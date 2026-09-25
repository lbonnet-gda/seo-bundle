<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module\Links\Model;

final class CheckResult
{
    public function __construct(
        public readonly string $url,
        public readonly ?int $statusCode = null,
        public readonly float $duration = 0.0,
        public readonly ?string $errorMessage = null,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $contentType = null,
        public readonly bool $likelyBlocked = false,
        public readonly ?BotProvider $blockedBy = null,
    ) {
    }

    public function isReachable(): bool
    {
        return $this->statusCode !== null
            && $this->statusCode >= 200
            && $this->statusCode < 400;
    }

    public function isBroken(): bool
    {
        return !$this->isReachable();
    }
}
