<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\EventListener;

use Lbonnet\SeoBundle\Event\SeoAuditCompletedEvent;
use Lbonnet\SeoBundle\EventListener\StoreReportListener;
use Lbonnet\SeoBundle\Model\SeoReport;
use Lbonnet\SeoBundle\Storage\ReportStorageInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class StoreReportListenerTest extends TestCase
{
    public function testItSavesTheReport(): void
    {
        $report = new SeoReport('https://example.com/');

        $storage = $this->createMock(ReportStorageInterface::class);
        $storage->expects($this->once())
            ->method('save')
            ->with($report)
            ->willReturn('/tmp/report.json');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with($this->stringContains('/tmp/report.json'));

        (new StoreReportListener($storage, $logger))(new SeoAuditCompletedEvent($report));
    }

    public function testItDoesNothingWithoutStorage(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method($this->anything());

        (new StoreReportListener(logger: $logger))(new SeoAuditCompletedEvent(new SeoReport('https://example.com/')));
    }

    public function testAStorageFailureDoesNotBubbleUp(): void
    {
        $storage = $this->createMock(ReportStorageInterface::class);
        $storage->method('save')->willThrowException(new RuntimeException('Disk full'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('Disk full'));

        (new StoreReportListener($storage, $logger))(new SeoAuditCompletedEvent(new SeoReport('https://example.com/')));
    }
}
