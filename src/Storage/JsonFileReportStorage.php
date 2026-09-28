<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Storage;

use DateTimeInterface;
use JsonException;
use Lbonnet\SeoBundle\Model\Issue;
use Lbonnet\SeoBundle\Model\Module;
use Lbonnet\SeoBundle\Model\PageReport;
use Lbonnet\SeoBundle\Model\SeoReport;
use RuntimeException;
use stdClass;

final class JsonFileReportStorage implements ReportStorageInterface
{
    public function __construct(
        private readonly string $storageDirectory,
        private readonly int $maxReports = 0,
    ) {
    }

    public function save(SeoReport $report): string
    {
        if (
            !is_dir($this->storageDirectory)
            && !@mkdir($this->storageDirectory, 0775, true)
            && !is_dir($this->storageDirectory)
        ) {
            throw new RuntimeException(
                sprintf('Could not create report storage directory "%s".', $this->storageDirectory)
            );
        }

        $filename = sprintf(
            'report-%s-%s-%s.json',
            date('Y-m-d_H-i-s'),
            self::hash($report->startUrl),
            uniqid('', true)
        );

        $filePath = rtrim($this->storageDirectory, '/').'/'.$filename;

        $data = [
            'startUrl' => $report->startUrl,
            'createdAt' => date(DateTimeInterface::ATOM),
            'modules' => array_map(static fn(Module $module): string => $module->value, $report->modules),
            'pagesRead' => $report->pagesRead,
            'urlsChecked' => $report->urlsChecked,
            'totalDuration' => $report->totalDuration,
            'truncated' => $report->truncated,
            'blockedByRobotsTxt' => $report->blockedByRobotsTxt,
            'startUrlStatusCode' => $report->startUrlStatusCode,
            'urlsDisallowedByRobotsTxt' => $report->urlsDisallowedByRobotsTxt,
            'issuesCount' => $report->getIssuesCount(),
            'issuesBySeverity' => $report->getIssuesCountBySeverity(),
            'issuesByModule' => $report->getIssuesCountByModule(),
            'pages' => array_map(static fn(PageReport $page): array => [
                'url' => $page->url,
                'statusCode' => $page->statusCode,
                'depth' => $page->depth,
                'canonical' => $page->canonical,
                'issues' => array_map(static fn(Issue $issue): array => [
                    'type' => $issue->type->value,
                    'module' => $issue->module()->value,
                    'severity' => $issue->severity()->value,
                    'message' => $issue->message,
                    'context' => $issue->context === [] ? new stdClass() : $issue->context,
                ], $page->issues),
            ], $report->pages),
        ];

        try {
            $json = json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            throw new RuntimeException(
                sprintf('Could not encode the report for "%s": %s', $report->startUrl, $e->getMessage()),
                previous: $e,
            );
        }

        $written = file_put_contents($filePath, $json);

        if ($written === false) {
            throw new RuntimeException(sprintf('Could not write report file "%s".', $filePath));
        }

        $this->rotate($report->startUrl);

        return $filePath;
    }

    private function rotate(string $startUrl): void
    {
        if ($this->maxReports <= 0) {
            return;
        }

        $files = glob(
            sprintf(
                '%s/report-*-%s-*.json',
                rtrim($this->storageDirectory, '/'),
                self::hash($startUrl)
            )
        ) ?: [];

        sort($files);

        $excess = count($files) - $this->maxReports;
        for ($i = 0; $i < $excess; $i++) {
            @unlink($files[$i]);
        }
    }

    private static function hash(string $startUrl): string
    {
        return substr(md5($startUrl), 0, 8);
    }
}
