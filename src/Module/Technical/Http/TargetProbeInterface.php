<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module\Technical\Http;

use Lbonnet\SeoBundle\Model\PageResponse;
use Lbonnet\SeoBundle\Robots\RobotsTxt;

interface TargetProbeInterface
{
    /**
     * Fetches a URL that was not part of the crawl without following redirects. Returns null
     * when probing is disabled, the budget is exhausted, or the request failed — in which case
     * the caller must not report anything about that URL.
     */
    public function probe(string $url): ?PageResponse;

    /**
     * Fetches the robots.txt of a host that was not part of the crawl. Returns null in the same cases as
     * probe(), and each newly fetched host spends the same budget.
     */
    public function robotsTxt(string $url): ?RobotsTxt;

    /**
     * Clears the cache and the probe budget. Called at the start of every crawl, since the
     * probe is a long-lived service in a worker process.
     */
    public function reset(): void;
}
