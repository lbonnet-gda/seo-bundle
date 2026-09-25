<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Module\Links;

use Lbonnet\SeoBundle\Module\Links\Model\BotProvider;
use Lbonnet\SeoBundle\Module\Links\UrlChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
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

        $this->assertTrue($result->isReachable());
        $this->assertFalse($result->isBroken());
        $this->assertSame(Response::HTTP_OK, $result->statusCode);
        $this->assertSame('text/html; charset=UTF-8', $result->contentType);
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

        $this->assertTrue($result->isReachable());
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

        $this->assertTrue($result->isReachable());
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

        $this->assertFalse($result->isReachable());
        $this->assertTrue($result->isBroken());
        $this->assertNull($result->statusCode);
        $this->assertStringContainsString('Connection timeout', (string)$result->errorMessage);
    }
}
