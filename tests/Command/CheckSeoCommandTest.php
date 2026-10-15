<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Command;

use DateTimeImmutable;
use Lbonnet\SeoBundle\Audit\SeoAuditorInterface;
use Lbonnet\SeoBundle\Command\CheckSeoCommand;
use Lbonnet\SeoBundle\Model\Issue;
use Lbonnet\SeoBundle\Model\IssueType;
use Lbonnet\SeoBundle\Model\Module;
use Lbonnet\SeoBundle\Model\PageReport;
use Lbonnet\SeoBundle\Model\SeoReport;
use Lbonnet\SeoBundle\Storage\ReportReaderInterface;
use Lbonnet\SeoBundle\Storage\StoredReport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;

final class CheckSeoCommandTest extends TestCase
{
    public function testItFailsWhenNoUrlIsProvided(): void
    {
        $tester = new CommandTester(new CheckSeoCommand($this->createMock(SeoAuditorInterface::class)));

        $this->assertSame(Command::INVALID, $tester->execute([]));
        $this->assertStringContainsString('No URL provided', $tester->getDisplay());
    }

    public function testItRejectsAnUnknownFailOnValue(): void
    {
        $tester = $this->testerAuditingNothing();

        $this->assertSame(Command::INVALID, $tester->execute(['--fail-on' => 'critical']));
        $this->assertStringContainsString('Invalid --fail-on value', $tester->getDisplay());
    }

    public function testItRejectsAnInvalidExcludePattern(): void
    {
        $tester = $this->testerAuditingNothing();

        $this->assertSame(Command::INVALID, $tester->execute(['--exclude' => ['#/admin#', '/oops']]));
        $this->assertStringContainsString('Invalid --exclude pattern(s): /oops', $tester->getDisplay());
    }

    public function testItRejectsAnUnknownModule(): void
    {
        $tester = $this->testerAuditingNothing();

        $this->assertSame(Command::INVALID, $tester->execute(['--only' => 'links,sitemaps']));
        $this->assertStringContainsString('Invalid --only module "sitemaps"', $tester->getDisplay());
    }

    public function testItPassesTheCrawlOptionsToTheAuditor(): void
    {
        $auditor = $this->createMock(SeoAuditorInterface::class);
        $auditor->expects($this->once())
            ->method('audit')
            ->with(
                'https://example.com/blog',
                2,
                25,
                ['#/preview#'],
                [Module::Links, Module::OnPage],
                false,
                $this->callback(static fn(mixed $callback): bool => is_callable($callback)),
            )
            ->willReturn(new SeoReport('https://example.com/blog'));

        $tester = new CommandTester(new CheckSeoCommand($auditor));

        $this->assertSame(Command::SUCCESS, $tester->execute([
            'url' => 'https://example.com/blog',
            '--max-depth' => '2',
            '--max-pages' => '25',
            '--exclude' => ['#/preview#'],
            '--only' => 'links, on_page',
            '--no-external' => true,
        ]));
    }

    public function testItSucceedsWhenNothingIsFound(): void
    {
        $tester = $this->tester(new SeoReport('https://example.com', pagesRead: 3, urlsChecked: 12));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('All clear! Read 3 page(s) and checked 12 URL(s)', $tester->getDisplay());
    }

    public function testItFailsOnAnErrorLevelIssue(): void
    {
        $tester = $this->tester($this->reportWith(IssueType::BrokenInternalLink));

        $this->assertSame(Command::FAILURE, $tester->execute([]));

        $display = $tester->getDisplay();
        $this->assertStringContainsString('broken_internal_link', $display);
        $this->assertStringContainsString('1 error(s)', $display);
    }

    public function testAWarningDoesNotBreakTheBuildByDefault(): void
    {
        $tester = $this->tester($this->reportWith(IssueType::TitleTooLong));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();
        $this->assertStringContainsString('title_too_long', $display);
        $this->assertStringContainsString('No issue of severity "error" or above', $display);
    }

    public function testTheThresholdCanBeLoweredFromTheCommandLine(): void
    {
        $tester = $this->tester($this->reportWith(IssueType::TitleTooLong));

        $this->assertSame(Command::FAILURE, $tester->execute(['--fail-on' => 'warning']));
    }

    public function testItFailsWhenNothingCouldBeAudited(): void
    {
        $tester = $this->tester(new SeoReport('https://example.com', startUrlStatusCode: Response::HTTP_NOT_FOUND));

        $this->assertSame(Command::FAILURE, $tester->execute([]));

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Nothing could be audited', $display);
        $this->assertStringContainsString('answers 404', $display);
        $this->assertStringNotContainsString('All clear', $display);
    }

    public function testItSaysWhenTheSiteCouldNotBeReachedAtAll(): void
    {
        $tester = $this->tester(new SeoReport('https://example.com', startUrlStatusCode: 0));

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('could not be reached', $tester->getDisplay());
    }

