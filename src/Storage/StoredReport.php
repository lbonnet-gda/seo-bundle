<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Storage;

use DateTimeImmutable;
use Lbonnet\SeoBundle\Model\SeoReport;

final class StoredReport
{
    public function __construct(
        public readonly SeoReport $report,
        public readonly DateTimeImmutable $createdAt,
        public readonly string $location,
    ) {
    }
}
