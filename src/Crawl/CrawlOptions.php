<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Crawl;

use Closure;

final class CrawlOptions
{
    /**
     * @param int $maxPages 0 for no limit
     * @param int $concurrency how many requests the crawl may have in flight at once
     * @param list<string> $excludePatterns regex patterns of URLs never requested
     * @param bool $checkLinkTargets also request, without reading them, the internal URLs linked from pages the crawl does not read: pages at max depth, and those still queued past max pages
     * @param (Closure(string $currentUrl, int $pagesRead): void)|null $progressCallback called after each page read
     */
    public function __construct(
        public readonly int $maxDepth = 3,
        public readonly int $maxPages = 500,
        public readonly int $concurrency = 1,
        public readonly array $excludePatterns = [],
        public readonly bool $checkLinkTargets = false,
        public readonly ?Closure $progressCallback = null,
    ) {
    }
}
