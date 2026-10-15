<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Storage;

use DateTimeInterface;
use Lbonnet\SeoBundle\Model\Issue;
use Lbonnet\SeoBundle\Model\IssueType;
use Lbonnet\SeoBundle\Model\Module;
use Lbonnet\SeoBundle\Model\PageReport;
use Lbonnet\SeoBundle\Model\SeoReport;
use Lbonnet\SeoBundle\Storage\JsonFileReportStorage;
use Lbonnet\SeoBundle\Storage\StoredReport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class JsonFileReportStorageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/seo-'.uniqid('', true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*.json') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    public function testSaveWritesTheReportAsJson(): void
    {
        $path = (new JsonFileReportStorage($this->directory))->save($this->report());

        $this->assertFileExists($path);

        $json = (string)file_get_contents($path);
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertSame('https://example.com/', $decoded['startUrl']);
        $this->assertSame(['links', 'technical'], $decoded['modules']);
        $this->assertSame(1, $decoded['pagesRead']);
        $this->assertSame(3, $decoded['urlsChecked']);
        $this->assertFalse($decoded['truncated']);
        $this->assertFalse($decoded['blockedByRobotsTxt']);
        $this->assertNull($decoded['startUrlStatusCode']);
        $this->assertSame(0, $decoded['urlsDisallowedByRobotsTxt']);
        $this->assertSame(2, $decoded['issuesCount']);
        $this->assertSame(['error' => 2, 'warning' => 0, 'notice' => 0], $decoded['issuesBySeverity']);
        $this->assertSame(['links' => 1, 'technical' => 1], $decoded['issuesByModule']);

        $page = $decoded['pages'][0];
        $this->assertSame(Response::HTTP_OK, $page['statusCode']);
        $this->assertSame(0, $page['depth']);
        $this->assertSame('https://example.com/canonical', $page['canonical']);
        $this->assertSame(
            [
                'type' => 'broken_internal_link',
                'module' => 'links',
                'severity' => 'error',
                'message' => 'This page links to a missing page.',
                'context' => ['target' => 'https://example.com/gone', 'statusCode' => Response::HTTP_NOT_FOUND],
            ],
            $page['issues'][0],
        );
        // An issue without context still serializes it as an object, not as an empty list.
        $this->assertStringContainsString('"context": {}', $json);
    }

    public function testInvalidUtf8InAPageDoesNotCostTheWholeReport(): void
    {
        $report = new SeoReport(
            startUrl: 'https://example.com/',
            pages: [
                new PageReport(
                    url: "https://example.com/caf\xE9",
                    statusCode: Response::HTTP_OK,
                    issues: [new Issue(IssueType::MissingTitle, "The page \xE9 has no <title>.")],
                ),
            ],
        );

        $path = (new JsonFileReportStorage($this->directory))->save($report);

        $decoded = json_decode((string)file_get_contents($path), true);

        $this->assertIsArray($decoded);
        // The bad byte becomes U+FFFD instead of silently truncating the file to nothing.
        $this->assertStringContainsString("\u{FFFD}", $decoded['pages'][0]['issues'][0]['message']);
        $this->assertSame('missing_title', $decoded['pages'][0]['issues'][0]['type']);
    }

    public function testRotationKeepsOnlyTheMostRecentReports(): void
    {
        $storage = new JsonFileReportStorage($this->directory, maxReports: 2);

        $storage->save($this->report());
        $storage->save($this->report());
        $storage->save($this->report());

        $this->assertCount(2, glob($this->directory.'/*.json') ?: []);
    }

    public function testRotationIsDisabledWithZero(): void
    {
        $storage = new JsonFileReportStorage($this->directory, maxReports: 0);

        $storage->save($this->report());
        $storage->save($this->report());

        $this->assertCount(2, glob($this->directory.'/*.json') ?: []);
    }

    public function testLatestReadsBackWhatSaveWrote(): void
    {
        $storage = new JsonFileReportStorage($this->directory);
        $path = $storage->save($this->report());

        $stored = $storage->latest('https://example.com/');

        $this->assertNotNull($stored);
        $this->assertSame($path, $stored->location);
        $this->assertSame(date('Y-m-d'), $stored->createdAt->format('Y-m-d'));

        $report = $stored->report;
        $this->assertSame('https://example.com/', $report->startUrl);
        $this->assertSame([Module::Links, Module::Technical], $report->modules);
        $this->assertSame(1, $report->pagesRead);
        $this->assertSame(3, $report->urlsChecked);
        $this->assertSame(0.42, $report->totalDuration);
        $this->assertFalse($report->truncated);
        $this->assertNull($report->startUrlStatusCode);
        // Counted from the pages that were read back, not read from the file.
        $this->assertSame(2, $report->getIssuesCount());

        $page = $report->pages[0];
        $this->assertSame('https://example.com/', $page->url);
        $this->assertSame(Response::HTTP_OK, $page->statusCode);
        $this->assertSame(0, $page->depth);
        $this->assertSame('https://example.com/canonical', $page->canonical);
        $this->assertSame(IssueType::BrokenInternalLink, $page->issues[0]->type);
        $this->assertSame('This page links to a missing page.', $page->issues[0]->message);
        $this->assertSame(
            ['target' => 'https://example.com/gone', 'statusCode' => Response::HTTP_NOT_FOUND],
            $page->issues[0]->context,
        );
        $this->assertSame([], $page->issues[1]->context);
    }

    public function testLatestFindsTheSiteWhenTheStartUrlIsWrittenDifferently(): void
    {
        $storage = new JsonFileReportStorage($this->directory);
        $storage->save($this->report());

        $this->assertNotNull($storage->latest('https://example.com'));
        $this->assertNotNull($storage->latest('http://example.com/'));
    }

    public function testLatestIgnoresReportsOfAnotherSite(): void
    {
        $storage = new JsonFileReportStorage($this->directory);
        $storage->save($this->report());

        $this->assertNull($storage->latest('https://other.example/'));
    }

    public function testLatestReturnsNullWhenNothingIsStored(): void
    {
        $this->assertNull((new JsonFileReportStorage($this->directory))->latest('https://example.com/'));
        $this->assertSame([], (new JsonFileReportStorage($this->directory))->history('https://example.com/'));
    }

    public function testLatestSkipsAFileItCannotReadAsAReport(): void
    {
        $this->writeReport('report-2026-01-01_00-00-00-%s-a.json', ['pagesRead' => 7]);
        $this->write(sprintf('report-2026-01-02_00-00-00-%s-b.json', self::hash()), '{"startUrl": "https://ex');

        $stored = (new JsonFileReportStorage($this->directory))->latest('https://example.com/');

        $this->assertNotNull($stored);
        $this->assertSame(7, $stored->report->pagesRead);
    }

    public function testAnIssueWhoseCheckNoLongerExistsIsDropped(): void
    {
        $this->writeReport('report-2026-01-01_00-00-00-%s-a.json', [
            'pages' => [
                [
                    'url' => 'https://example.com/',
                    'statusCode' => Response::HTTP_OK,
                    'issues' => [
                        ['type' => 'a_check_that_went_away', 'message' => 'Gone.'],
                        ['type' => 'missing_title', 'message' => 'The page has no <title>.'],
                    ],
                ],
            ],
        ]);

        $stored = (new JsonFileReportStorage($this->directory))->latest('https://example.com/');

        $this->assertNotNull($stored);
        $this->assertCount(1, $stored->report->pages[0]->issues);
        $this->assertSame(IssueType::MissingTitle, $stored->report->pages[0]->issues[0]->type);
    }

    public function testHistoryReturnsTheStoredReportsNewestFirst(): void
    {
        $this->writeReport('report-2026-01-01_00-00-00-%s-a.json', ['pagesRead' => 1]);
        $this->writeReport('report-2026-01-03_00-00-00-%s-c.json', ['pagesRead' => 3]);
        $this->writeReport('report-2026-01-02_00-00-00-%s-b.json', ['pagesRead' => 2]);

        $history = (new JsonFileReportStorage($this->directory))->history('https://example.com/');

        $this->assertSame([3, 2, 1],
            array_map(
                static fn(StoredReport $stored): int => $stored->report->pagesRead,
                $history,
            ));
    }

    public function testHistoryStopsAtTheLimit(): void
    {
        $this->writeReport('report-2026-01-01_00-00-00-%s-a.json', ['pagesRead' => 1]);
        $this->writeReport('report-2026-01-02_00-00-00-%s-b.json', ['pagesRead' => 2]);

        $history = (new JsonFileReportStorage($this->directory))->history('https://example.com/', limit: 1);

        $this->assertCount(1, $history);
        $this->assertSame(2, $history[0]->report->pagesRead);
    }

    /**
     * @param array<string, mixed> $data merged over a minimal report for https://example.com/
     */
    private function writeReport(string $filename, array $data): void
    {
        $this->write(
            sprintf($filename, self::hash()),
            (string)json_encode([
                'startUrl' => 'https://example.com/',
                'createdAt' => date(DateTimeInterface::ATOM),
                'pages' => [],
                ...$data,
            ]),
        );
    }

    private function write(string $filename, string $contents): void
    {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }

        file_put_contents($this->directory.'/'.$filename, $contents);
    }

    private static function hash(): string
    {
        return substr(md5('https://example.com/'), 0, 8);
    }

    private function report(): SeoReport
    {
        return new SeoReport(
            startUrl: 'https://example.com/',
            pages: [
                new PageReport(
                    url: 'https://example.com/',
                    statusCode: Response::HTTP_OK,
                    issues: [
                        new Issue(
                            IssueType::BrokenInternalLink,
                            'This page links to a missing page.',
                            ['target' => 'https://example.com/gone', 'statusCode' => Response::HTTP_NOT_FOUND],
                        ),
                        new Issue(IssueType::CanonicalMultiple, 'Two canonicals.'),
                    ],
                    depth: 0,
                    canonical: 'https://example.com/canonical',
                ),
            ],
            modules: [Module::Links, Module::Technical],
            pagesRead: 1,
            urlsChecked: 3,
            totalDuration: 0.42,
        );
    }
}
