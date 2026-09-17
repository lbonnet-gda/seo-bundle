<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\EventListener;

use Lbonnet\SeoBundle\Event\SeoAuditCompletedEvent;
use Lbonnet\SeoBundle\EventListener\StoreReportListener;
use Lbonnet\SeoBundle\Model\SeoReport;
use Lbonnet\SeoBundle\Storage\ReportStorageInterface;
use PHPUnit\Framework\TestCase;
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

        (new StoreReportListener($storage))(new SeoAuditCompletedEvent($report));
    }

    public function testItDoesNothingWithoutStorage(): void
    {
        $this->expectNotToPerformAssertions();

        (new StoreReportListener())(new SeoAuditCompletedEvent(new SeoReport('https://example.com/')));
    }

    public function testAStorageFailureDoesNotBubbleUp(): void
    {
        $storage = $this->createMock(ReportStorageInterface::class);
        $storage->method('save')->willThrowException(new RuntimeException('Disk full'));

        (new StoreReportListener($storage))(new SeoAuditCompletedEvent(new SeoReport('https://example.com/')));

        $this->addToAssertionCount(1);
    }
}
