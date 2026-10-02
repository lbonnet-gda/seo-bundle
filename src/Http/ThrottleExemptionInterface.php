<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Http;

interface ThrottleExemptionInterface
{
    public function setHostDelay(?string $host, int $delayMs = 0, int $maxInFlight = 1): void;
}
