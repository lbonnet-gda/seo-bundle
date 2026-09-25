<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module\OnPage;

use Lbonnet\SeoBundle\Crawl\CrawledPage;
use Lbonnet\SeoBundle\Crawl\CrawlResult;
use Lbonnet\SeoBundle\Model\Module;
use Lbonnet\SeoBundle\Model\PageReport;
use Lbonnet\SeoBundle\Module\ModuleInterface;
use Lbonnet\SeoBundle\Module\ModuleOptions;

final class OnPageModule implements ModuleInterface
{
    public function __construct(
        private readonly PageAuditor $pageAuditor,
        private readonly DuplicateContentAuditor $duplicateContentAuditor = new DuplicateContentAuditor(),
    ) {
    }

    public function module(): Module
    {
        return Module::OnPage;
    }

    public function audit(CrawlResult $crawl, ModuleOptions $options): array
    {
        $duplicates = $this->duplicateContentAuditor->audit($crawl->pages);

        return array_map(
            fn(CrawledPage $page): PageReport => new PageReport(
                url: $page->url,
                statusCode: $page->response->statusCode,
                issues: [
                    ...$this->pageAuditor->audit($page->signals),
                    ...$duplicates[$page->url] ?? [],
                ],
                depth: $page->depth,
            ),
            $crawl->pages,
        );
    }
}
