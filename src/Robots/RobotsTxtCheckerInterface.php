<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Robots;

interface RobotsTxtCheckerInterface
{
    /**
     * Whether the given URL may be crawled according to its host's robots.txt. Nothing may be crawled on a host
     * whose robots.txt answers a server error (see isSiteBlocked()).
     */
    public function isAllowed(string $url): bool;

    /**
     * Whether the URL's host is off-limits as a whole because its robots.txt answers 5xx or 429, or cannot be
     * fetched at all: Google stops crawling such a site, and so should we.
     */
    public function isSiteBlocked(string $url): bool;

    /**
     * Returns the Crawl-delay (in seconds) the URL's host requests for our user agent via
     * robots.txt, or null if none is specified (or robots.txt handling is disabled).
     */
    public function crawlDelay(string $url): ?float;
}
