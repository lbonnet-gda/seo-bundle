<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Model;

final class PageLink
{
    /**
     * @param string $url the absolute target, without fragment
     * @param bool $isExternal the target is on another host than the page linking to it
     */
    public function __construct(
        public readonly string $url,
        public readonly string $anchorText = '',
        public readonly bool $isExternal = false,
    ) {
    }
}
