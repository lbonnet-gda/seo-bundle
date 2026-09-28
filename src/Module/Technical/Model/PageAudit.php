<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module\Technical\Model;

use Lbonnet\SeoBundle\Model\Issue;
use Lbonnet\SeoBundle\Model\PageSignals;

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

    /**
     * @param list<self> $pages
     */
    public static function startPageOf(array $pages): ?self
    {
        $startPage = null;

        foreach ($pages as $page) {
            if ($startPage === null || $page->depth < $startPage->depth) {
                $startPage = $page;
            }
        }

        return $startPage;
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
