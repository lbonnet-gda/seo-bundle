<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Http;

use Lbonnet\SeoBundle\Http\ThrottledHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

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
