<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Http;

use Lbonnet\SeoBundle\Http\PageFetcher;
use Lbonnet\SeoBundle\Http\RedirectChainResolver;
use Lbonnet\SeoBundle\Model\PageResponse;
use Lbonnet\SeoBundle\Model\RedirectHop;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class RedirectChainResolverTest extends TestCase
{
    public function testResolvesASingleHopToItsFinalStatus(): void
    {
        $resolver = $this->resolver([
            'https://example.com/b' => self::ok(),
        ]);

        $chain = $resolver->resolve(self::redirectFrom('https://example.com/a', 'https://example.com/b'));

        $this->assertSame(1, $chain->hopCount());
        $this->assertSame('https://example.com/b', $chain->finalUrl);
        $this->assertSame(Response::HTTP_OK, $chain->finalStatusCode);
        $this->assertFalse($chain->isLoop);
        $this->assertFalse($chain->truncated);
        $this->assertSame(Response::HTTP_MOVED_PERMANENTLY, $chain->startStatusCode);
    }

    public function testFollowsSeveralHops(): void
    {
        $resolver = $this->resolver([
            'https://example.com/b' => self::redirect('https://example.com/c'),
            'https://example.com/c' => self::ok(),
        ]);

        $chain = $resolver->resolve(self::redirectFrom('https://example.com/a', 'https://example.com/b'));

        $this->assertSame(2, $chain->hopCount());
        $this->assertSame(
            ['https://example.com/a', 'https://example.com/b'],
            array_map(static fn(RedirectHop $hop): string => $hop->url, $chain->hops),
        );
        $this->assertSame('https://example.com/c', $chain->finalUrl);
        $this->assertSame(Response::HTTP_OK, $chain->finalStatusCode);
    }

    public function testResolvesARelativeLocationHeader(): void
    {
        $resolver = $this->resolver([
            'https://example.com/section/b' => self::ok(),
        ]);

        $chain = $resolver->resolve(
            self::redirectFrom('https://example.com/section/a', 'b', Response::HTTP_FOUND),
        );

        $this->assertSame('https://example.com/section/b', $chain->finalUrl);
        $this->assertSame(Response::HTTP_OK, $chain->finalStatusCode);
        $this->assertSame(Response::HTTP_FOUND, $chain->startStatusCode);
    }

    public function testMovingToHttpsIsNotALoop(): void
    {
        $chain = $this->resolver(['https://example.com/page' => ['', ['http_code' => Response::HTTP_OK]]])->resolve(
            new PageResponse(
                'http://example.com/page',
                Response::HTTP_MOVED_PERMANENTLY,
                ['location' => ['https://example.com/page']],
            )
        );

        $this->assertFalse($chain->isLoop);
        $this->assertSame('https://example.com/page', $chain->finalUrl);
        $this->assertSame(Response::HTTP_OK, $chain->finalStatusCode);
    }

    public function testDetectsALoop(): void
    {
        $resolver = $this->resolver([
            'https://example.com/b' => self::redirect('https://example.com/a'),
        ]);

        $chain = $resolver->resolve(self::redirectFrom('https://example.com/a', 'https://example.com/b'));

        $this->assertTrue($chain->isLoop);
        $this->assertSame(2, $chain->hopCount());
        $this->assertNull($chain->finalStatusCode);
    }

    public function testStopsAtTheFollowBudget(): void
    {
        $httpClient = new MockHttpClient(static function (string $method, string $url): MockResponse {
            $next = (int)substr($url, (int)strrpos($url, '/') + 1) + 1;

            return new MockResponse('', [
                'http_code' => Response::HTTP_MOVED_PERMANENTLY,
                'response_headers' => ['location' => 'https://example.com/'.$next],
            ]);
        });

        $resolver = new RedirectChainResolver(new PageFetcher($httpClient), maxFollowedHops: 3);
        $chain = $resolver->resolve(self::redirectFrom('https://example.com/0', 'https://example.com/1'));

        $this->assertTrue($chain->truncated);
        $this->assertSame(3, $chain->hopCount());
        $this->assertNull($chain->finalStatusCode);
    }

    public function testARedirectWithoutLocationEndsTheChain(): void
    {
        $resolver = new RedirectChainResolver(new PageFetcher(new MockHttpClient()));

        $chain = $resolver->resolve(
            new PageResponse('https://example.com/a', Response::HTTP_FOUND),
        );

        $this->assertSame(0, $chain->hopCount());
        $this->assertSame('https://example.com/a', $chain->finalUrl);
        $this->assertSame(Response::HTTP_FOUND, $chain->finalStatusCode);
    }

    public function testTransportFailureLeavesTheFinalStatusUnknown(): void
    {
        $httpClient = new MockHttpClient(
            static fn(): MockResponse => new MockResponse('', ['error' => 'Connection refused'])
        );

        $resolver = new RedirectChainResolver(new PageFetcher($httpClient));
        $chain = $resolver->resolve(self::redirectFrom('https://example.com/a', 'https://example.com/b'));

        $this->assertSame(1, $chain->hopCount());
        $this->assertSame('https://example.com/b', $chain->finalUrl);
        $this->assertNull($chain->finalStatusCode);
    }

    public function testAClientResolvedLocationWins(): void
    {
        $resolver = $this->resolver(['https://example.com/from-info' => self::ok()]);

        $chain = $resolver->resolve(
            new PageResponse(
                url: 'https://example.com/a',
                statusCode: Response::HTTP_MOVED_PERMANENTLY,
                headers: ['location' => ['/from-header']],
                redirectLocation: 'https://example.com/from-info',
            )
        );

        $this->assertSame('https://example.com/from-info', $chain->finalUrl);
    }

    /**
     * @param array<string, array{string, array<string, mixed>}> $responses
     */
    private function resolver(array $responses): RedirectChainResolver
    {
        $httpClient = new MockHttpClient(
            static function (string $method, string $url) use ($responses): MockResponse {
                if (!isset($responses[$url])) {
                    return new MockResponse('', ['http_code' => Response::HTTP_NOT_FOUND]);
                }

                [$body, $info] = $responses[$url];

                return new MockResponse($body, $info);
            }
        );

        return new RedirectChainResolver(new PageFetcher($httpClient));
    }

    private static function redirectFrom(
        string $url,
        string $location,
        int $statusCode = Response::HTTP_MOVED_PERMANENTLY,
    ): PageResponse {
        return new PageResponse($url, $statusCode, ['location' => [$location]]);
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function ok(): array
    {
        return ['', ['response_headers' => ['content-type' => 'text/html']]];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function redirect(string $location): array
    {
        return [
            '',
            [
                'http_code' => Response::HTTP_MOVED_PERMANENTLY,
                'response_headers' => ['location' => $location],
            ],
        ];
    }
}
