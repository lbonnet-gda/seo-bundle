<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Model;

use Lbonnet\SeoBundle\Model\Issue;
use Lbonnet\SeoBundle\Model\IssueType;
use Lbonnet\SeoBundle\Model\Module;
use Lbonnet\SeoBundle\Model\PageReport;
use Lbonnet\SeoBundle\Model\SeoReport;
use Lbonnet\SeoBundle\Model\Severity;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class SeoReportTest extends TestCase
{
    public function testCountsIssuesBySeverityAndModule(): void
    {
        $report = new SeoReport(
            startUrl: 'https://example.com/',
            pages: [
                new PageReport('https://example.com/', Response::HTTP_OK, [
                    new Issue(IssueType::MissingTitle, 'No title.'),
                    new Issue(IssueType::TitleTooLong, 'Long title.'),
                ]),
                new PageReport('https://example.com/a', Response::HTTP_OK, [
                    new Issue(IssueType::MultipleH1, 'Two h1.'),
                ]),
            ],
            modules: [Module::Links, Module::OnPage],
        );

        $this->assertSame(3, $report->getIssuesCount());
        $this->assertSame(2, $report->getIssuesCount(Severity::Warning));
        $this->assertSame(1, $report->getIssuesCount(Severity::Error));
        $this->assertTrue($report->hasIssues(Severity::Error));
        $this->assertSame(['error' => 1, 'warning' => 1, 'notice' => 1], $report->getIssuesCountBySeverity());
        $this->assertSame(['links' => 0, 'on_page' => 3], $report->getIssuesCountByModule());
    }

    public function testEveryIssueTypeHasASeverityAndAModule(): void
    {
        $severities = [];
        $modules = [];

        foreach (IssueType::cases() as $type) {
            $severities[$type->value] = $type->severity()->value;
            $modules[$type->module()->value] = ($modules[$type->module()->value] ?? 0) + 1;
        }

        $this->assertCount(count(IssueType::cases()), $severities);
        $this->assertSame(['links' => 3, 'on_page' => 9, 'technical' => 48], $modules);
    }
}
