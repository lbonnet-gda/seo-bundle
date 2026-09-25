<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module\Technical;

use Lbonnet\SeoBundle\Module\Technical\Model\CrawlContext;
use Lbonnet\SeoBundle\Module\Technical\Model\PageAudit;

interface SiteAuditorInterface
{
    /**
     * Second pass: adds the issues that can only be seen once the whole crawl is known
     * (canonical targets, redirect chains, and who links to them), and drops the checks
     * disabled by configuration.
     *
     * @param list<PageAudit> $pages
     *
     * @return list<PageAudit>
     */
    public function audit(array $pages, CrawlContext $context): array;
}
