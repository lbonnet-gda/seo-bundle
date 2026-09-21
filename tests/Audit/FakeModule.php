<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Audit;

use Lbonnet\SeoBundle\Crawl\CrawlResult;
use Lbonnet\SeoBundle\Model\Module;
use Lbonnet\SeoBundle\Model\PageReport;
use Lbonnet\SeoBundle\Module\ModuleInterface;
use Lbonnet\SeoBundle\Module\ModuleOptions;

final class FakeModule implements ModuleInterface
{
    public ?CrawlResult $crawl = null;

    public ?ModuleOptions $options = null;

    /**
     * @param list<PageReport> $entries
     */
    public function __construct(
        private readonly Module $module,
        private readonly array $entries = [],
    ) {
    }

    public function module(): Module
    {
        return $this->module;
    }

    public function audit(CrawlResult $crawl, ModuleOptions $options): array
    {
        $this->crawl = $crawl;
        $this->options = $options;

        return $this->entries;
    }
}
