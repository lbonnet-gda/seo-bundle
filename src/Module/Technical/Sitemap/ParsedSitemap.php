<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module\Technical\Sitemap;

final class ParsedSitemap
{
    /**
     * @param list<string> $locations
     */
    public function __construct(
        public readonly bool $isIndex = false,
        public readonly array $locations = [],
        public readonly ?string $error = null,
        public readonly bool $tooManyEntries = false,
    ) {
    }
}
