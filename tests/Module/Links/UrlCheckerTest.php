<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Module\Links;

use Lbonnet\SeoBundle\Module\Links\Model\BotProvider;
use Lbonnet\SeoBundle\Module\Links\UrlChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class UrlCheckerTest extends TestCase
{
    public function testCheckSuccessfulHeadRequest(): void
    {
        $mockResponse = new MockResponse('', [
            'http_code' => Response::HTTP_OK,
            'response_headers' => ['content-type' => 'text/html; charset=UTF-8'],
        ]);
        $client = new MockHttpClient($mockResponse);
        $checker = new UrlChecker($client, 5);

        $result = $checker->check('https://example.com');

        $this->assertFalse($result->isBroken());
        $this->assertSame(Response::HTTP_OK, $result->statusCode);
        $this->assertNull($result->errorMessage);
    }

    public function testCheckFallbackToGetOn405(): void
    {
        $responses = [
            new MockResponse('', ['http_code' => Response::HTTP_METHOD_NOT_ALLOWED]),
            new MockResponse('content', ['http_code' => Response::HTTP_OK]),
        ];
        $client = new MockHttpClient($responses);
        $checker = new UrlChecker($client, 5);

        $result = $checker->check('https://example.com/api');

        $this->assertFalse($result->isBroken());
        $this->assertSame(Response::HTTP_OK, $result->statusCode);
    }

    public function testCheckFallbackToGetOn404(): void
    {
        $responses = [
            new MockResponse('', ['http_code' => Response::HTTP_NOT_FOUND]),
            new MockResponse('content', ['http_code' => Response::HTTP_OK]),
        ];
        $client = new MockHttpClient($responses);
        $checker = new UrlChecker($client, 5);

        $result = $checker->check('https://example.com/head-not-supported');

        $this->assertFalse($result->isBroken());
        $this->assertSame(Response::HTTP_OK, $result->statusCode);
    }

    public function testCheckSendsConfiguredUserAgent(): void
    {
        $seenUserAgent = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seenUserAgent) {
            foreach ($options['headers'] as $header) {
                if (str_starts_with($header, 'User-Agent:')) {
                    $seenUserAgent = trim(substr($header, strlen('User-Agent:')));
                }
            }

            return new MockResponse('', ['http_code' => Response::HTTP_OK]);
        });
        $checker = new UrlChecker($client, 5, 'MyCustomBot/1.0');

        $checker->check('https://example.com');

        $this->assertSame('MyCustomBot/1.0', $seenUserAgent);
    }

    public function testCheckFlagsLikelyBlockedOnAkamaiSignature(): void
    {
        $akamaiResponse = static fn() => new MockResponse('', [
            'http_code' => Response::HTTP_FORBIDDEN,
            'response_headers' => [
                'server-timing' => ['cdn-cache; desc=HIT', 'edge; dur=1', 'ak_p; desc="123"'],
            ],
        ]);
        $responses = [$akamaiResponse(), $akamaiResponse()];
        $client = new MockHttpClient($responses);
        $checker = new UrlChecker($client, 5);

        $result = $checker->check('https://protected.example.com');

        $this->assertTrue($result->isBroken());
        $this->assertSame(Response::HTTP_FORBIDDEN, $result->statusCode);
        $this->assertTrue($result->likelyBlocked);
        $this->assertSame(BotProvider::Akamai, $result->blockedBy);
    }

    public function testChecksSeveralUrlsAtOnceAndKeepsTheOrderTheyWereGivenIn(): void
    {
        $events = [];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$events): MockResponse {
            $events[] = 'sent '.$url;
            $code = str_contains($url, 'gone') ? Response::HTTP_NOT_FOUND : Response::HTTP_OK;

            return new MockResponse(
                (static function () use (&$events, $url): iterable {
                    $events[] = 'read '.$url;

                    yield 'body';
                })(),
                ['http_code' => $code],
            );
        });

        $results = (new UrlChecker($client, 5))->checkMany([
            'https://a.example.com/ok',
            'https://b.example.com/gone',
            'https://c.example.com/ok',
        ]);

        $this->assertSame(
            ['https://a.example.com/ok', 'https://b.example.com/gone', 'https://c.example.com/ok'],
            array_keys($results),
        );
        $this->assertFalse($results['https://a.example.com/ok']->isBroken());
        $this->assertTrue($results['https://b.example.com/gone']->isBroken());

        $this->assertSame(
            [
                'sent https://a.example.com/ok',
                'sent https://b.example.com/gone',
                'sent https://c.example.com/ok',
            ],
            array_slice($events, 0, 3),
        );
    }

    public function testChecksUrlsSharingAHostOneAfterTheOther(): void
    {
        $inFlight = 0;
        $peak = 0;
        $client = new MockHttpClient(static function () use (&$inFlight, &$peak): MockResponse {
            $inFlight++;
            $peak = max($peak, $inFlight);

            return new MockResponse(
                (static function () use (&$inFlight): iterable {
                    $inFlight--;

                    yield 'body';
                })(),
                ['http_code' => Response::HTTP_OK],
            );
        });

        $results = (new UrlChecker($client, 5))->checkMany([
            'https://example.com/a',
            'https://example.com/b',
            'https://example.com/c',
        ]);

        $this->assertCount(3, $results);
        $this->assertSame(1, $peak, 'one host, one request at a time');
    }

    public function testFallsBackToAGetInsideABatch(): void
    {
        $methods = [];
        $client = new MockHttpClient(static function (string $method, string $url) use (&$methods): MockResponse {
            $methods[] = $method.' '.$url;
            $code = $method === Request::METHOD_HEAD && str_contains($url, 'picky')
                ? Response::HTTP_METHOD_NOT_ALLOWED
                : Response::HTTP_OK;

            return new MockResponse('', ['http_code' => $code]);
        });

        $results = (new UrlChecker($client, 5))->checkMany([
            'https://picky.example.com/a',
            'https://plain.example.com/b',
        ]);

        $this->assertFalse($results['https://picky.example.com/a']->isBroken());
        $this->assertSame(
            [
                'HEAD https://picky.example.com/a',
                'HEAD https://plain.example.com/b',
                'GET https://picky.example.com/a',
            ],
            $methods,
        );
    }

    public function testCheckDoesNotFlagOrdinaryForbiddenAsBlocked(): void
    {
        $responses = [
            new MockResponse('', ['http_code' => Response::HTTP_FORBIDDEN]),
            new MockResponse('', ['http_code' => Response::HTTP_FORBIDDEN]),
        ];
        $client = new MockHttpClient($responses);
        $checker = new UrlChecker($client, 5);

        $result = $checker->check('https://example.com/restricted');

        $this->assertTrue($result->isBroken());
        $this->assertFalse($result->likelyBlocked);
        $this->assertNull($result->blockedBy);
    }

    public function testCheckHandlesTransportException(): void
    {
        $client = new MockHttpClient(static function () {
            throw new TransportException('Connection timeout');
        });
        $checker = new UrlChecker($client, 2);

        $result = $checker->check('https://timeout.com');

        $this->assertTrue($result->isBroken());
        $this->assertNull($result->statusCode);
        $this->assertStringContainsString('Connection timeout', (string)$result->errorMessage);
    }
}
