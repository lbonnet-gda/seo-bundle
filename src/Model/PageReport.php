<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Model;

final class PageReport
{
    /**
     * @param list<Issue> $issues
     */
    public function __construct(
        public readonly string $url,
        public readonly int $statusCode,
        public readonly array $issues = [],
        public readonly ?int $depth = null,
        public readonly ?string $canonical = null,
    ) {
    }

    /**
     * @param list<Issue> $issues
     */
    public function withIssues(array $issues): self
    {
        return new self($this->url, $this->statusCode, $issues, $this->depth, $this->canonical);
    }

    /**
     * @param list<Issue> $issues
     */
    public function withAddedIssues(array $issues): self
    {
        if ($issues === []) {
            return $this;
        }

        return $this->withIssues([...$this->issues, ...$issues]);
    }

    public function hasIssues(?Severity $atLeast = null): bool
    {
        return $this->countIssues($atLeast) > 0;
    }

    public function countIssues(?Severity $atLeast = null): int
    {
        if ($atLeast === null) {
            return count($this->issues);
        }

        return count(
            array_filter($this->issues, static fn(Issue $issue): bool => $issue->severity()->isAtLeast($atLeast))
        );
    }
}
