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
}
