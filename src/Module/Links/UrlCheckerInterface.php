<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module\Links;

use Lbonnet\SeoBundle\Module\Links\Model\CheckResult;

interface UrlCheckerInterface
{
    /**
     * Checks the status of a given URL.
     *
     * @param string $url The URL to test
     * @param int|null $timeout Optional timeout override in seconds
     */
    public function check(string $url, ?int $timeout = null): CheckResult;

    /**
     * Checks several URLs, several at a time, without ever holding more than one request per host.
     *
     * @param list<string> $urls
     *
     * @return array<string, CheckResult> keyed by URL, in the order they were given
     */
    public function checkMany(array $urls, ?int $timeout = null): array;
}
