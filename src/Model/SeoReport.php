<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Model;

final class SeoReport
{
    /**
     * @param list<PageReport> $pages
     * @param list<Module> $modules
     */
    public function __construct(
        public readonly string $startUrl,
        public readonly array $pages = [],
        public readonly array $modules = [],
        public readonly int $pagesRead = 0,
        public readonly int $urlsChecked = 0,
        public readonly float $totalDuration = 0.0,
        public readonly bool $truncated = false,
        public readonly bool $blockedByRobotsTxt = false,
    ) {
    }

    public function getIssuesCount(?Severity $atLeast = null): int
    {
        return array_sum(array_map(static fn(PageReport $page): int => $page->countIssues($atLeast), $this->pages));
    }

    public function hasIssues(?Severity $atLeast = null): bool
    {
        return $this->getIssuesCount($atLeast) > 0;
    }

    /**
     * @return array<string, int> severity value => number of issues, highest severity first
     */
    public function getIssuesCountBySeverity(): array
    {
        $counts = [
            Severity::Error->value => 0,
            Severity::Warning->value => 0,
            Severity::Notice->value => 0,
        ];

        foreach ($this->pages as $page) {
            foreach ($page->issues as $issue) {
                $counts[$issue->severity()->value]++;
            }
        }

        return $counts;
    }

    /**
     * @return array<string, int> module value => number of issues, for the modules that ran
     */
    public function getIssuesCountByModule(): array
    {
        $counts = [];

        foreach ($this->modules as $module) {
            $counts[$module->value] = 0;
        }

        foreach ($this->pages as $page) {
            foreach ($page->issues as $issue) {
                $counts[$issue->module()->value] = ($counts[$issue->module()->value] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
