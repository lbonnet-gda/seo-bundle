<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Model;

final class HreflangLink
{
    public const X_DEFAULT = 'x-default';

    /**
     * @param string $hreflang the hreflang value as authored
     * @param string $href the href as authored, not resolved
     */
    public function __construct(
        public readonly string $hreflang,
        public readonly string $href,
    ) {
    }

    public function isXDefault(): bool
    {
        return strcasecmp($this->hreflang, self::X_DEFAULT) === 0;
    }
}
