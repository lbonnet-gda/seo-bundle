<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module\Technical\Model;

use Lbonnet\SeoBundle\Model\Issue;
use Lbonnet\SeoBundle\Model\PageSignals;
use Lbonnet\SeoBundle\Model\Severity;

final class PageAudit
{
    /**
     * @param list<Issue> $issues
     * @param PageSignals|null $signals null for a URL that was recorded without being parsed (e.g. the start of a redirect chain)
     */
    public function __construct(
        public readonly string $url,
        public readonly int $statusCode,
        public readonly array $issues = [],
        public readonly ?PageSignals $signals = null,
        public readonly int $depth = 0,
    ) {
    }

    /**
     * @param list<Issue> $issues
     */
    public function withIssues(array $issues): self
    {
        return new self($this->url, $this->statusCode, $issues, $this->signals, $this->depth);
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

        $count = 0;

        foreach ($this->issues as $issue) {
            if ($issue->severity()->isAtLeast($atLeast)) {
                $count++;
            }
        }

        return $count;
    }

    public function canonicalElsewhere(): ?string
    {
        return $this->signals?->canonicalElsewhere($this->url);
    }

    /**
     * @return array<string, string> dedup key => URL
     */
    public function hreflangUrls(): array
    {
        return $this->signals?->hreflangUrls($this->url) ?? [];
    }

    public function isCanonicalizedVariant(): bool
    {
        return $this->signals?->isCanonicalizedVariant($this->url) ?? false;
    }
}