    public function testItWarnsAboutTheUrlsRobotsTxtKeepsOut(): void
    {
        $tester = $this->tester(new SeoReport('https://example.com', pagesRead: 1, urlsDisallowedByRobotsTxt: 12));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();
        $this->assertStringContainsString('12 internal URL(s) were left out', $display);
        $this->assertStringContainsString('respect_robots_txt', $display);
    }

    public function testItWarnsAboutTheUrlsTheDepthLimitLeavesOut(): void
    {
        $tester = $this->tester(new SeoReport('https://example.com', pagesRead: 1, urlsBeyondMaxDepth: 7));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();
        $this->assertStringContainsString('7 internal URL(s) were linked from pages at the max_depth', $display);
        $this->assertStringContainsString('--max-depth', $display);
    }

    public function testItWarnsAboutAnIncompleteCrawl(): void
    {
        $tester = $this->tester(
            new SeoReport('https://example.com', pagesRead: 25, truncated: true, blockedByRobotsTxt: true),
        );

        $this->assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();
        $this->assertStringContainsString('answers a server error', $display);
        $this->assertStringContainsString('Stopped after 25 page(s)', $display);
    }

    public function testItReportsWhatChangedSinceThePreviousAudit(): void
    {
        $tester = $this->tester(
            $this->reportWith(IssueType::BrokenInternalLink),
            previous: $this->reportWith(IssueType::MissingTitle),
        );

        $this->assertSame(Command::FAILURE, $tester->execute([]));

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Changes since the previous audit (2026-01-01 08:00)', $display);
        $this->assertStringContainsString('1 appeared, 1 fixed, 0 unchanged', $display);
        $this->assertStringContainsString('broken_internal_link', $display);
        $this->assertStringContainsString('missing_title', $display);
    }

    public function testItSaysWhenNothingChangedSinceThePreviousAudit(): void
    {
        $report = $this->reportWith(IssueType::BrokenInternalLink);
        $tester = $this->tester($report, previous: $report);

        $this->assertSame(Command::FAILURE, $tester->execute([]));

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Nothing changed since the previous audit', $display);
        $this->assertStringContainsString('the same 1 issue(s)', $display);
    }

    public function testAnIssueOnAPageThisAuditDidNotReadIsNotReportedAsFixed(): void
    {
        $report = $this->reportWith(IssueType::BrokenInternalLink);
        $tester = $this->tester(
            $report,
            previous: new SeoReport(
            startUrl: 'https://example.com',
            pages: [
                ...$report->pages,
                new PageReport(
                    'https://example.com/gone',
                    Response::HTTP_OK,
                    [new Issue(IssueType::MissingTitle, 'No title.')],
                ),
            ],
        )
        );

        $this->assertSame(Command::FAILURE, $tester->execute([]));

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Nothing changed since the previous audit', $display);
        $this->assertStringContainsString('1 issue(s) sat on 1 page(s) this audit did not read', $display);
        $this->assertStringNotContainsString('Changes since the previous audit', $display);
    }

    public function testItSaysNothingAboutChangesWithoutAPreviousAudit(): void
    {
        $tester = $this->tester($this->reportWith(IssueType::BrokenInternalLink));

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringNotContainsString('previous audit', $tester->getDisplay());
    }

    public function testTheChangesAreReportedEvenWhenTheAuditFoundNothing(): void
    {
        $tester = $this->tester(
            new SeoReport(
                startUrl: 'https://example.com',
                pages: [new PageReport('https://example.com', Response::HTTP_OK)],
                pagesRead: 1,
            ),
            previous: $this->reportWith(IssueType::BrokenInternalLink),
        );

        $this->assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();
        $this->assertStringContainsString('0 appeared, 1 fixed', $display);
        $this->assertStringContainsString('All clear!', $display);
    }

    private function reportWith(IssueType $type): SeoReport
    {
        return new SeoReport(
            startUrl: 'https://example.com',
            pages: [new PageReport('https://example.com', Response::HTTP_OK, [new Issue($type, 'Something to fix.')])],
            pagesRead: 1,
            totalDuration: 0.15,
        );
    }

    private function testerAuditingNothing(): CommandTester
    {
        $auditor = $this->createMock(SeoAuditorInterface::class);
        $auditor->expects($this->never())->method('audit');

        return new CommandTester(new CheckSeoCommand($auditor, defaultBaseUrl: 'https://example.com'));
    }

    private function tester(SeoReport $report, ?SeoReport $previous = null): CommandTester
    {
        $auditor = $this->createMock(SeoAuditorInterface::class);
        $auditor->method('audit')->willReturn($report);

        $reader = null;

        if ($previous !== null) {
            $reader = $this->createMock(ReportReaderInterface::class);
            $reader->method('latest')->willReturn(
                new StoredReport(
                    $previous,
                    new DateTimeImmutable('2026-01-01 08:00:00'),
                    '/var/seo/report-2026-01-01.json',
                )
            );
        }

        return new CommandTester(
            new CheckSeoCommand(
                $auditor,
                defaultBaseUrl: 'https://example.com',
                reportReader: $reader,
            )
        );
    }
}
