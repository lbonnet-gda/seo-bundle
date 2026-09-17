<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Storage;

use Lbonnet\SeoBundle\Model\Issue;
use Lbonnet\SeoBundle\Model\IssueType;
use Lbonnet\SeoBundle\Model\Module;
use Lbonnet\SeoBundle\Model\PageReport;
use Lbonnet\SeoBundle\Model\SeoReport;
use Lbonnet\SeoBundle\Storage\JsonFileReportStorage;
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
