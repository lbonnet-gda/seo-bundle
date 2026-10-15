<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Storage;

/**
 * Reading is kept apart from ReportStorageInterface: a storage that only writes stays a valid storage.
 */
interface ReportReaderInterface
{
    /**
     * The most recent report stored for that start URL, or null when nothing is stored for it.
     */
    public function latest(string $startUrl): ?StoredReport;

    /**
     * @param int $limit how many reports to return at most, 0 for every one still kept
     *
     * @return list<StoredReport> newest first
     */
    public function history(string $startUrl, int $limit = 0): array;
}
