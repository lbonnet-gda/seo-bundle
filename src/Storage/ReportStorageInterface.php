<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Storage;

use Lbonnet\SeoBundle\Model\SeoReport;

interface ReportStorageInterface
{
    /**
     * Persists the report and returns its identifier or save path.
     */
    public function save(SeoReport $report): string;
}
