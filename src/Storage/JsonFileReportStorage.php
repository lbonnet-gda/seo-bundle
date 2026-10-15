<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Storage;

use DateTimeInterface;
use JsonException;
use Lbonnet\SeoBundle\Model\SeoReport;
use Lbonnet\SeoBundle\Url\UrlResolver;
use RuntimeException;

final class JsonFileReportStorage implements ReportStorageInterface, ReportReaderInterface
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

        try {
            $json = json_encode(
                ReportJson::encode($report, date(DateTimeInterface::ATOM)),
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

    public function latest(string $startUrl): ?StoredReport
    {
        foreach ($this->storedFiles($startUrl) as $path) {
            $stored = self::read($path, $startUrl);

            if ($stored !== null) {
                return $stored;
            }
        }

        return null;
    }

    public function history(string $startUrl, int $limit = 0): array
    {
        $stored = [];

        foreach ($this->storedFiles($startUrl) as $path) {
            $report = self::read($path, $startUrl);

            if ($report === null) {
                continue;
            }

            $stored[] = $report;

            if ($limit > 0 && count($stored) >= $limit) {
                break;
            }
        }

        return $stored;
    }

    /**
     * @return list<string> paths of the candidate report files, newest first
     */
    private function storedFiles(string $startUrl): array
    {
        $directory = rtrim($this->storageDirectory, '/');
        $files = glob(sprintf('%s/report-*-%s-*.json', $directory, self::hash($startUrl))) ?: [];

        if ($files === []) {
            $files = glob($directory.'/report-*.json') ?: [];
        }

        rsort($files);

        return $files;
    }

    private static function read(string $path, string $startUrl): ?StoredReport
    {
        $json = @file_get_contents($path);

        if ($json === false) {
            return null;
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        $report = ReportJson::decode($data);
        $createdAt = ReportJson::createdAt($data);

        if (
            $report === null
            || $createdAt === null
            || UrlResolver::dedupKey($report->startUrl) !== UrlResolver::dedupKey($startUrl)
        ) {
            return null;
        }

        return new StoredReport($report, $createdAt, $path);
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
