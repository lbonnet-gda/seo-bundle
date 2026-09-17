<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Audit;

use Lbonnet\SeoBundle\Model\Module;
use Lbonnet\SeoBundle\Model\SeoReport;

interface SeoAuditorInterface
{
    /**
     * Crawls a site once from its starting URL and runs the enabled modules over what the crawl found.
     *
     * Every null argument falls back to the bundle configuration.
     *
     * @param string $startUrl
     * @param int|null $maxDepth
     * @param int|null $maxPages
     * @param list<string> $excludePatterns additional exclusion regex patterns
     * @param list<Module>|null $modules the modules to run, among those enabled in the configuration
     * @param bool|null $checkExternal
     * @param (callable(string $currentUrl, int $pagesRead): void)|null $progressCallback
     * @return SeoReport
     */
    public function audit(
        string $startUrl,
        ?int $maxDepth = null,
        ?int $maxPages = null,
        array $excludePatterns = [],
        ?array $modules = null,
        ?bool $checkExternal = null,
        ?callable $progressCallback = null,
    ): SeoReport;
}
