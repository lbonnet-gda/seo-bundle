<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module\Technical;

use Lbonnet\SeoBundle\Module\Technical\Model\CrawlContext;
use Lbonnet\SeoBundle\Module\Technical\Model\PageAudit;

interface UrlVariantAuditorInterface
{
    /**
     * Requests other spellings of the crawled URLs (http://, www or apex host, index file, trailing slash,
     * letter case) and reports those that neither redirect nor declare the crawled URL as canonical.
     *
     * @param list<PageAudit> $pages the crawled pages
     *
     * @return list<PageAudit> one entry per variant URL with issues
     */
    public function audit(array $pages, CrawlContext $context): array;
}
