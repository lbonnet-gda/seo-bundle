<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\EventListener;

use Lbonnet\SeoBundle\Event\SeoAuditCompletedEvent;
use Lbonnet\SeoBundle\Storage\ReportStorageInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Throwable;

// High priority: the audit trail must be persisted before any other SeoAuditCompletedEvent
// listener (e.g., a user's notification listener) runs and potentially throws.
#[AsEventListener(priority: 100)]
final class StoreReportListener
{
    public function __construct(
        private readonly ?ReportStorageInterface $storage = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(SeoAuditCompletedEvent $event): void
    {
        if ($this->storage === null) {
            return;
        }

        try {
            $location = $this->storage->save($event->report);
            $this->logger->info(sprintf('[Seo] Report saved to %s', $location));
        } catch (Throwable $e) {
            $this->logger->error(sprintf('[Seo] Failed to save report: %s', $e->getMessage()));
        }
    }
}
