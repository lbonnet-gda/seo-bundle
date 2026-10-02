<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Crawl;

use Lbonnet\SeoBundle\Crawl\CrawledPage;
use Lbonnet\SeoBundle\Crawl\CrawlOptions;
use Lbonnet\SeoBundle\Crawl\CrawlResult;
use Lbonnet\SeoBundle\Crawl\SiteCrawler;
use Lbonnet\SeoBundle\Http\HostRateLimiter;
use Lbonnet\SeoBundle\Http\PageFetcher;
use Lbonnet\SeoBundle\Http\RedirectChainResolver;
use Lbonnet\SeoBundle\Http\SiteThrottleExemption;
use Lbonnet\SeoBundle\Http\ThrottleExemptionInterface;
use Lbonnet\SeoBundle\Robots\RobotsTxtChecker;
use Lbonnet\SeoBundle\Robots\RobotsTxtCheckerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class SiteCrawlerTest extends TestCase
{
    /** @var list<string> */
    private array $requestedUrls = [];

    public function testReadsInternalPagesAndOnlyRecordsExternalLinks(): void
    {
        $crawl = $this->crawl([
            'https://example.com/' => self::page('<a href="/about">About</a><a href="https://other.com/">Other</a>'),
            'https://example.com/about' => self::page('<a href="/">Home</a>'),
        ]);

        $this->assertSame(['https://example.com/', 'https://example.com/about'], self::urls($crawl));
        $this->assertCount(1, array_keys($this->requestedUrls, 'https://example.com/about', true));
        $this->assertNotContains('https://other.com/', $this->requestedUrls);
        $this->assertSame(['https://other.com/'], array_keys($crawl->externalLinks()));
        $this->assertSame(['https://example.com/'], $crawl->referrersOf('https://other.com/'));
        $this->assertSame(2, $crawl->urlsChecked);
        $this->assertSame(1, $crawl->pageFor('https://example.com/about')?->depth);
    }

    public function testFollowsARedirectHopByHopAndReadsItsTarget(): void
    {
        $crawl = $this->crawl([
            'https://example.com/' => self::page('<a href="/old">Old</a>'),
            'https://example.com/old' => self::redirect('https://example.com/new'),
            'https://example.com/new' => self::page(),
        ]);

        $this->assertSame(['https://example.com/', 'https://example.com/new'], self::urls($crawl));
        $chain = $crawl->redirectChains()['https://example.com/old'] ?? null;
        $this->assertNotNull($chain);
        $this->assertSame('https://example.com/new', $chain->finalUrl);
        $this->assertSame(Response::HTTP_OK, $chain->finalStatusCode);
        $this->assertSame(['https://example.com/'], $crawl->referrersOf('https://example.com/old'));
    }

    public function testReadsTheSitePastAnHttpToHttpsRedirect(): void
    {
        $this->requestedUrls = [];
        $site = [
            'http://example.com/' => self::redirect('https://example.com/'),
            'https://example.com/' => self::page('<a href="/about">About</a>'),
            'https://example.com/about' => self::page(),
        ];

        $crawl = $this->crawler($this->httpClient($site))->crawl('http://example.com/', new CrawlOptions());

        // The https URL shares its dedup key with the http one, but it is the page we came for.
        $this->assertSame(['https://example.com/', 'https://example.com/about'], self::urls($crawl));
        $this->assertFalse($crawl->redirectChains()['https://example.com/']->isLoop);
    }

    public function testDoesNotReadNonHtmlResponsesButKeepsTheirStatus(): void
    {
        $crawl = $this->crawl([
            'https://example.com/' => self::page('<a href="/brochure.pdf">PDF</a><a href="/missing">Missing</a>'),
            'https://example.com/brochure.pdf' => [
                '%PDF-1.4',
                ['response_headers' => ['content-type' => 'application/pdf']],
            ],
        ]);

        $this->assertSame(['https://example.com/'], self::urls($crawl));
        $this->assertSame(Response::HTTP_OK, $crawl->responseFor('https://example.com/brochure.pdf')?->statusCode);
        $this->assertSame(Response::HTTP_NOT_FOUND, $crawl->responseFor('https://example.com/missing')?->statusCode);
    }

    public function testRecordsAnInternalUrlThatCannotBeReached(): void
    {
        $crawl = $this->crawl([
            'https://example.com/' => self::page('<a href="/timeout">Slow</a>'),
            'https://example.com/timeout' => ['', ['error' => 'Operation timed out']],
        ]);

        $this->assertTrue($crawl->isUnreachable('https://example.com/timeout'));
        $this->assertNull($crawl->responseFor('https://example.com/timeout'));
    }

    public function testStopsReadingAtTheMaxDepth(): void
    {
        $site = [
            'https://example.com/' => self::page('<a href="/one">1</a>'),
            'https://example.com/one' => self::page('<a href="/two">2</a>'),
            'https://example.com/two' => self::page('<a href="/three">3</a>'),
        ];

        $crawl = $this->crawl($site, new CrawlOptions(maxDepth: 1));

        $this->assertSame(['https://example.com/', 'https://example.com/one'], self::urls($crawl));
        $this->assertNotContains('https://example.com/two', $this->requestedUrls);
    }

    public function testChecksTheLinkTargetsOfTheLastPagesReadWithoutReadingThem(): void
    {
        $site = [
            'https://example.com/' => self::page('<a href="/one">1</a>'),
            'https://example.com/one' => self::page('<a href="/two">2</a><a href="/gone">Gone</a>'),
            'https://example.com/two' => self::page('<a href="/three">3</a>'),
        ];

        $crawl = $this->crawl($site, new CrawlOptions(maxDepth: 1, checkLinkTargets: true));

        $this->assertSame(['https://example.com/', 'https://example.com/one'], self::urls($crawl));
        $this->assertSame(Response::HTTP_OK, $crawl->responseFor('https://example.com/two')?->statusCode);
        $this->assertNull($crawl->responseFor('https://example.com/two')->html);
        $this->assertSame(Response::HTTP_NOT_FOUND, $crawl->responseFor('https://example.com/gone')?->statusCode);
        $this->assertNotContains('https://example.com/three', $this->requestedUrls);
    }

    public function testReadsAPageFirstRequestedWithoutItsBodyWhenARedirectAlsoLeadsToIt(): void
    {
        $site = [
            'https://example.com/' => self::page('<a href="/b">B</a><a href="/r">R</a>'),
            'https://example.com/b' => self::page('<a href="/x">X</a>'),
            'https://example.com/r' => self::redirect('https://example.com/x'),
            'https://example.com/x' => self::page(),
        ];

        $crawl = $this->crawl($site, new CrawlOptions(maxDepth: 1, checkLinkTargets: true));

        $this->assertSame(
            ['https://example.com/', 'https://example.com/b', 'https://example.com/x'],
            self::urls($crawl),
        );
        $this->assertNotNull($crawl->responseFor('https://example.com/x')?->html);
    }

    public function testStopsAtTheMaxPagesLimitAndMarksTheCrawlAsTruncated(): void
    {
        $site = [
            'https://example.com/' => self::page('<a href="/one">1</a><a href="/two">2</a>'),
            'https://example.com/one' => self::page('<a href="/deeper">Deeper</a>'),
            'https://example.com/two' => self::page(),
        ];

        $crawl = $this->crawl($site, new CrawlOptions(maxPages: 2));

        $this->assertSame(['https://example.com/', 'https://example.com/one'], self::urls($crawl));
        $this->assertTrue($crawl->truncated);
        $this->assertNotContains('https://example.com/two', $this->requestedUrls);

        $this->requestedUrls = [];
        $crawl = $this->crawl($site, new CrawlOptions(maxPages: 2, checkLinkTargets: true));

        $this->assertSame(['https://example.com/', 'https://example.com/one'], self::urls($crawl));
        $this->assertTrue($crawl->truncated);
        $this->assertSame(Response::HTTP_OK, $crawl->responseFor('https://example.com/two')?->statusCode);
        $this->assertNull($crawl->responseFor('https://example.com/two')->html);
        $this->assertSame(Response::HTTP_NOT_FOUND, $crawl->responseFor('https://example.com/deeper')?->statusCode);
    }

    public function testACrawlThatFitsTheLimitIsNotTruncated(): void
    {
        $site = [
            'https://example.com/' => self::page('<a href="/one">1</a>'),
            'https://example.com/one' => self::page('<a href="/">Home</a>'),
        ];

        foreach ([2, 0] as $maxPages) {
            $crawl = $this->crawl($site, new CrawlOptions(maxPages: $maxPages));

            $this->assertCount(2, $crawl->pages, (string)$maxPages);
            $this->assertFalse($crawl->truncated, (string)$maxPages);
        }
    }

    public function testFollowsTheSiteWhenTheStartUrlRedirectsToAnotherHost(): void
    {
        $crawl = $this->crawl([
            'https://example.com/' => self::redirect('https://www.example.com/'),
            'https://www.example.com/' => self::page(
                '<a href="/page">Page</a><a href="https://example.com/x">Apex</a>'
            ),
            'https://www.example.com/page' => self::page(),
        ]);

        $this->assertSame('https://www.example.com/', $crawl->siteUrl);
        $this->assertSame(['https://www.example.com/', 'https://www.example.com/page'], self::urls($crawl));
        $this->assertNotContains('https://example.com/x', $this->requestedUrls);
    }

    public function testDoesNotFollowAnInternalLinkThatRedirectsToAnotherHost(): void
    {
        $crawl = $this->crawl([
            'https://example.com/' => self::page('<a href="/out">Out</a>'),
            'https://example.com/out' => self::redirect('https://other.com/'),
        ]);

        $this->assertSame(['https://example.com/'], self::urls($crawl));
        $this->assertCount(1, array_keys($this->requestedUrls, 'https://other.com/', true));
        $this->assertSame('https://other.com/', $crawl->redirectChains()['https://example.com/out']->finalUrl ?? null);
    }

    public function testLeavesOutExcludedUrlsAndPagesDisallowedByRobotsTxt(): void
    {
        $robotsTxtChecker = $this->createMock(RobotsTxtCheckerInterface::class);
        $robotsTxtChecker->method('isAllowed')->willReturnCallback(
            static fn(string $url): bool => !str_contains($url, '/private'),
        );

        $crawl = $this->crawl(
            [
                'https://example.com/' => self::page(
                    '<a href="/admin">Admin</a><a href="/private">Private</a><a href="/public">Public</a>'
                ),
                'https://example.com/public' => self::page(),
            ],
            new CrawlOptions(excludePatterns: ['#/admin#'], checkLinkTargets: true),
            $robotsTxtChecker,
        );

        $this->assertSame(['https://example.com/', 'https://example.com/public'], self::urls($crawl));
        $this->assertNotContains('https://example.com/admin', $this->requestedUrls);
        $this->assertNotContains('https://example.com/private', $this->requestedUrls);
        $this->assertSame(['https://example.com/private'], $crawl->disallowedUrls());
    }

    public function testStopsAfterTheStartPageWhenRobotsTxtAnswersAServerError(): void
    {
        $site = [
            'https://example.com/robots.txt' => ['', ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE]],
            'https://example.com/' => self::page('<a href="/page">Page</a>'),
            'https://example.com/page' => self::page(),
        ];
        $httpClient = $this->httpClient($site);

        $crawl = $this->crawler($httpClient, new RobotsTxtChecker($httpClient, 'TestBot/1.0'))
            ->crawl('https://example.com/', new CrawlOptions(checkLinkTargets: true));

        $this->assertSame(['https://example.com/'], self::urls($crawl));
        $this->assertTrue($crawl->blockedByRobotsTxt);
    }

    public function testReportsProgressAfterEachPageRead(): void
    {
        $calls = [];

        $this->crawl(
            [
                'https://example.com/' => self::page('<a href="/one">1</a>'),
                'https://example.com/one' => self::page(),
            ],
            new CrawlOptions(progressCallback: static function (string $url, int $pagesRead) use (&$calls): void {
                $calls[] = [$url, $pagesRead];
            }),
        );

        $this->assertSame([['https://example.com/', 1], ['https://example.com/one', 2]], $calls);
    }

    public function testMovesTheThrottleExemptionToWhereTheStartUrlRedirects(): void
    {
        $httpClient = new class($this->httpClient([
            'https://example.com/' => self::redirect('https://www.example.com/'),
            'https://www.example.com/' => self::page(),
        ])) implements HttpClientInterface, ThrottleExemptionInterface {
            /** @var list<array{0: ?string, 1: int, 2: int}> */
            public array $hostDelayCalls = [];

            public function __construct(private HttpClientInterface $inner)
            {
            }

            public function setHostDelay(?string $host, int $delayMs = 0, int $maxInFlight = 1): void
            {
                $this->hostDelayCalls[] = [$host, $delayMs, $maxInFlight];
            }

            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                return $this->inner->request($method, $url, $options);
            }

            public function stream(
                ResponseInterface|iterable $responses,
                ?float $timeout = null
            ): ResponseStreamInterface {
                return $this->inner->stream($responses, $timeout);
            }

            public function withOptions(array $options): static
            {
                $clone = clone $this;
                $clone->inner = $this->inner->withOptions($options);

                return $clone;
            }
        };

        $exemption = SiteThrottleExemption::begin($httpClient, 'https://example.com/');
        $this->crawler($httpClient)->crawl('https://example.com/', new CrawlOptions(), $exemption);
        $exemption->end();

        $this->assertSame(
            [['example.com', 0, 1], ['www.example.com', 0, 1], [null, 0, 1]],
            $httpClient->hostDelayCalls,
        );
    }

    public function testReadsSeveralPagesAtOnceWhenTheHostAllowsIt(): void
    {
        $limiter = new HostRateLimiter();
        $limiter->setHostLimits('example.com', 0, 3);

        [$crawl, $peak] = $this->crawlCountingRequestsInFlight(new CrawlOptions(concurrency: 3), $limiter);

        $this->assertCount(4, $crawl->pages);
        $this->assertSame(3, $peak);
    }

    public function testReadsOnePageAtATimeWhenTheHostIsGivenASingleSlot(): void
    {
        [$crawl, $peak] = $this->crawlCountingRequestsInFlight(new CrawlOptions(concurrency: 3));

        $this->assertCount(4, $crawl->pages);
        $this->assertSame(1, $peak);
    }

    public function testOrdersThePagesByDepthAndUrlWhateverTheAnswersOrder(): void
    {
        $site = [
            'https://example.com/' => self::page('<a href="/c">C</a><a href="/b">B</a><a href="/a">A</a>'),
            'https://example.com/a' => self::page(),
            'https://example.com/b' => self::page(),
            'https://example.com/c' => self::page('<a href="/d">D</a>'),
            'https://example.com/d' => self::page(),
        ];

        $crawl = $this->crawl($site, new CrawlOptions(concurrency: 4));

        $this->assertSame(
            [
                'https://example.com/',
                'https://example.com/a',
                'https://example.com/b',
                'https://example.com/c',
                'https://example.com/d',
            ],
            self::urls($crawl),
        );
    }

    /**
     * @return array{CrawlResult, int}
     */
    private function crawlCountingRequestsInFlight(CrawlOptions $options, ?HostRateLimiter $limiter = null): array
    {
        $inFlight = 0;
        $peak = 0;
        $site = [
            'https://example.com/' => '<a href="/a">A</a><a href="/b">B</a><a href="/c">C</a>',
            'https://example.com/a' => '',
            'https://example.com/b' => '',
            'https://example.com/c' => '',
        ];
        $httpClient = new MockHttpClient(
            static function (string $method, string $url) use (&$inFlight, &$peak, $site): MockResponse {
                $inFlight++;
                $peak = max($peak, $inFlight);
                $body = '<!DOCTYPE html><html><head><title>T</title></head><body>'
                    .($site[$url] ?? '').'</body></html>';

                return new MockResponse(
                    (static function () use (&$inFlight, $body): iterable {
                        $inFlight--;

                        yield $body;
                    })(),
                    ['response_headers' => ['content-type' => 'text/html; charset=UTF-8']],
                );
            }
        );

        return [$this->crawler($httpClient, rateLimiter: $limiter)->crawl('https://example.com/', $options), $peak];
    }

    private function crawl(
        array $site,
        CrawlOptions $options = new CrawlOptions(),
        ?RobotsTxtCheckerInterface $robotsTxtChecker = null,
    ): CrawlResult {
        return $this->crawler($this->httpClient($site), $robotsTxtChecker)->crawl('https://example.com/', $options);
    }

    private function crawler(
        HttpClientInterface $httpClient,
        ?RobotsTxtCheckerInterface $robotsTxtChecker = null,
        ?HostRateLimiter $rateLimiter = null,
    ): SiteCrawler {
        $pageFetcher = new PageFetcher($httpClient);

        return new SiteCrawler(
            $pageFetcher,
            new RedirectChainResolver($pageFetcher),
            robotsTxtChecker: $robotsTxtChecker,
            rateLimiter: $rateLimiter,
        );
    }

    /**
     * @param array<string, array{string, array<string, mixed>}> $site URL => [body, info]
     */
    private function httpClient(array $site): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url) use ($site): MockResponse {
            if (!str_ends_with($url, '/robots.txt')) {
                $this->requestedUrls[] = $url;
            }

            if (!isset($site[$url])) {
                return new MockResponse('', ['http_code' => Response::HTTP_NOT_FOUND]);
            }

            [$body, $info] = $site[$url];

            return new MockResponse($body, $info);
        });
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function page(string $body = ''): array
    {
        return [
            '<!DOCTYPE html><html><head><title>T</title></head><body>'.$body.'</body></html>',
            ['response_headers' => ['content-type' => 'text/html; charset=UTF-8']],
        ];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function redirect(string $location): array
    {
        return ['', ['http_code' => Response::HTTP_MOVED_PERMANENTLY, 'response_headers' => ['location' => $location]]];
    }

    /**
     * @return list<string>
     */
    private static function urls(CrawlResult $crawl): array
    {
        return array_map(static fn(CrawledPage $page): string => $page->url, $crawl->pages);
    }
}
