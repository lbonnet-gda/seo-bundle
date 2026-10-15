<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Model;

final class IssueChange
{
    public function __construct(
        public readonly string $pageUrl,
        public readonly Issue $issue,
        public readonly int $count = 1,
    ) {
    }
}
