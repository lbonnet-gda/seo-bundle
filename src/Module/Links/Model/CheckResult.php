<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module\Links\Model;

final class CheckResult
{
    public function __construct(
        public readonly string $url,
        public readonly ?int $statusCode = null,
        public readonly ?string $errorMessage = null,
        public readonly bool $likelyBlocked = false,
        public readonly ?BotProvider $blockedBy = null,
    ) {
    }

    public function isBroken(): bool
    {
        return $this->statusCode === null || $this->statusCode >= 400;
    }
}
