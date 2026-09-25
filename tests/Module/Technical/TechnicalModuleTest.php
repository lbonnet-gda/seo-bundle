<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Module\Technical;

use Lbonnet\SeoBundle\Audit\SeoAuditor;
use Lbonnet\SeoBundle\Crawl\SiteCrawler;
use Lbonnet\SeoBundle\Http\PageFetcher;
use Lbonnet\SeoBundle\Http\RedirectChainResolver;
use Lbonnet\SeoBundle\Model\Issue;
use Lbonnet\SeoBundle\Model\IssueType;
use Lbonnet\SeoBundle\Model\SeoReport;
use Lbonnet\SeoBundle\Module\Technical\Http\TargetProbeInterface;
use Lbonnet\SeoBundle\Module\Technical\PageAuditor;
use Lbonnet\SeoBundle\Module\Technical\SiteAuditor;
use Lbonnet\SeoBundle\Module\Technical\TechnicalModule;
use Lbonnet\SeoBundle\Robots\RobotsTxtChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class TechnicalModuleTest extends TestCase
{
    public function testACleanSiteHasNoIssue(): void
    {
        $report = $this->audit([
            'https://example.com/' => self::html(
                'https://example.com/',
                '<a href="/page-1">Page 1</a><a href="https://other.example.org/">Elsewhere</a>',
            ),
            'https://example.com/page-1' => self::html('https://example.com/page-1'),
        ]);

        $this->assertSame(2, $report->pagesRead);
        $this->assertFalse($report->hasIssues());
    }

    public function testASingleRedirectOnTheStartUrlIsNotAFinding(): void
    {
        $report = $this->audit([
            'https://example.com/' => self::redirect('https://example.com/home'),
            'https://example.com/home' => self::html('https://example.com/home'),
        ]);

        $this->assertSame('https://example.com/home', $report->pages[0]->url);
        $this->assertFalse($report->hasIssues());
    }

    public function testFlagsAnInternalLinkPointingToARedirect(): void
    {
        $report = $this->audit([
            'https://example.com/' => self::html('https://example.com/', '<a href="/old">Old</a>'),
            'https://example.com/old' => self::redirect('https://example.com/new'),
            'https://example.com/new' => self::html('https://example.com/new'),
        ]);

        $this->assertSame(
            [
                'https://example.com/' => [IssueType::InternalLinkToRedirect],
                'https://example.com/new' => [],
            ],
            self::typesByUrl($report),
        );
    }

    public function testFlagsACanonicalPointingToAPageThatRedirects(): void
    {
        $report = $this->audit([
            'https://example.com/' => self::html('https://example.com/old', '<a href="/old">Old</a>'),
            'https://example.com/old' => self::redirect('https://example.com/new'),
            'https://example.com/new' => self::html('https://example.com/new'),
        ]);

        $this->assertContains(IssueType::CanonicalTargetRedirects, self::typesByUrl($report)['https://example.com/']);
    }

    public function testFlagsANoindexPageReachedThroughALink(): void
    {
        $report = $this->audit([
            'https://example.com/' => self::html('https://example.com/', '<a href="/hidden">Hidden</a>'),
            'https://example.com/hidden' => self::html(
                'https://example.com/hidden',
                '',
                '<meta name="robots" content="noindex">',
            ),
        ]);

        $this->assertSame([IssueType::NoindexOnLinkedPage], self::typesByUrl($report)['https://example.com/hidden']);
    }

    public function testReportsABrokenTemporaryRedirectOnceOnTheRedirectItself(): void
    {
        $report = $this->audit([
            'https://example.com/' => self::html('https://example.com/', '<a href="/old">Old</a>'),
            'https://example.com/old' => [
                '',
                ['http_code' => Response::HTTP_FOUND, 'response_headers' => ['location' => 'https://example.com/gone']],
            ],
        ]);

        $this->assertSame(
            [
                'https://example.com/' => [IssueType::InternalLinkToRedirect],
                'https://example.com/old' => [IssueType::TemporaryRedirect, IssueType::RedirectToError],
            ],
            self::typesByUrl($report),
        );
        $this->assertNull($report->pages[1]->depth);
    }

    public function testFlagsAnHreflangAlternateThatDoesNotLinkBack(): void
    {
        $report = $this->audit(
            [
                'https://example.com/fr' => self::html(
                    'https://example.com/fr',
                    '<a href="/en">English</a>',
                    '<link rel="alternate" hreflang="fr" href="https://example.com/fr">'
                    .'<link rel="alternate" hreflang="en" href="https://example.com/en">'
                    .'<link rel="alternate" hreflang="x-default" href="https://example.com/fr">',
                ),
                'https://example.com/en' => self::html(
                    'https://example.com/en',
                    '',
                    '<link rel="alternate" hreflang="en" href="https://example.com/en">'
                    .'<link rel="alternate" hreflang="x-default" href="https://example.com/en">',
                ),
            ],
            'https://example.com/fr',
        );

        $this->assertSame(
            [
                'https://example.com/fr' => [IssueType::HreflangNotReciprocal],
                'https://example.com/en' => [],
            ],
            self::typesByUrl($report),
        );
    }

    public function testAFilteredVariantOfAMultilingualPageRaisesNoHreflangIssue(): void
    {
        $cluster = '<link rel="alternate" hreflang="en" href="https://example.com/en/presse">'
            .'<link rel="alternate" hreflang="fr" href="https://example.com/fr/presse">'
            .'<link rel="alternate" hreflang="x-default" href="https://example.com/en/presse">';

        $report = $this->audit(
            [
                'https://example.com/en/presse' => self::html(
                    'https://example.com/en/presse',
                    '<a href="/en/presse?categoryId=5">Category</a><a href="/fr/presse">Français</a>',
                    $cluster,
                ),
                'https://example.com/en/presse?categoryId=5' => self::html(
                    'https://example.com/en/presse',
                    '',
                    $cluster,
                ),
                'https://example.com/fr/presse' => self::html('https://example.com/fr/presse', '', $cluster),
            ],
            'https://example.com/en/presse',
        );

        $this->assertSame(3, $report->pagesRead);
        $this->assertFalse($report->hasIssues());
    }

    public function testARobotsTxtServerErrorStopsTheCrawlAndIsReportedOnItsOwnEntry(): void
    {
        $report = $this->audit(
            [
                'https://example.com/' => self::html('https://example.com/', '<a href="/page">Page</a>'),
                'https://example.com/page' => self::html('https://example.com/page'),
                'https://example.com/robots.txt' => ['', ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE]],
            ],
            withRobotsTxt: true,
        );

        $this->assertTrue($report->blockedByRobotsTxt);
        $this->assertSame(
            [
                'https://example.com/' => [],
                'https://example.com/robots.txt' => [IssueType::RobotsTxtServerError],
            ],
            self::typesByUrl($report),
        );
    }

    /**
     * @param array<string, array{string, array<string, mixed>}> $site URL => [body, info]
     */
    private function audit(
        array $site,
        string $startUrl = 'https://example.com/',
        bool $withRobotsTxt = false,
    ): SeoReport {
        $httpClient = new MockHttpClient(static function (string $method, string $url) use ($site): MockResponse {
            [$body, $info] = $site[$url] ?? ['', ['http_code' => Response::HTTP_NOT_FOUND]];

            return new MockResponse($body, $info);
        });
        $pageFetcher = new PageFetcher($httpClient);
        $robotsTxtChecker = $withRobotsTxt ? new RobotsTxtChecker($httpClient, 'TestBot/1.0') : null;

        $auditor = new SeoAuditor(
            crawler: new SiteCrawler(
                $pageFetcher,
                new RedirectChainResolver($pageFetcher),
                robotsTxtChecker: $robotsTxtChecker,
            ),
            httpClient: $httpClient,
            modules: [
                new TechnicalModule(
                    new PageAuditor(),
                    new SiteAuditor(
                        $this->createMock(TargetProbeInterface::class),
                        robotsTxtProvider: $robotsTxtChecker,
                    ),
                ),
            ],
            robotsTxtChecker: $robotsTxtChecker,
        );

        return $auditor->audit($startUrl);
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function html(string $canonical, string $body = '', string $extraHead = ''): array
    {
        $html = sprintf(
            '<!DOCTYPE html><html lang="fr"><head><title>T</title>'
            .'<link rel="canonical" href="%s">%s</head><body>%s</body></html>',
            $canonical,
            $extraHead,
            $body,
        );

        return [$html, ['response_headers' => ['content-type' => 'text/html; charset=UTF-8']]];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function redirect(string $location): array
    {
        return ['', ['http_code' => Response::HTTP_MOVED_PERMANENTLY, 'response_headers' => ['location' => $location]]];
    }

    /**
     * @return array<string, list<IssueType>>
     */
    private static function typesByUrl(SeoReport $report): array
    {
        $types = [];

        foreach ($report->pages as $page) {
            $types[$page->url] = array_map(static fn(Issue $issue): IssueType => $issue->type, $page->issues);
        }

        return $types;
    }
}
