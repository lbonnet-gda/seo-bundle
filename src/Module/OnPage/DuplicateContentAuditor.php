<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module\OnPage;

use Lbonnet\SeoBundle\Crawl\CrawledPage;
use Lbonnet\SeoBundle\Model\Issue;
use Lbonnet\SeoBundle\Model\IssueType;

final class DuplicateContentAuditor
{
    /**
     * @param list<CrawledPage> $pages
     *
     * @return array<string, list<Issue>> page URL => its issues
     */
    public function audit(array $pages): array
    {
        $titleCounts = self::countDuplicates(
            $pages,
            static fn(CrawledPage $page): ?string => $page->signals->title,
        );
        $descriptionCounts = self::countDuplicates(
            $pages,
            static fn(CrawledPage $page): ?string => $page->signals->metaDescription,
        );

        $issues = [];

        foreach ($pages as $page) {
            $pageIssues = [];

            if (isset($titleCounts[$page->url])) {
                $pageIssues[] = new Issue(
                    IssueType::DuplicateTitle,
                    sprintf(
                        'The title "%s" is shared with %d other page(s).',
                        $page->signals->title,
                        $titleCounts[$page->url],
                    ),
                    ['title' => $page->signals->title, 'pages' => $titleCounts[$page->url] + 1],
                );
            }

            if (isset($descriptionCounts[$page->url])) {
                $pageIssues[] = new Issue(
                    IssueType::DuplicateDescription,
                    sprintf(
                        'The meta description is shared with %d other page(s).',
                        $descriptionCounts[$page->url],
                    ),
                    ['pages' => $descriptionCounts[$page->url] + 1],
                );
            }

            if ($pageIssues !== []) {
                $issues[$page->url] = $pageIssues;
            }
        }

        return $issues;
    }

    /**
     * @param list<CrawledPage> $pages
     * @param callable(CrawledPage): ?string $value
     *
     * @return array<string, int> page URL => how many other pages share its value
     */
    private static function countDuplicates(array $pages, callable $value): array
    {
        $urlsByValue = [];

        foreach ($pages as $page) {
            $pageValue = $value($page);

            if ($pageValue !== null) {
                $urlsByValue[$pageValue][] = $page->url;
            }
        }

        $counts = [];

        foreach ($urlsByValue as $urls) {
            if (count($urls) < 2) {
                continue;
            }

            foreach ($urls as $url) {
                $counts[$url] = count($urls) - 1;
            }
        }

        return $counts;
    }
}
