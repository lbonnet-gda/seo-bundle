<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Event;

use Lbonnet\SeoBundle\Model\SeoReport;
use Symfony\Contracts\EventDispatcher\Event;

final class SeoAuditCompletedEvent extends Event
{
    public function __construct(
        public readonly SeoReport $report,
    ) {
    }
}
