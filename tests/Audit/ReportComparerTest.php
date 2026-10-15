<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Audit;

use DateTimeImmutable;
use Lbonnet\SeoBundle\Audit\ReportComparer;
use Lbonnet\SeoBundle\Model\Issue;
use Lbonnet\SeoBundle\Model\IssueChange;
use Lbonnet\SeoBundle\Model\IssueType;
use Lbonnet\SeoBundle\Model\PageReport;
use Lbonnet\SeoBundle\Model\ReportComparison;
use Lbonnet\SeoBundle\Model\SeoReport;
use Lbonnet\SeoBundle\Storage\StoredReport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class ReportComparerTest extends TestCase
{
    public function testAnIssueFoundOnlyByTheNewAuditAppeared(): void
    {
        $comparison = $this->compare(
            previous: [self::page('https://example.com/', [self::missingTitle()])],
            current: [self::page('https://example.com/', [self::missingTitle(), self::brokenLink('/gone')])],
        );

        $this->assertSame(1, $comparison->countAppeared());
        $this->assertSame(0, $comparison->countFixed());
        $this->assertSame(1, $comparison->stayed);
        $this->assertTrue($comparison->hasChanges());
        $this->assertSame('https://example.com/', $comparison->appeared[0]->pageUrl);
        $this->assertSame(IssueType::BrokenInternalLink, $comparison->appeared[0]->issue->type);
        $this->assertSame('2026-01-01', $comparison->previousAuditAt->format('Y-m-d'));
    }

    public function testAnIssueTheNewAuditNoLongerFindsIsFixed(): void
    {
        $comparison = $this->compare(
            previous: [self::page('https://example.com/', [self::missingTitle(), self::brokenLink('/gone')])],
            current: [self::page('https://example.com/', [self::missingTitle()])],
        );

        $this->assertSame(0, $comparison->countAppeared());
        $this->assertSame(1, $comparison->countFixed());
        $this->assertSame(1, $comparison->stayed);
        $this->assertSame(IssueType::BrokenInternalLink, $comparison->fixed[0]->issue->type);
        // The fixed issue is reported with the target it used to point at.
        $this->assertSame('https://example.com/gone', $comparison->fixed[0]->issue->context['target']);
    }

    public function testTwoAuditsThatFoundTheSameThingsHaveNoChanges(): void
    {
        $comparison = $this->compare(
            previous: [self::page('https://example.com/', [self::missingTitle(), self::brokenLink('/gone')])],
            current: [self::page('https://example.com/', [self::missingTitle(), self::brokenLink('/gone')])],
        );

        $this->assertFalse($comparison->hasChanges());
        $this->assertSame(2, $comparison->stayed);
        $this->assertSame(0, $comparison->pagesNoLongerAudited);
    }

    public function testTheStatusCodeAndTheAnchorTextAreNotPartOfTheIssueIdentity(): void
    {
        $comparison = $this->compare(
            previous: [
                self::page('https://example.com/', [
                    self::brokenLink('/gone', Response::HTTP_NOT_FOUND, 'read more'),
                ]),
            ],
            // The same dead link, now timing out and linked from a reworded anchor.
            current: [
                self::page('https://example.com/', [
                    self::brokenLink('/gone', 0, 'here'),
                ]),
            ],
        );

        $this->assertFalse($comparison->hasChanges());
        $this->assertSame(1, $comparison->stayed);
    }

    public function testTwoBrokenLinksOnTheSamePageAreToldApartByTheirTarget(): void
    {
        $comparison = $this->compare(
            previous: [self::page('https://example.com/', [self::brokenLink('/gone'), self::brokenLink('/old')])],
            current: [self::page('https://example.com/', [self::brokenLink('/gone'), self::brokenLink('/new')])],
        );

        $this->assertSame(1, $comparison->countAppeared());
        $this->assertSame(1, $comparison->countFixed());
        $this->assertSame(1, $comparison->stayed);
        $this->assertSame('https://example.com/new', $comparison->appeared[0]->issue->context['target']);
        $this->assertSame('https://example.com/old', $comparison->fixed[0]->issue->context['target']);
    }

    public function testIssuesWithNothingToTellThemApartAreComparedByTheirNumber(): void
    {
        $comparison = $this->compare(
            previous: [self::page('https://example.com/', [self::missingTitle(), self::missingTitle()])],
            current: [
                self::page('https://example.com/', [
                    self::missingTitle(),
                    self::missingTitle(),
                    self::missingTitle(),
                ]),
            ],
        );

        $this->assertSame(1, $comparison->countAppeared());
        $this->assertSame(1, $comparison->appeared[0]->count);
        $this->assertSame(2, $comparison->stayed);
    }

    public function testEveryIssueOfANewPageAppeared(): void
    {
        $comparison = $this->compare(
            previous: [self::page('https://example.com/', [])],
            current: [
                self::page('https://example.com/', []),
                self::page('https://example.com/new', [self::missingTitle(), self::brokenLink('/gone')]),
            ],
        );

        $this->assertSame(2, $comparison->countAppeared());
        $this->assertSame('https://example.com/new', $comparison->appeared[0]->pageUrl);
    }

    public function testAPageTheNewAuditDidNotReadIsNotAPageThatWasFixed(): void
    {
        $comparison = $this->compare(
            previous: [
                self::page('https://example.com/', [self::missingTitle()]),
                self::page('https://example.com/gone', [self::missingTitle(), self::brokenLink('/nowhere')]),
            ],
            current: [self::page('https://example.com/', [self::missingTitle()])],
        );

        $this->assertFalse($comparison->hasChanges());
        $this->assertSame(0, $comparison->countFixed());
        $this->assertSame(1, $comparison->pagesNoLongerAudited);
        $this->assertSame(2, $comparison->issuesNoLongerAudited);
    }

    public function testAPageIsTheSamePageWhateverItsUrlLooksLike(): void
    {
        $comparison = $this->compare(
            previous: [self::page('http://example.com/page', [self::missingTitle()])],
            current: [self::page('https://example.com/page', [self::missingTitle()])],
        );

        $this->assertFalse($comparison->hasChanges());
        $this->assertSame(1, $comparison->stayed);
        $this->assertSame(0, $comparison->pagesNoLongerAudited);
    }

    public function testTheChangesFollowTheOrderThePagesAreReported(): void
    {
        $comparison = $this->compare(
            previous: [self::page('https://example.com/b', [])],
            current: [
                self::page('https://example.com/a', [self::missingTitle()]),
                self::page('https://example.com/b', [self::brokenLink('/gone')]),
                self::page('https://example.com/c', [self::missingTitle()]),
            ],
        );

        $this->assertSame(
            ['https://example.com/a', 'https://example.com/b', 'https://example.com/c'],
            array_map(static fn(IssueChange $change): string => $change->pageUrl, $comparison->appeared),
        );
    }

    /**
     * @param list<PageReport> $previous
     * @param list<PageReport> $current
     */
    private function compare(array $previous, array $current): ReportComparison
    {
        return (new ReportComparer())->compare(
            new SeoReport(startUrl: 'https://example.com/', pages: $current),
            new StoredReport(
                new SeoReport(startUrl: 'https://example.com/', pages: $previous),
                new DateTimeImmutable('2026-01-01 08:00:00'),
                '/var/seo/report-2026-01-01.json',
            ),
        );
    }

    /**
     * @param list<Issue> $issues
     */
    private static function page(string $url, array $issues): PageReport
    {
        return new PageReport(url: $url, statusCode: Response::HTTP_OK, issues: $issues);
    }

    private static function missingTitle(): Issue
    {
        return new Issue(IssueType::MissingTitle, 'The page has no <title>.');
    }

    private static function brokenLink(
        string $path,
        int $statusCode = Response::HTTP_NOT_FOUND,
        string $anchorText = 'read more',
    ): Issue {
        return new Issue(
            IssueType::BrokenInternalLink,
            sprintf('This page links to "%s", which answers %d.', $path, $statusCode),
            ['target' => 'https://example.com'.$path, 'statusCode' => $statusCode, 'anchorText' => $anchorText],
        );
    }
}
