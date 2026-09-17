<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Robots;

interface RobotsTxtProviderInterface
{
    /**
     * Returns the robots.txt of the URL's host, fetched on first use whether the crawler itself honors it, or null
     * when the URL has no host.
     */
    public function robotsTxt(string $url): ?RobotsTxt;
}
