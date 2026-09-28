<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Http;

use Lbonnet\SeoBundle\Http\HostRateLimiter;
use PHPUnit\Framework\TestCase;

final class HostRateLimiterTest extends TestCase
{
    private float $now = 1_000.0;

    public function testLetsTheFirstRequestToAHostThrough(): void
    {
        $limiter = $this->limiter(delayMs: 200);

        $this->assertTrue($limiter->tryAcquire('example.com'));
        $this->assertSame(0.0, $limiter->waitTimeFor('example.com'));
    }

    public function testCountsTheDelayFromTheReleaseNotFromTheAcquire(): void
    {
        $limiter = $this->limiter(delayMs: 200);

        $limiter->tryAcquire('example.com');
        $this->now += 5.0; // a slow answer
        $limiter->release('example.com');

        $this->assertFalse($limiter->tryAcquire('example.com'));
        $this->assertEqualsWithDelta(0.2, $limiter->waitTimeFor('example.com'), 0.001);

        $this->now += 0.2;

        $this->assertTrue($limiter->tryAcquire('example.com'));
    }

    public function testHoldsOnlyOneRequestInFlightPerHostByDefault(): void
    {
        $limiter = $this->limiter();

        $this->assertTrue($limiter->tryAcquire('example.com'));
        $this->assertFalse($limiter->tryAcquire('example.com'));

        $limiter->release('example.com');

        $this->assertTrue($limiter->tryAcquire('example.com'));
    }

    public function testAFullHostReportsNothingToWaitFor(): void
    {
        $limiter = $this->limiter(delayMs: 200);

        $limiter->tryAcquire('example.com');

        $this->assertFalse($limiter->tryAcquire('example.com'));
        $this->assertSame(0.0, $limiter->waitTimeFor('example.com'));
    }

    public function testAllowsSeveralRequestsInFlightWhenTheHostSaysSo(): void
    {
        $limiter = $this->limiter();
        $limiter->setHostLimits('example.com', delayMs: 0, maxInFlight: 3);

        $this->assertTrue($limiter->tryAcquire('example.com'));
        $this->assertTrue($limiter->tryAcquire('example.com'));
        $this->assertTrue($limiter->tryAcquire('example.com'));
        $this->assertFalse($limiter->tryAcquire('example.com'));
    }

    public function testWaitingOnOneHostDoesNotHoldBackAnother(): void
    {
        $limiter = $this->limiter(delayMs: 200);

        $limiter->tryAcquire('example.com');
        $limiter->release('example.com');

        $this->assertFalse($limiter->tryAcquire('example.com'));
        $this->assertTrue($limiter->tryAcquire('other.example.org'));
    }

    public function testHostLimitsOverrideTheDefaultsUntilTheyAreCleared(): void
    {
        $limiter = $this->limiter(delayMs: 500);
        $limiter->setHostLimits('example.com', delayMs: 0);

        $limiter->tryAcquire('example.com');
        $limiter->release('example.com');

        $this->assertTrue($limiter->tryAcquire('example.com'));

        $limiter->clearHostLimits('example.com');
        $limiter->release('example.com');

        $this->assertFalse($limiter->tryAcquire('example.com'));
        $this->assertEqualsWithDelta(0.5, $limiter->waitTimeFor('example.com'), 0.001);
    }

    public function testTreatsAHostWhateverItsCase(): void
    {
        $limiter = $this->limiter(delayMs: 200);

        $limiter->tryAcquire('Example.COM');
        $limiter->release('EXAMPLE.com');

        $this->assertFalse($limiter->tryAcquire('example.com'));
    }

    public function testResetForgetsThePaceButKeepsTheLimits(): void
    {
        $limiter = $this->limiter(delayMs: 500);
        $limiter->setHostLimits('example.com', delayMs: 0);

        $limiter->tryAcquire('other.example.org');
        $limiter->release('other.example.org');
        $limiter->tryAcquire('example.com');

        $limiter->reset();

        $this->assertTrue($limiter->tryAcquire('other.example.org'));
        $this->assertTrue($limiter->tryAcquire('example.com'));
        $limiter->release('example.com');
        $this->assertTrue($limiter->tryAcquire('example.com'));
    }

    private function limiter(int $delayMs = 0, int $maxInFlight = 1): HostRateLimiter
    {
        return new HostRateLimiter($delayMs, $maxInFlight, fn(): float => $this->now);
    }
}
