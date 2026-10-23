<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Model;

final class IssueTypeCount
{
    public function __construct(
        public readonly IssueType $type,
        public readonly int $count,
        public readonly int $pages,
    ) {
    }

    public function compareTo(self $other): int
    {
        $bySeverity = $other->type->severity()->compareTo($this->type->severity());

        if ($bySeverity !== 0) {
            return $bySeverity;
        }

        $byCount = $other->count <=> $this->count;

        return $byCount !== 0 ? $byCount : strcmp($this->type->value, $other->type->value);
    }
}
