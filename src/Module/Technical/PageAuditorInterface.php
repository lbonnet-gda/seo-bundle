<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module\Technical;

use Lbonnet\SeoBundle\Model\Issue;
use Lbonnet\SeoBundle\Model\PageResponse;
use Lbonnet\SeoBundle\Model\PageSignals;

interface PageAuditorInterface
{
    /**
     * Audits the signals of a single page, using nothing but that page's own response.
     *
     * @return list<Issue>
     */
    public function audit(PageResponse $response, PageSignals $signals): array;
}
