<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Model;

enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Notice = 'notice';

    public function isAtLeast(self $threshold): bool
    {
        return $this->weight() >= $threshold->weight();
    }

    private function weight(): int
    {
        return match ($this) {
            self::Notice => 0,
            self::Warning => 1,
            self::Error => 2,
        };
    }
}
