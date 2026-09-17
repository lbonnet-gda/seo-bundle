<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Robots;

use Lbonnet\SeoBundle\Robots\RobotsTxtChecker;
use Lbonnet\SeoBundle\Robots\RobotsTxtStatus;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class RobotsTxtCheckerTest extends TestCase
{
    public function testAllowsEverythingWhenDisabled(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new LogicException('robots.txt should not be fetched when disabled');
        });
        $checker = new RobotsTxtChecker($client, 'TestBot/1.0', enabled: false);

        $this->assertTrue($checker->isAllowed('https://example.com/anything'));
    }

    public function testAllowsEverythingWhenRobotsTxtIsMissing(): void
    {
        $client = new MockHttpClient(static fn() => new MockResponse('', ['http_code' => Response::HTTP_NOT_FOUND]));
        $checker = new RobotsTxtChecker($client, 'TestBot/1.0');

        $this->assertTrue($checker->isAllowed('https://example.com/anything'));
    }

    public function testBlocksTheWholeSiteWhenRobotsTxtAnswersAServerError(): void
    {
        $clients = [
            '503' => new MockHttpClient(
                static fn() => new MockResponse('', ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE])
            ),
            '429' => new MockHttpClient(
                static fn() => new MockResponse('', ['http_code' => Response::HTTP_TOO_MANY_REQUESTS])
            ),
            'network failure' => new MockHttpClient(static function (): MockResponse {
                throw new TransportException('Connection refused');
            }),
        ];

        foreach ($clients as $case => $client) {
            $checker = new RobotsTxtChecker($client, 'TestBot/1.0');

            $this->assertTrue($checker->isSiteBlocked('https://example.com/anything'), (string)$case);
            $this->assertFalse($checker->isAllowed('https://example.com/anything'), (string)$case);
        }
    }

    public function testAServerErrorBlocksNothingWhenDisabled(): void
    {
        $client = new MockHttpClient(
            static fn() => new MockResponse('', ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE])
        );
        $checker = new RobotsTxtChecker($client, 'TestBot/1.0', enabled: false);

        $this->assertFalse($checker->isSiteBlocked('https://example.com/anything'));
        $this->assertTrue($checker->isAllowed('https://example.com/anything'));
    }

    public function testAMissingOrForbiddenRobotsTxtDoesNotBlockTheSite(): void
    {
        foreach ([Response::HTTP_NOT_FOUND, Response::HTTP_FORBIDDEN] as $httpCode) {
            $client = new MockHttpClient(static fn() => new MockResponse('', ['http_code' => $httpCode]));

            $this->assertFalse(
                (new RobotsTxtChecker($client, 'TestBot/1.0'))->isSiteBlocked('https://example.com/'),
                (string)$httpCode,
            );
        }
    }

    public function testDisallowsMatchingPathUnderWildcardGroup(): void
    {
        $robotsTxt = "User-agent: *\nDisallow: /admin\n";
        $client = new MockHttpClient(static fn() => new MockResponse($robotsTxt));
        $checker = new RobotsTxtChecker($client, 'TestBot/1.0');

        $this->assertFalse($checker->isAllowed('https://example.com/admin/settings'));
        $this->assertTrue($checker->isAllowed('https://example.com/blog'));
    }

    public function testMostSpecificRuleWins(): void
    {
        $robotsTxt = "User-agent: *\nDisallow: /admin\nAllow: /admin/public\n";
        $client = new MockHttpClient(static fn() => new MockResponse($robotsTxt));
        $checker = new RobotsTxtChecker($client, 'TestBot/1.0');

        $this->assertFalse($checker->isAllowed('https://example.com/admin/secret'));
        $this->assertTrue($checker->isAllowed('https://example.com/admin/public/page'));
    }

    public function testUserAgentSpecificGroupOverridesWildcardGroup(): void
    {
        $robotsTxt = "User-agent: *\nDisallow: /\nUser-agent: TestBot\nDisallow: /private\n";
        $client = new MockHttpClient(static fn() => new MockResponse($robotsTxt));
        $checker = new RobotsTxtChecker($client, 'TestBot/1.0');

        $this->assertTrue($checker->isAllowed('https://example.com/public'));
        $this->assertFalse($checker->isAllowed('https://example.com/private/data'));
    }

    public function testSupportsWildcardAndEndAnchorPatterns(): void
    {
        $robotsTxt = "User-agent: *\nDisallow: /files/*.pdf$\n";
        $client = new MockHttpClient(static fn() => new MockResponse($robotsTxt));
        $checker = new RobotsTxtChecker($client, 'TestBot/1.0');

        $this->assertFalse($checker->isAllowed('https://example.com/files/report.pdf'));
        $this->assertTrue($checker->isAllowed('https://example.com/files/report.txt'));
    }

    public function testTruncatesRobotsTxtBeyondTheSizeCap(): void
    {
        $padding = str_repeat("# padding\n", 50_001);
        $body = (static function () use ($padding) {
            yield $padding;
            yield "User-agent: *\nDisallow: /late\n";
        })();

        $client = new MockHttpClient(static fn() => new MockResponse($body));
        $checker = new RobotsTxtChecker($client, 'TestBot/1.0');

        $this->assertTrue($checker->isAllowed('https://example.com/late'));
    }

    public function testCrawlDelayIsNullWhenNotSpecified(): void
    {
        $robotsTxt = "User-agent: *\nDisallow: /admin\n";
        $client = new MockHttpClient(static fn() => new MockResponse($robotsTxt));
        $checker = new RobotsTxtChecker($client, 'TestBot/1.0');

        $this->assertNull($checker->crawlDelay('https://example.com/'));
    }

    public function testCrawlDelayIsNullWhenDisabled(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new LogicException('robots.txt should not be fetched when disabled');
        });
        $checker = new RobotsTxtChecker($client, 'TestBot/1.0', enabled: false);

        $this->assertNull($checker->crawlDelay('https://example.com/'));
    }

    public function testParsesCrawlDelayForWildcardGroup(): void
    {
        $robotsTxt = "User-agent: *\nCrawl-delay: 10\n";
        $client = new MockHttpClient(static fn() => new MockResponse($robotsTxt));
        $checker = new RobotsTxtChecker($client, 'TestBot/1.0');

        $this->assertSame(10.0, $checker->crawlDelay('https://example.com/'));
    }

    public function testUserAgentSpecificCrawlDelayOverridesWildcard(): void
    {
        $robotsTxt = "User-agent: *\nCrawl-delay: 10\nUser-agent: TestBot\nCrawl-delay: 2\n";
        $client = new MockHttpClient(static fn() => new MockResponse($robotsTxt));
        $checker = new RobotsTxtChecker($client, 'TestBot/1.0');

        $this->assertSame(2.0, $checker->crawlDelay('https://example.com/'));
    }

    public function testFetchesRobotsTxtOnlyOncePerHost(): void
    {
        $calls = 0;
        $client = new MockHttpClient(static function () use (&$calls): MockResponse {
            $calls++;

            return new MockResponse("User-agent: *\nDisallow: /admin\n");
        });
        $checker = new RobotsTxtChecker($client, 'TestBot/1.0');

        $checker->isAllowed('https://example.com/admin');
        $checker->isAllowed('https://example.com/other');

        $this->assertSame(1, $calls);
    }

    /**
     * @dataProvider responseStatusProvider
     */
    public function testClassifiesTheRobotsTxtResponseTheWayGoogleDoes(int $httpCode, RobotsTxtStatus $expected): void
    {
        $client = new MockHttpClient(
            static fn() => new MockResponse("User-agent: *\nDisallow: /\n", ['http_code' => $httpCode])
        );
        $robotsTxt = (new RobotsTxtChecker($client, 'TestBot/1.0'))->robotsTxt('https://example.com/page');

        $this->assertNotNull($robotsTxt);
        $this->assertSame($expected, $robotsTxt->status);
        $this->assertSame($httpCode, $robotsTxt->statusCode);
        $this->assertSame('https://example.com/robots.txt', $robotsTxt->url);
        $this->assertSame(
            $expected !== RobotsTxtStatus::Found,
            $robotsTxt->isAllowed('https://example.com/page', 'TestBot'),
        );
    }

    /**
     * @return iterable<string, array{int, RobotsTxtStatus}>
     */
    public static function responseStatusProvider(): iterable
    {
        yield '200' => [Response::HTTP_OK, RobotsTxtStatus::Found];
        yield '404' => [Response::HTTP_NOT_FOUND, RobotsTxtStatus::NotFound];
        yield '403' => [Response::HTTP_FORBIDDEN, RobotsTxtStatus::NotFound];
        yield 'redirect left once the redirects run out' => [
            Response::HTTP_MOVED_PERMANENTLY,
            RobotsTxtStatus::NotFound,
        ];
        yield '429' => [Response::HTTP_TOO_MANY_REQUESTS, RobotsTxtStatus::ServerError];
        yield '503' => [Response::HTTP_SERVICE_UNAVAILABLE, RobotsTxtStatus::ServerError];
    }

    public function testANetworkFailureIsAServerError(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Connection timed out');
        });
        $robotsTxt = (new RobotsTxtChecker($client, 'TestBot/1.0'))->robotsTxt('https://example.com/');

        $this->assertNotNull($robotsTxt);
        $this->assertSame(RobotsTxtStatus::ServerError, $robotsTxt->status);
        $this->assertNull($robotsTxt->statusCode);
    }

    public function testExposesTheRobotsTxtEvenWhenTheCrawlerDoesNotHonorIt(): void
    {
        $calls = 0;
        $client = new MockHttpClient(static function () use (&$calls): MockResponse {
            $calls++;

            return new MockResponse("User-agent: Googlebot\nDisallow: /\n");
        });
        $checker = new RobotsTxtChecker($client, 'TestBot/1.0', enabled: false);

        $this->assertTrue($checker->isAllowed('https://example.com/page'));
        $this->assertSame(0, $calls);
        $robotsTxt = $checker->robotsTxt('https://example.com/page');
        $this->assertFalse($robotsTxt?->isAllowed('https://example.com/page', 'Googlebot'));
        $this->assertSame(1, $calls);
    }

    public function testFollowsAtMostFiveRedirects(): void
    {
        $options = [];
        $client = new MockHttpClient(
            static function (string $method, string $url, array $requestOptions) use (&$options): MockResponse {
                $options = $requestOptions;

                return new MockResponse('');
            }
        );

        (new RobotsTxtChecker($client, 'TestBot/1.0'))->robotsTxt('https://example.com/');

        $this->assertSame(5, $options['max_redirects']);
    }

    public function testThereIsNoRobotsTxtForAUrlWithoutHost(): void
    {
        $this->assertNull((new RobotsTxtChecker(new MockHttpClient(), 'TestBot/1.0'))->robotsTxt('/relative/path'));
    }
}
