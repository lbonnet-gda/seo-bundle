<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Http;

use Closure;
use Symfony\Contracts\Service\ResetInterface;

final class HostRateLimiter implements ResetInterface
{
    /** @var Closure(): float */
    private readonly Closure $clock;

    /** @var array<string, array{delayMs: int, maxInFlight: int}> host => its own limits, when they differ */
    private array $limits = [];

    /** @var array<string, float> host => the earliest time a request may be sent to it */
    private array $nextAllowedAt = [];

    /** @var array<string, int> host => how many requests are in flight */
    private array $inFlight = [];

    /**
     * @param int $defaultDelayMs minimum delay between two requests to the same host
     * @param int $defaultMaxInFlight how many requests may be in flight per host
     * @param (Closure(): float)|null $clock seconds elapsed, from any origin, as long as it never goes backwards
     */
    public function __construct(
        private readonly int $defaultDelayMs = 0,
        private readonly int $defaultMaxInFlight = 1,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): float => hrtime(true) / 1e9;
    }

    public function setHostLimits(string $host, int $delayMs, int $maxInFlight = 1): void
    {
        $this->limits[strtolower($host)] = ['delayMs' => $delayMs, 'maxInFlight' => max(1, $maxInFlight)];
    }

    public function clearHostLimits(string $host): void
    {
        unset($this->limits[strtolower($host)]);
    }

    public function tryAcquire(string $host): bool
    {
        $host = strtolower($host);
        ['delayMs' => $delayMs, 'maxInFlight' => $maxInFlight] = $this->limitsFor($host);

        if (($this->inFlight[$host] ?? 0) >= $maxInFlight) {
            return false;
        }

        if ($delayMs > 0 && ($this->clock)() < ($this->nextAllowedAt[$host] ?? 0.0)) {
            return false;
        }

        $this->inFlight[$host] = ($this->inFlight[$host] ?? 0) + 1;

        return true;
    }

    public function release(string $host): void
    {
        $host = strtolower($host);
        $inFlight = ($this->inFlight[$host] ?? 0) - 1;

        if ($inFlight > 0) {
            $this->inFlight[$host] = $inFlight;
        } else {
            unset($this->inFlight[$host]);
        }

        $this->nextAllowedAt[$host] = ($this->clock)() + $this->limitsFor($host)['delayMs'] / 1000;
    }

    public function waitTimeFor(string $host): float
    {
        $remaining = ($this->nextAllowedAt[strtolower($host)] ?? 0.0) - ($this->clock)();

        return $remaining > 0 ? $remaining : 0.0;
    }

    public function reset(): void
    {
        $this->nextAllowedAt = [];
        $this->inFlight = [];
    }

    /**
     * @return array{delayMs: int, maxInFlight: int}
     */
    private function limitsFor(string $host): array
    {
        return $this->limits[$host] ?? ['delayMs' => $this->defaultDelayMs, 'maxInFlight' => $this->defaultMaxInFlight];
    }
}
