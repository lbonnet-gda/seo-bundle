<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Model;

use Lbonnet\SeoBundle\Model\Severity;
use PHPUnit\Framework\TestCase;

final class SeverityTest extends TestCase
{
    public function testSeveritiesCompareWorstToLeast(): void
    {
        $this->assertGreaterThan(0, Severity::Error->compareTo(Severity::Warning));
        $this->assertGreaterThan(0, Severity::Warning->compareTo(Severity::Notice));
        $this->assertSame(0, Severity::Warning->compareTo(Severity::Warning));
        $this->assertLessThan(0, Severity::Notice->compareTo(Severity::Error));
    }

    public function testSortingOnCompareToPutsTheWorstFirst(): void
    {
        $severities = [Severity::Notice, Severity::Error, Severity::Warning];

        usort($severities, static fn(Severity $a, Severity $b): int => $b->compareTo($a));

        $this->assertSame([Severity::Error, Severity::Warning, Severity::Notice], $severities);
    }

    public function testASeverityIsAtLeastItselfAndAnythingBelowIt(): void
    {
        $this->assertTrue(Severity::Error->isAtLeast(Severity::Error));
        $this->assertTrue(Severity::Error->isAtLeast(Severity::Warning));
        $this->assertTrue(Severity::Error->isAtLeast(Severity::Notice));

        $this->assertTrue(Severity::Warning->isAtLeast(Severity::Notice));
        $this->assertFalse(Severity::Warning->isAtLeast(Severity::Error));

        $this->assertFalse(Severity::Notice->isAtLeast(Severity::Warning));
        $this->assertFalse(Severity::Notice->isAtLeast(Severity::Error));
    }
}
