<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Http;

use Lbonnet\SeoBundle\Http\HostRateLimiter;
use Lbonnet\SeoBundle\Http\ThrottledHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ThrottledHttpClientTest extends TestCase
{
    public function testDoesNotDelayWhenDisabled(): void
    {
        $client = new ThrottledHttpClient(new MockHttpClient(static fn() => new MockResponse()), 0);

        $start = microtime(true);
        $client->request(Request::METHOD_GET, 'https://example.com/a');
        $client->request(Request::METHOD_GET, 'https://example.com/b');
        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertLessThan(50, $elapsedMs);
    }

    public function testGivesTheHostSlotBackOnceTheResponseHasBeenRead(): void
    {
        [$client, $limiter] = $this->sharedLimiterClient();

        $client->request(Request::METHOD_GET, 'https://example.com/robots.txt')->getContent();

        $this->assertSame(4, self::freeSlots($limiter));
    }

    public function testGivesTheHostSlotBackWhenOnlyTheHeadersAreLookedAt(): void
    {
        [$client, $limiter] = $this->sharedLimiterClient();

        $response = $client->request(Request::METHOD_GET, 'https://example.com/a');
        $response->getStatusCode();
        unset($response);

        $this->assertSame(4, self::freeSlots($limiter));
    }

    public function testDoesNotKeepASlotForAResponseItsCallerHasMovedOnFrom(): void
    {
        [$client, $limiter] = $this->sharedLimiterClient();

        $held = $client->request(Request::METHOD_GET, 'https://example.com/a');
        $client->request(Request::METHOD_GET, 'https://example.com/b')->getContent();

        $this->assertSame(4, self::freeSlots($limiter));
        $this->assertSame(Response::HTTP_OK, $held->getStatusCode());
    }

    /**
     * @return array{ThrottledHttpClient, HostRateLimiter}
     */
    private function sharedLimiterClient(): array
    {
        $limiter = new HostRateLimiter();
        $limiter->setHostLimits('example.com', 0, 4);
        $client = new ThrottledHttpClient(new MockHttpClient(static fn() => new MockResponse('ok')), 0, $limiter);

        return [$client, $limiter];
    }

    private static function freeSlots(HostRateLimiter $limiter): int
    {
        $slots = 0;

        while ($limiter->tryAcquire('example.com')) {
            $slots++;
        }

        return $slots;
    }

    public function testDelaysConsecutiveRequestsToTheSameHost(): void
    {
        $client = new ThrottledHttpClient(new MockHttpClient(static fn() => new MockResponse()), 100);

        $start = microtime(true);
        $client->request(Request::METHOD_GET, 'https://example.com/a');
        $client->request(Request::METHOD_GET, 'https://example.com/b');
        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertGreaterThanOrEqual(90, $elapsedMs);
    }

    public function testCountsTheDelayFromTheEndOfTheRequestNotFromItsStart(): void
    {
        $client = new ThrottledHttpClient(new MockHttpClient(static function (): MockResponse {
            usleep(150_000);

            return new MockResponse();
        }), 100);

        $start = microtime(true);
        $client->request(Request::METHOD_GET, 'https://example.com/a');
        $client->request(Request::METHOD_GET, 'https://example.com/b');
        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertGreaterThanOrEqual(360, $elapsedMs);
    }

    public function testDoesNotDelayRequestsToDifferentHosts(): void
    {
        $client = new ThrottledHttpClient(new MockHttpClient(static fn() => new MockResponse()), 200);

        $start = microtime(true);
        $client->request(Request::METHOD_GET, 'https://example.com/a');
        $client->request(Request::METHOD_GET, 'https://other-example.com/b');
        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertLessThan(100, $elapsedMs);
    }

    public function testDoesNotDelayTheExemptedHost(): void
    {
        $client = new ThrottledHttpClient(new MockHttpClient(static fn() => new MockResponse()), 200);
        $client->setHostDelay('example.com');

        $start = microtime(true);
        $client->request(Request::METHOD_GET, 'https://example.com/a');
        $client->request(Request::METHOD_GET, 'https://example.com/b');
        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertLessThan(100, $elapsedMs);
    }

    public function testStillDelaysOtherHostsWhileOneIsExempted(): void
    {
        $client = new ThrottledHttpClient(new MockHttpClient(static fn() => new MockResponse()), 100);
        $client->setHostDelay('example.com');

        $start = microtime(true);
        $client->request(Request::METHOD_GET, 'https://other-example.com/a');
        $client->request(Request::METHOD_GET, 'https://other-example.com/b');
        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertGreaterThanOrEqual(90, $elapsedMs);
    }

    public function testClearingTheExemptionResumesThrottlingThatHost(): void
    {
        $client = new ThrottledHttpClient(new MockHttpClient(static fn() => new MockResponse()), 100);
        $client->setHostDelay('example.com');
        $client->request(Request::METHOD_GET, 'https://example.com/a');
        $client->setHostDelay(null);

        $start = microtime(true);
        $client->request(Request::METHOD_GET, 'https://example.com/b');
        $client->request(Request::METHOD_GET, 'https://example.com/c');
        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertGreaterThanOrEqual(90, $elapsedMs);
    }

    public function testHostDelayOverrideAppliesEvenBelowTheGlobalDelay(): void
    {
        $client = new ThrottledHttpClient(new MockHttpClient(static fn() => new MockResponse()), 50);
        $client->setHostDelay('example.com', 200);

        $start = microtime(true);
        $client->request(Request::METHOD_GET, 'https://example.com/a');
        $client->request(Request::METHOD_GET, 'https://example.com/b');
        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertGreaterThanOrEqual(190, $elapsedMs);
    }

    public function testHostDelayOverrideAppliesEvenAboveTheGlobalDelay(): void
    {
        $client = new ThrottledHttpClient(new MockHttpClient(static fn() => new MockResponse()), 200);
        $client->setHostDelay('example.com', 50);

        $start = microtime(true);
        $client->request(Request::METHOD_GET, 'https://example.com/a');
        $client->request(Request::METHOD_GET, 'https://example.com/b');
        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertGreaterThanOrEqual(40, $elapsedMs);
        $this->assertLessThan(150, $elapsedMs);
    }
}
