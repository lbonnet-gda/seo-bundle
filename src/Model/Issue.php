<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Model;

final class Issue
{
    /**
     * @param array<string, scalar|null> $context
     */
    public function __construct(
        public readonly IssueType $type,
        public readonly string $message,
        public readonly array $context = [],
    ) {
    }

    public function severity(): Severity
    {
        return $this->type->severity();
    }

    public function module(): Module
    {
        return $this->type->module();
    }
}
