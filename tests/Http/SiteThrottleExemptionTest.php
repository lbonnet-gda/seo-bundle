<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Http;

use Lbonnet\SeoBundle\Http\SiteThrottleExemption;
use Lbonnet\SeoBundle\Http\ThrottleExemptionInterface;
use Lbonnet\SeoBundle\Robots\RobotsTxtCheckerInterface;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class SiteThrottleExemptionTest extends TestCase
{
    public function testBeginExemptsTheSiteHost(): void
    {
        $client = self::spyClient();

        SiteThrottleExemption::begin($client, 'https://example.com/page');

        $this->assertSame([['example.com', 0, 1]], $client->hostDelayCalls);
    }

    public function testBeginHonorsTheSiteRobotsTxtCrawlDelay(): void
    {
        $client = self::spyClient();
        $robotsTxtChecker = $this->createMock(RobotsTxtCheckerInterface::class);
        $robotsTxtChecker->method('crawlDelay')->with('https://example.com')->willReturn(2.5);

        SiteThrottleExemption::begin($client, 'https://example.com', $robotsTxtChecker);

        $this->assertSame([['example.com', 2500, 1]], $client->hostDelayCalls);
    }

    public function testMoveToAnotherHostExemptsThatHostWithItsOwnCrawlDelay(): void
    {
        $client = self::spyClient();
        $robotsTxtChecker = $this->createMock(RobotsTxtCheckerInterface::class);
        $robotsTxtChecker->method('crawlDelay')->willReturnMap([
            ['https://example.com', null],
            ['https://www.example.com/fr', 1.0],
        ]);

        $exemption = SiteThrottleExemption::begin($client, 'https://example.com', $robotsTxtChecker);
        $exemption->moveTo('https://www.example.com/fr');

        $this->assertSame([['example.com', 0, 1], ['www.example.com', 1000, 1]], $client->hostDelayCalls);
    }

    public function testMoveToTheSameHostDoesNothing(): void
    {
        $client = self::spyClient();
        $robotsTxtChecker = $this->createMock(RobotsTxtCheckerInterface::class);
        $robotsTxtChecker->expects($this->once())->method('crawlDelay');

        $exemption = SiteThrottleExemption::begin($client, 'https://example.com', $robotsTxtChecker);
        $exemption->moveTo('https://EXAMPLE.com/other-page');

        $this->assertSame([['example.com', 0, 1]], $client->hostDelayCalls);
    }

    public function testEndClearsTheExemptionOnce(): void
    {
        $client = self::spyClient();

        $exemption = SiteThrottleExemption::begin($client, 'https://example.com');
        $exemption->end();
        $exemption->end();

        $this->assertSame([['example.com', 0, 1], [null, 0, 1]], $client->hostDelayCalls);
    }

    public function testASiteUrlWithoutHostExemptsNothing(): void
    {
        $client = self::spyClient();

        SiteThrottleExemption::begin($client, 'not a url')->end();

        $this->assertSame([], $client->hostDelayCalls);
    }

    public function testIsInertWithAClientThatDoesNotSupportExemptions(): void
    {
        $this->expectNotToPerformAssertions();

        $exemption = SiteThrottleExemption::begin(new MockHttpClient(), 'https://example.com');
        $exemption->moveTo('https://www.example.com');
        $exemption->end();
    }

    private static function spyClient(): ThrottleExemptionInterface|HttpClientInterface
    {
        return new class implements HttpClientInterface, ThrottleExemptionInterface {
            /** @var list<array{0: ?string, 1: int, 2: int}> */
            public array $hostDelayCalls = [];

            public function setHostDelay(?string $host, int $delayMs = 0, int $maxInFlight = 1): void
            {
                $this->hostDelayCalls[] = [$host, $delayMs, $maxInFlight];
            }

            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                throw new LogicException('No request is expected.');
            }

            public function stream(
                ResponseInterface|iterable $responses,
                ?float $timeout = null
            ): ResponseStreamInterface {
                throw new LogicException('No stream is expected.');
            }

            public function withOptions(array $options): static
            {
                return clone $this;
            }
        };
    }
}
