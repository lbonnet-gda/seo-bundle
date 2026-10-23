<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Model;

use Lbonnet\SeoBundle\Model\IssueType;
use Lbonnet\SeoBundle\Model\IssueTypeCount;
use PHPUnit\Framework\TestCase;

final class IssueTypeCountTest extends TestCase
{
    public function testTheWorstSeverityComesFirst(): void
    {
        $error = new IssueTypeCount(IssueType::MissingTitle, 1, 1);
        $notice = new IssueTypeCount(IssueType::MultipleH1, 99, 99);

        $this->assertLessThan(0, $error->compareTo($notice));
        $this->assertGreaterThan(0, $notice->compareTo($error));
    }

    public function testTheMostFrequentComesFirstWithinASeverity(): void
    {
        $once = new IssueTypeCount(IssueType::MultipleH1, 1, 1);
        $twice = new IssueTypeCount(IssueType::SitemapMissing, 2, 1);

        $this->assertLessThan(0, $twice->compareTo($once));
        $this->assertGreaterThan(0, $once->compareTo($twice));
    }

    public function testNamesBreakTheTie(): void
    {
        $h1 = new IssueTypeCount(IssueType::MultipleH1, 2, 2);
        $sitemap = new IssueTypeCount(IssueType::SitemapMissing, 2, 2);

        $this->assertLessThan(0, $h1->compareTo($sitemap));
        $this->assertGreaterThan(0, $sitemap->compareTo($h1));
        $this->assertSame(0, $h1->compareTo($h1));
    }

    public function testSortingAShuffledListPutsItInReportOrder(): void
    {
        $counted = [
            new IssueTypeCount(IssueType::SitemapMissing, 2, 2),
            new IssueTypeCount(IssueType::MissingTitle, 1, 1),
            new IssueTypeCount(IssueType::MultipleH1, 2, 2),
            new IssueTypeCount(IssueType::DescriptionTooLong, 5, 5),
        ];

        usort($counted, static fn(IssueTypeCount $a, IssueTypeCount $b): int => $a->compareTo($b));

        $this->assertSame(
            ['missing_title', 'description_too_long', 'multiple_h1', 'sitemap_missing'],
            array_map(static fn(IssueTypeCount $one): string => $one->type->value, $counted),
        );
    }
}
