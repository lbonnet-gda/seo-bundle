<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Model;

final class DisabledChecks
{
    /** @var array<string, true> */
    private readonly array $types;

    /**
     * @param list<string> $types IssueType values
     */
    public function __construct(array $types = [])
    {
        $disabled = [];

        foreach ($types as $type) {
            $disabled[$type] = true;
        }

        $this->types = $disabled;
    }

    public function has(IssueType $type): bool
    {
        return isset($this->types[$type->value]);
    }

    /**
     * @param list<IssueType> $types
     */
    public function hasAll(array $types): bool
    {
        foreach ($types as $type) {
            if (!$this->has($type)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<Issue> $issues
     *
     * @return list<Issue>
     */
    public function filter(array $issues): array
    {
        if ($this->types === []) {
            return $issues;
        }

        return array_values(array_filter($issues, fn(Issue $issue): bool => !$this->has($issue->type)));
    }
}
