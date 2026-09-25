<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Module\Technical;

use Lbonnet\SeoBundle\Html\HtmlPageParser;
use Lbonnet\SeoBundle\Http\PageFetcher;
use Lbonnet\SeoBundle\Model\IssueType;
use Lbonnet\SeoBundle\Model\PageResponse;
use Lbonnet\SeoBundle\Model\PageSignals;
use Lbonnet\SeoBundle\Module\Technical\Model\CrawlContext;
use Lbonnet\SeoBundle\Module\Technical\Model\PageAudit;
use Lbonnet\SeoBundle\Module\Technical\UrlVariantAuditor;
use Lbonnet\SeoBundle\Robots\RobotsTxtCheckerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class UrlVariantAuditorTest extends TestCase
{
    /** @var list<string> */
    private array $requestedUrls = [];

    public function testFlagsTheHttpAndWwwVersionsOfASiteAnsweringLikeIt(): void
    {
        $auditor = $this->auditor([
            'http://example.com/' => self::html(),
            'https://www.example.com/' => self::html('https://www.example.com/'),
        ]);

        $audited = $auditor->audit([self::page('https://example.com/')], new CrawlContext());

        $this->assertSame(
            [
                'http://example.com/' => [IssueType::HttpNotRedirectedToHttps],
                'https://www.example.com/' => [IssueType::HostVariantNotRedirected],
            ],
            self::typesByUrl($audited),
        );
    }

    public function testRedirectingOrCanonicalizedVariantsAreFine(): void
    {
        $auditor = $this->auditor([
            'http://example.com/' => self::redirect('https://example.com/', Response::HTTP_MOVED_PERMANENTLY),
            'https://www.example.com/' => self::html('https://example.com/'),
            'https://example.com/index.php' => self::redirect(
                'https://example.com/',
                Response::HTTP_PERMANENTLY_REDIRECT,
            ),
            'https://example.com/index.html' => self::html('/'),
        ]);

        $this->assertSame([], $auditor->audit([self::page('https://example.com/')], new CrawlContext()));
    }

    public function testAVariantMustDeclareTheCanonicalOfTheHomePage(): void
    {
        $auditor = $this->auditor([
            'https://example.com/index.php' => self::html('https://example.com/'),
        ]);

        $audited = $auditor->audit(
            [self::page('https://example.com/', canonical: 'https://example.com/fr/')],
            new CrawlContext(),
        );

        $this->assertSame(
            ['https://example.com/index.php' => [IssueType::IndexFileDuplicate]],
            self::typesByUrl($audited),
        );
    }

    public function testFlagsATemporaryRedirectOnAVariant(): void
    {
        $auditor = $this->auditor([
            'http://example.com/' => self::redirect('https://example.com/', Response::HTTP_FOUND),
        ]);

        $audited = $auditor->audit([self::page('https://example.com/')], new CrawlContext());

        $this->assertSame(['http://example.com/' => [IssueType::TemporaryRedirect]], self::typesByUrl($audited));
    }

    public function testAnUnreachableVariantIsNotReported(): void
    {
        $auditor = $this->auditor([
            'https://www.example.com/' => ['', ['error' => 'Could not resolve host']],
        ]);

        $this->assertSame([], $auditor->audit([self::page('https://example.com/')], new CrawlContext()));
    }

    public function testPicksTheOtherHostOnlyWhenItIsKnown(): void
    {
        $cases = [
            'https://www.example.com/' => 'https://example.com/',
            'https://shop.example.com/' => null,
            'https://example.co.uk/' => null,
            'https://127.0.0.1/' => null,
        ];

        foreach ($cases as $startUrl => $alternateUrl) {
            $this->requestedUrls = [];
            $this->auditor([])->audit([self::page($startUrl)], new CrawlContext());

            $otherHosts = array_values(
                array_filter(
                    $this->requestedUrls,
                    static fn(string $url): bool => parse_url($url, PHP_URL_HOST) !== parse_url(
                            $startUrl,
                            PHP_URL_HOST
                        ),
                )
            );

            $this->assertSame($alternateUrl !== null ? [$alternateUrl] : [], $otherHosts, $startUrl);
        }
    }

    public function testDoesNotRequestHttpForASiteServedOverHttpOrOnACustomPort(): void
    {
        foreach (['http://example.com/', 'https://example.com:8443/'] as $startUrl) {
            $this->requestedUrls = [];
            $this->auditor([])->audit([self::page($startUrl)], new CrawlContext());

            $this->assertNotContains('http://example.com/', $this->requestedUrls, $startUrl);
            $this->assertNotContains('http://example.com:8443/', $this->requestedUrls, $startUrl);
        }
    }

    public function testFlagsTrailingSlashAndCaseDuplicates(): void
    {
        $auditor = $this->auditor([
            'https://example.com/products/' => self::html(),
            'https://example.com/PRODUCTS' => self::html('https://example.com/products'),
            'https://example.com/about' => self::html('https://example.com/about'),
            'https://example.com/ABOUT/' => self::html(),
        ]);

        $audited = $auditor->audit(
            [
                self::page('https://example.com/'),
                self::page('https://example.com/products', depth: 1),
                self::page('https://example.com/about/', depth: 1),
            ],
            new CrawlContext(),
        );

        $this->assertSame(
            [
                'https://example.com/products/' => [IssueType::TrailingSlashDuplicate],
                'https://example.com/about' => [IssueType::TrailingSlashDuplicate],
                'https://example.com/ABOUT/' => [IssueType::CaseDuplicate],
            ],
            self::typesByUrl($audited),
        );
    }

    public function testLowerCasesAPathHoldingCapitalsAndKeepsItsPercentEncoding(): void
    {
        $this->auditor([])->audit(
            [self::page('https://example.com/'), self::page('https://example.com/Caf%C3%A9', depth: 1)],
            new CrawlContext(),
        );

        $this->assertContains('https://example.com/caf%C3%A9', $this->requestedUrls);
    }

    public function testChecksOnlyASampleOfTheShallowestPages(): void
    {
        $this->auditor([], sampleSize: 1)->audit(
            [
                self::page('https://example.com/'),
                self::page('https://example.com/deep', depth: 2),
                self::page('https://example.com/near', depth: 1),
            ],
            new CrawlContext(),
        );

        $this->assertContains('https://example.com/near/', $this->requestedUrls);
        $this->assertNotContains('https://example.com/deep/', $this->requestedUrls);
    }

    public function testSkipsPagesThatCannotBeComparedAndHonorsASampleSizeOfZero(): void
    {
        $pages = [
            self::page('https://example.com/'),
            self::page('https://example.com/search?q=shoes', depth: 1),
            self::page('https://example.com/shoes?color=red', canonical: 'https://example.com/shoes', depth: 1),
            self::page('https://example.com/filtered', canonical: 'https://example.com/shoes', depth: 1),
        ];

        $this->auditor([])->audit($pages, new CrawlContext());
        $homePageRequests = $this->requestedUrls;

        $this->requestedUrls = [];
        $this->auditor([], sampleSize: 0)->audit(
            [...$pages, self::page('https://example.com/shoes', depth: 1)],
            new CrawlContext(),
        );

        $expected = [
            'http://example.com/',
            'https://www.example.com/',
            'https://example.com/index.php',
            'https://example.com/index.html',
        ];

        $this->assertSame($expected, $homePageRequests);
        $this->assertSame($expected, $this->requestedUrls);
    }

    public function testDoesNotRequestAVariantDisallowedByRobotsTxt(): void
    {
        $robotsTxtChecker = $this->createMock(RobotsTxtCheckerInterface::class);
        $robotsTxtChecker->method('isAllowed')->willReturnCallback(
            static fn(string $url): bool => !str_ends_with($url, '/index.php'),
        );

        $this->auditor([], robotsTxtChecker: $robotsTxtChecker)->audit(
            [self::page('https://example.com/')],
            new CrawlContext(),
        );

        $this->assertNotContains('https://example.com/index.php', $this->requestedUrls);
        $this->assertContains('https://example.com/index.html', $this->requestedUrls);
    }

    public function testADisabledCheckSendsNoRequest(): void
    {
        $pages = [self::page('https://example.com/'), self::page('https://example.com/products', depth: 1)];

        $this->auditor([], disabledChecks: [IssueType::TrailingSlashDuplicate->value])
            ->audit($pages, new CrawlContext());

        $this->assertNotContains('https://example.com/products/', $this->requestedUrls);
        $this->assertContains('https://example.com/PRODUCTS', $this->requestedUrls);

        $this->requestedUrls = [];
        $this->auditor(
            [],
            disabledChecks: array_map(
                static fn(IssueType $type): string => $type->value,
                [
                    IssueType::HttpNotRedirectedToHttps,
                    IssueType::HostVariantNotRedirected,
                    IssueType::IndexFileDuplicate,
                    IssueType::TrailingSlashDuplicate,
                    IssueType::CaseDuplicate,
                ],
            ),
        )->audit($pages, new CrawlContext());

        $this->assertSame([], $this->requestedUrls);
    }

    public function testReusesTheResponseOfAVariantTheCrawlAlreadyFetched(): void
    {
        [$html] = self::html();

        $context = new CrawlContext([
            'https://example.com/products/' => new PageResponse(
                url: 'https://example.com/products/',
                statusCode: Response::HTTP_OK,
                html: $html,
            ),
            'https://example.com/PRODUCTS' => new PageResponse(
                url: 'https://example.com/PRODUCTS',
                statusCode: Response::HTTP_FOUND,
            ),
        ]);

        $audited = $this->auditor([])->audit(
            [self::page('https://example.com/'), self::page('https://example.com/products', depth: 1)],
            $context,
        );

        $this->assertSame(
            ['https://example.com/products/' => [IssueType::TrailingSlashDuplicate]],
            self::typesByUrl($audited),
        );
        $this->assertNotContains('https://example.com/products/', $this->requestedUrls);
        $this->assertNotContains('https://example.com/PRODUCTS', $this->requestedUrls);
    }

    /**
     * @param array<string, array{string, array<string, mixed>}> $responses URL => [body, info]
     * @param list<string> $disabledChecks
     */
    private function auditor(
        array $responses,
        int $sampleSize = 10,
        ?RobotsTxtCheckerInterface $robotsTxtChecker = null,
        array $disabledChecks = [],
    ): UrlVariantAuditor {
        $httpClient = new MockHttpClient(function (string $method, string $url) use ($responses): MockResponse {
            $this->requestedUrls[] = $url;

            if (!isset($responses[$url])) {
                return new MockResponse('', ['http_code' => Response::HTTP_NOT_FOUND]);
            }

            [$body, $info] = $responses[$url];

            return new MockResponse($body, $info);
        });

        return new UrlVariantAuditor(
            new PageFetcher($httpClient),
            new HtmlPageParser(),
            $sampleSize,
            $disabledChecks,
            $robotsTxtChecker,
        );
    }

    private static function page(string $url, ?string $canonical = null, int $depth = 0): PageAudit
    {
        return new PageAudit(
            url: $url,
            statusCode: Response::HTTP_OK,
            signals: new PageSignals(canonicalHrefs: $canonical !== null ? [$canonical] : []),
            depth: $depth,
        );
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function html(?string $canonical = null): array
    {
        $html = sprintf(
            '<!DOCTYPE html><html lang="fr"><head><title>T</title>%s</head><body></body></html>',
            $canonical !== null ? sprintf('<link rel="canonical" href="%s">', $canonical) : '',
        );

        return [$html, ['response_headers' => ['content-type' => 'text/html; charset=UTF-8']]];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function redirect(string $location, int $statusCode): array
    {
        return ['', ['http_code' => $statusCode, 'response_headers' => ['location' => $location]]];
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
