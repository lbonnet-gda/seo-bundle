<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module\Technical;

use Lbonnet\SeoBundle\Crawl\CrawledPage;
use Lbonnet\SeoBundle\Crawl\CrawlResult;
use Lbonnet\SeoBundle\Model\Module;
use Lbonnet\SeoBundle\Model\PageReport;
use Lbonnet\SeoBundle\Module\ModuleInterface;
use Lbonnet\SeoBundle\Module\ModuleOptions;
use Lbonnet\SeoBundle\Module\Technical\Model\CrawlContext;
use Lbonnet\SeoBundle\Module\Technical\Model\PageAudit;

final class TechnicalModule implements ModuleInterface
{
    public function __construct(
        private readonly PageAuditorInterface $pageAuditor,
        private readonly SiteAuditorInterface $siteAuditor,
    ) {
    }

    public function module(): Module
    {
        return Module::Technical;
    }

    public function audit(CrawlResult $crawl, ModuleOptions $options): array
    {
        $pages = array_map(
            fn(CrawledPage $page): PageAudit => new PageAudit(
                url: $page->url,
                statusCode: $page->response->statusCode,
                issues: $this->pageAuditor->audit($page->response, $page->signals),
                signals: $page->signals,
                depth: $page->depth,
            ),
            $crawl->pages,
        );

        return array_map(
            static fn(PageAudit $audit): PageReport => new PageReport(
                url: $audit->url,
                statusCode: $audit->statusCode,
                issues: $audit->issues,
                depth: $audit->signals !== null ? $audit->depth : null,
            ),
            $this->siteAuditor->audit(
                $pages,
                CrawlContext::fromCrawl($crawl),
                reportDeadRedirects: !$options->runs(Module::Links),
            ),
        );
    }
}
