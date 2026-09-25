<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module\Technical;

use Lbonnet\SeoBundle\Module\Technical\Model\CrawlContext;
use Lbonnet\SeoBundle\Module\Technical\Model\PageAudit;

interface SitemapAuditorInterface
{
    /**
     * Reads the sitemaps of the crawled site, checks the URLs they list, and looks for crawled pages they miss.
     *
     * @param list<PageAudit> $pages the crawled pages
     *
     * @return list<PageAudit> one entry per sitemap, robots.txt or crawled page URL with issues
     */
    public function audit(array $pages, CrawlContext $context): array;
}
