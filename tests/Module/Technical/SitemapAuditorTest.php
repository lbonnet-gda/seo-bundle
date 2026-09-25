<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Module\Technical;

use Lbonnet\SeoBundle\Model\IssueType;
use Lbonnet\SeoBundle\Model\PageResponse;
use Lbonnet\SeoBundle\Model\PageSignals;
use Lbonnet\SeoBundle\Module\Technical\Http\SitemapFetcher;
use Lbonnet\SeoBundle\Module\Technical\Http\TargetProbeInterface;
use Lbonnet\SeoBundle\Module\Technical\Model\CrawlContext;
use Lbonnet\SeoBundle\Module\Technical\Model\PageAudit;
use Lbonnet\SeoBundle\Module\Technical\SitemapAuditor;
use Lbonnet\SeoBundle\Robots\RobotsTxt;
use Lbonnet\SeoBundle\Robots\RobotsTxtProviderInterface;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class SitemapAuditorTest extends TestCase
{
    private const ROBOTS_TXT = "User-agent: *\nDisallow: /private\nSitemap: https://example.com/sitemap.xml\n";

    /** @var list<string> */
    private array $requestedUrls = [];

    public function testChecksEveryUrlTheSitemapLists(): void
    {
        $probe = $this->createMock(TargetProbeInterface::class);
        $probe->method('probe')->willReturnCallback(static fn(string $url): ?PageResponse => match ($url) {
            'https://example.com/gone' => new PageResponse($url, Response::HTTP_NOT_FOUND),
            'https://example.com/old' => new PageResponse($url, Response::HTTP_MOVED_PERMANENTLY),
            'https://example.com/hidden' => new PageResponse(
                $url,
                Response::HTTP_OK,
                ['x-robots-tag' => ['noindex']],
            ),
            'https://example.com/unreachable' => new PageResponse($url, 0),
            'https://example.com/unknown' => null,
            default => throw new LogicException(sprintf('"%s" should not be probed.', $url)),
        });

        $auditor = $this->auditor(
            [
                'https://example.com/sitemap.xml' => self::urlset(
                    'https://example.com/',
                    'https://example.com/gone',
                    'https://example.com/old',
                    'https://example.com/hidden',
                    'https://example.com/unreachable',
                    'https://example.com/unknown',
                    'https://example.com/private/page',
                    'https://example.com/filtered',
                    'https://other.com/page',
                    '/relative',
                ),
            ],
            probe: $probe,
        );

        $audited = $auditor->audit(
            [
                self::page('https://example.com/'),
                self::page('https://example.com/filtered', canonical: 'https://example.com/', depth: 1),
            ],
            self::crawled('https://example.com/', 'https://example.com/filtered'),
        );

        $this->assertSame(
            [
                'https://example.com/sitemap.xml' => [
                    IssueType::SitemapUrlInvalid,
                    IssueType::SitemapUrlInvalid,
                    IssueType::SitemapUrlNotOk,
                    IssueType::SitemapUrlRedirects,
                    IssueType::SitemapUrlNoindex,
                    IssueType::SitemapUrlNotOk,
                    IssueType::SitemapUrlBlockedByRobotsTxt,
                    IssueType::SitemapUrlNotCanonical,
                ],
            ],
            self::typesByUrl($audited),
        );
    }

    public function testFlagsANoindexPageTheCrawlFound(): void
    {
        $auditor = $this->auditor(['https://example.com/sitemap.xml' => self::urlset('https://example.com/draft')]);

        $audited = $auditor->audit(
            [self::page('https://example.com/draft', metaRobots: ['noindex'])],
            self::crawled('https://example.com/draft'),
        );

        $this->assertSame(
            ['https://example.com/sitemap.xml' => [IssueType::SitemapUrlNoindex]],
            self::typesByUrl($audited),
        );
    }

    public function testFlagsIndexablePagesMissingFromTheSitemap(): void
    {
        $auditor = $this->auditor(['https://example.com/sitemap.xml' => self::urlset('https://example.com/')]);

        $audited = $auditor->audit(
            [
                self::page('https://example.com/'),
                self::page('https://example.com/missing', depth: 1),
                self::page('http://example.com/missing', depth: 1),
                self::page('https://example.com/variant', canonical: 'https://example.com/', depth: 1),
                self::page('https://example.com/draft', metaRobots: ['noindex'], depth: 1),
                self::page('https://example.com/private/page', depth: 1),
            ],
            self::crawled('https://example.com/'),
        );

        $this->assertSame(
            [
                'https://example.com/missing' => [IssueType::PageMissingFromSitemap],
                'http://example.com/missing' => [IssueType::PageMissingFromSitemap],
            ],
            self::typesByUrl($audited),
        );
    }

    public function testFallsBackToTheRootSitemapAndReportsItMissing(): void
    {
        $auditor = $this->auditor([], robotsTxt: "User-agent: *\nDisallow:\n");

        $audited = $auditor->audit([self::page('https://example.com/')], new CrawlContext());

        $this->assertSame(['https://example.com/sitemap.xml'], $this->requestedUrls);
        $this->assertSame(
            ['https://example.com/sitemap.xml' => [IssueType::SitemapMissing]],
            self::typesByUrl($audited),
        );
        $this->assertSame(Response::HTTP_NOT_FOUND, $audited[0]->statusCode);
    }

    public function testReadsTheRootSitemapWhenRobotsTxtDeclaresNone(): void
    {
        $auditor = $this->auditor(
            ['https://example.com/sitemap.xml' => self::urlset('https://example.com/')],
            robotsTxt: null,
        );

        $this->assertSame([], $auditor->audit([self::page('https://example.com/')], new CrawlContext()));
    }

    public function testReportsADeclaredSitemapThatCannotBeRead(): void
    {
        $auditor = $this->auditor(
            [
                'https://example.com/sitemap.xml' => ['', ['http_code' => Response::HTTP_INTERNAL_SERVER_ERROR]],
                'https://example.com/news.xml' => ['', ['error' => 'Connection timed out']],
                'https://example.com/html.xml' => ['<!DOCTYPE html><html></html>', []],
            ],
            robotsTxt: "Sitemap: https://example.com/sitemap.xml\nSitemap: https://example.com/news.xml\n"
            ."Sitemap: https://example.com/html.xml\nSitemap: /relative.xml\n",
        );

        $audited = $auditor->audit(
            [self::page('https://example.com/'), self::page('https://example.com/missing', depth: 1)],
            new CrawlContext(),
        );

        $this->assertSame(
            [
                'https://example.com/robots.txt' => [IssueType::SitemapUrlInvalid],
                'https://example.com/sitemap.xml' => [IssueType::SitemapNotOk],
                'https://example.com/news.xml' => [IssueType::SitemapNotOk],
                'https://example.com/html.xml' => [IssueType::SitemapInvalid],
            ],
            self::typesByUrl($audited),
        );
    }

    public function testFollowsASitemapIndexOneLevelDeep(): void
    {
        $auditor = $this->auditor([
            'https://example.com/sitemap.xml' => self::sitemapIndex(
                'https://example.com/sitemaps/pages.xml',
                'https://example.com/nested-index.xml',
                'https://cdn.example.com/elsewhere.xml',
            ),
            'https://example.com/sitemaps/pages.xml' => self::urlset('https://example.com/'),
            'https://example.com/nested-index.xml' => self::sitemapIndex('https://example.com/more.xml'),
        ]);

        $audited = $auditor->audit([self::page('https://example.com/')], self::crawled('https://example.com/'));

        $this->assertSame(
            [
                'https://example.com/sitemap.xml' => [IssueType::SitemapUrlInvalid],
                'https://example.com/nested-index.xml' => [IssueType::SitemapInvalid],
            ],
            self::typesByUrl($audited),
        );
        $this->assertNotContains('https://example.com/more.xml', $this->requestedUrls);
        $this->assertNotContains('https://cdn.example.com/elsewhere.xml', $this->requestedUrls);
    }

    public function testStopsAtTheFileLimitWithoutReportingMissingPages(): void
    {
        $auditor = $this->auditor(
            [
                'https://example.com/sitemap.xml' => self::sitemapIndex(
                    'https://example.com/a.xml',
                    'https://example.com/b.xml',
                ),
                'https://example.com/a.xml' => self::urlset('https://example.com/'),
            ],
            maxFiles: 2,
        );

        $audited = $auditor->audit(
            [self::page('https://example.com/'), self::page('https://example.com/in-b', depth: 1)],
            self::crawled('https://example.com/'),
        );

        $this->assertSame([], $audited);
        $this->assertNotContains('https://example.com/b.xml', $this->requestedUrls);
    }

    public function testSkipsEverythingWhenRobotsTxtAnswersAServerError(): void
    {
        $provider = $this->createMock(RobotsTxtProviderInterface::class);
        $provider->method('robotsTxt')->willReturn(
            RobotsTxt::serverError('https://example.com/robots.txt', Response::HTTP_SERVICE_UNAVAILABLE),
        );

        $auditor = new SitemapAuditor(
            new SitemapFetcher($this->httpClient([])),
            $this->createMock(TargetProbeInterface::class),
            $provider,
        );

        $this->assertSame([], $auditor->audit([self::page('https://example.com/')], new CrawlContext()));
        $this->assertSame([], $this->requestedUrls);
    }

    public function testDisabledChecksSendNoRequest(): void
    {
        $probe = $this->createMock(TargetProbeInterface::class);
        $probe->expects($this->never())->method('probe');

        $responseChecks = [
            IssueType::SitemapUrlNotOk->value,
            IssueType::SitemapUrlRedirects->value,
            IssueType::SitemapUrlNoindex->value,
            IssueType::SitemapUrlNotCanonical->value,
        ];

        $this->auditor(
            ['https://example.com/sitemap.xml' => self::urlset('https://example.com/elsewhere')],
            probe: $probe,
            disabledChecks: $responseChecks,
        )->audit([self::page('https://example.com/')], new CrawlContext());

        $this->requestedUrls = [];
        $this->auditor(
            [],
            disabledChecks: array_map(static fn(IssueType $type): string => $type->value, [
                IssueType::SitemapMissing,
                IssueType::SitemapNotOk,
                IssueType::SitemapInvalid,
                IssueType::SitemapUrlInvalid,
                IssueType::SitemapUrlNotOk,
                IssueType::SitemapUrlRedirects,
                IssueType::SitemapUrlNoindex,
                IssueType::SitemapUrlNotCanonical,
                IssueType::SitemapUrlBlockedByRobotsTxt,
                IssueType::PageMissingFromSitemap,
            ]),
        )->audit([self::page('https://example.com/')], new CrawlContext());

        $this->assertSame([], $this->requestedUrls);
    }

    /**
     * @param array<string, array{string, array<string, mixed>}> $responses URL => [body, info]
     * @param list<string> $disabledChecks
     */
    private function auditor(
        array $responses,
        ?string $robotsTxt = self::ROBOTS_TXT,
        ?TargetProbeInterface $probe = null,
        int $maxFiles = 10,
        array $disabledChecks = [],
    ): SitemapAuditor {
        $provider = $this->createMock(RobotsTxtProviderInterface::class);
        $provider->method('robotsTxt')->willReturn(
            $robotsTxt !== null
                ? RobotsTxt::parse('https://example.com/robots.txt', $robotsTxt, Response::HTTP_OK)
                : RobotsTxt::notFound('https://example.com/robots.txt', Response::HTTP_NOT_FOUND),
        );

        return new SitemapAuditor(
            new SitemapFetcher($this->httpClient($responses)),
            $probe ?? $this->createMock(TargetProbeInterface::class),
            $provider,
            $maxFiles,
            $disabledChecks,
        );
    }

    /**
     * @param array<string, array{string, array<string, mixed>}> $responses
     */
    private function httpClient(array $responses): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url) use ($responses): MockResponse {
            $this->requestedUrls[] = $url;

            if (!isset($responses[$url])) {
                return new MockResponse('', ['http_code' => Response::HTTP_NOT_FOUND]);
            }

            [$body, $info] = $responses[$url];

            return new MockResponse($body, $info);
        });
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function urlset(string ...$urls): array
    {
        $entries = array_map(static fn(string $url): string => sprintf('<url><loc>%s</loc></url>', $url), $urls);

        return [
            '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .implode('', $entries).'</urlset>',
            [],
        ];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function sitemapIndex(string ...$urls): array
    {
        $entries = array_map(
            static fn(string $url): string => sprintf('<sitemap><loc>%s</loc></sitemap>', $url),
            $urls,
        );

        return [
            '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .implode('', $entries).'</sitemapindex>',
            [],
        ];
    }

    /**
     * @param list<string> $metaRobots
     */
    private static function page(
        string $url,
        ?string $canonical = null,
        array $metaRobots = [],
        int $depth = 0,
    ): PageAudit {
        return new PageAudit(
            url: $url,
            statusCode: Response::HTTP_OK,
            signals: new PageSignals(
                canonicalHrefs: $canonical !== null ? [$canonical] : [],
                metaRobots: $metaRobots,
            ),
            depth: $depth,
        );
    }

    private static function crawled(string ...$urls): CrawlContext
    {
        $responses = [];

        foreach ($urls as $url) {
            $responses[$url] = new PageResponse($url, Response::HTTP_OK);
        }

        return new CrawlContext($responses);
    }

    /**
     * @param list<PageAudit> $audits
     *
     * @return array<string, list<IssueType>>
     */
    private static function typesByUrl(array $audits): array
    {
        $types = [];

        foreach ($audits as $audit) {
            foreach ($audit->issues as $issue) {
                $types[$audit->url][] = $issue->type;
            }
        }

        return $types;
    }
}
