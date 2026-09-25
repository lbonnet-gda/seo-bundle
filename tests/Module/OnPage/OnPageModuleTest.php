<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Module\OnPage;

use Lbonnet\SeoBundle\Audit\SeoAuditor;
use Lbonnet\SeoBundle\Crawl\SiteCrawler;
use Lbonnet\SeoBundle\Http\PageFetcher;
use Lbonnet\SeoBundle\Http\RedirectChainResolver;
use Lbonnet\SeoBundle\Model\Issue;
use Lbonnet\SeoBundle\Model\IssueType;
use Lbonnet\SeoBundle\Model\SeoReport;
use Lbonnet\SeoBundle\Model\Severity;
use Lbonnet\SeoBundle\Module\OnPage\OnPageModule;
use Lbonnet\SeoBundle\Module\OnPage\PageAuditor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class OnPageModuleTest extends TestCase
{
    public function testACleanPageHasNoIssue(): void
    {
        $report = $this->audit([
            'https://example.com/' => self::page('Home', 'What this page is about.', '<h1>Home</h1>'),
        ]);

        $this->assertFalse($report->hasIssues());
    }

    public function testFlagsWhatIsMissingOnAPage(): void
    {
        $report = $this->audit([
            'https://example.com/' => ['<!DOCTYPE html><html><body><img src="/logo.png"></body></html>', []],
        ]);

        $this->assertSame(
            [
                'https://example.com/' => [
                    IssueType::MissingTitle,
                    IssueType::MissingDescription,
                    IssueType::MissingH1,
                    IssueType::ImageMissingAlt,
                ],
            ],
            self::typesByUrl($report),
        );
        $this->assertSame(1, $report->getIssuesCount(Severity::Error));
    }

    public function testFlagsTitlesAndDescriptionsSharedBetweenPages(): void
    {
        $report = $this->audit([
            'https://example.com/' => self::page(
                'Same title',
                'Same description.',
                '<h1>Home</h1><a href="/a">A</a><a href="/b">B</a>',
            ),
            'https://example.com/a' => self::page('Same title', 'Same description.', '<h1>A</h1>'),
            'https://example.com/b' => self::page('Another title', 'Another description.', '<h1>B</h1>'),
        ]);

        $this->assertSame(
            [
                'https://example.com/' => [IssueType::DuplicateTitle, IssueType::DuplicateDescription],
                'https://example.com/a' => [IssueType::DuplicateTitle, IssueType::DuplicateDescription],
                'https://example.com/b' => [],
            ],
            self::typesByUrl($report),
        );
    }

    public function testHonorsTheConfiguredLengths(): void
    {
        $site = [
            'https://example.com/' => self::page('A title of thirty-one characters', 'Short.', '<h1>Home</h1>'),
        ];

        $this->assertSame([], self::typesByUrl($this->audit($site))['https://example.com/']);
        $this->assertSame(
            [IssueType::TitleTooLong],
            self::typesByUrl($this->audit($site, maxTitleLength: 20))['https://example.com/'],
        );
    }

    /**
     * @param array<string, array{string, array<string, mixed>}> $site URL => [body, info]
     */
    private function audit(array $site, int $maxTitleLength = 60): SeoReport
    {
        $httpClient = new MockHttpClient(static function (string $method, string $url) use ($site): MockResponse {
            [$body, $info] = $site[$url] ?? ['', ['http_code' => Response::HTTP_NOT_FOUND]];

            return new MockResponse($body, $info + ['response_headers' => ['content-type' => 'text/html']]);
        });
        $pageFetcher = new PageFetcher($httpClient);

        return (new SeoAuditor(
            crawler: new SiteCrawler($pageFetcher, new RedirectChainResolver($pageFetcher)),
            httpClient: $httpClient,
            modules: [new OnPageModule(new PageAuditor(maxTitleLength: $maxTitleLength))],
        ))->audit('https://example.com/');
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function page(string $title, string $description, string $body): array
    {
        return [
            sprintf(
                '<!DOCTYPE html><html lang="en"><head><title>%s</title>'
                .'<meta name="description" content="%s"></head><body>%s</body></html>',
                $title,
                $description,
                $body,
            ),
            [],
        ];
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
