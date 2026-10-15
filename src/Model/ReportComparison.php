<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Model;

use DateTimeImmutable;

final class ReportComparison
{
    /**
     * @param list<IssueChange> $appeared in the order the report lists the pages they sit on
     * @param list<IssueChange> $fixed likewise, for what the previous audit found and this one no longer does
     * @param int $stayed issues found by both audits
     * @param int $pagesNoLongerAudited pages the previous audit read and this one did not
     * @param int $issuesNoLongerAudited issues the previous audit found on those pages
     */
    public function __construct(
        public readonly DateTimeImmutable $previousAuditAt,
        public readonly array $appeared = [],
        public readonly array $fixed = [],
        public readonly int $stayed = 0,
        public readonly int $pagesNoLongerAudited = 0,
        public readonly int $issuesNoLongerAudited = 0,
    ) {
    }

    public function countAppeared(): int
    {
        return self::total($this->appeared);
    }

    public function countFixed(): int
    {
        return self::total($this->fixed);
    }

    public function countPreviously(): int
    {
        return $this->countFixed() + $this->stayed + $this->issuesNoLongerAudited;
    }

    public function hasChanges(): bool
    {
        return $this->appeared !== [] || $this->fixed !== [];
    }

    /**
     * @param list<IssueChange> $changes
     */
    private static function total(array $changes): int
    {
        return array_sum(array_map(static fn(IssueChange $change): int => $change->count, $changes));
    }
}
