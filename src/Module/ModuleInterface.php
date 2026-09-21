<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module;

use Lbonnet\SeoBundle\Crawl\CrawlResult;
use Lbonnet\SeoBundle\Model\Module;
use Lbonnet\SeoBundle\Model\PageReport;

interface ModuleInterface
{
    public function module(): Module;

    /**
     * Audits what the crawl found. A module may request more URLs (external links, sitemaps...) but never crawls.
     *
     * @return list<PageReport> the issues found, grouped by the URL they concern: a crawled page, or any other URL
     */
    public function audit(CrawlResult $crawl, ModuleOptions $options): array;
}
