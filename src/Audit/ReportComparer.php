<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Audit;

use Lbonnet\SeoBundle\Model\Issue;
use Lbonnet\SeoBundle\Model\IssueChange;
use Lbonnet\SeoBundle\Model\PageReport;
use Lbonnet\SeoBundle\Model\ReportComparison;
use Lbonnet\SeoBundle\Model\SeoReport;
use Lbonnet\SeoBundle\Storage\StoredReport;
use Lbonnet\SeoBundle\Url\UrlResolver;

final class ReportComparer
{
    private const VOLATILE_CONTEXT_KEYS = ['statusCode', 'anchorText', 'timeout'];

    /**
     * @param StoredReport $previous the previous audit of the same site
     */
    public function compare(SeoReport $current, StoredReport $previous): ReportComparison
    {
        $before = self::index($previous->report);
        $appeared = [];
        $fixed = [];
        $stayed = 0;

        foreach ($current->pages as $page) {
            $pageKey = UrlResolver::dedupKey($page->url);
            $pageBefore = $before[$pageKey] ?? [];
            unset($before[$pageKey]);

            foreach (self::groups($page) as $issueKey => $group) {
                $wasThere = $pageBefore[$issueKey]['count'] ?? 0;
                $delta = $group['count'] - $wasThere;
                $stayed += min($group['count'], $wasThere);

                if ($delta > 0) {
                    $appeared[] = new IssueChange($page->url, $group['issue'], $delta);
                } elseif ($delta < 0) {
                    $fixed[] = new IssueChange($page->url, $pageBefore[$issueKey]['issue'], -$delta);
                }

                unset($pageBefore[$issueKey]);
            }

            foreach ($pageBefore as $group) {
                $fixed[] = new IssueChange($page->url, $group['issue'], $group['count']);
            }
        }

        $issuesNoLongerAudited = 0;

        foreach ($before as $pageBefore) {
            foreach ($pageBefore as $group) {
                $issuesNoLongerAudited += $group['count'];
            }
        }

        return new ReportComparison(
            previousAuditAt: $previous->createdAt,
            appeared: $appeared,
            fixed: $fixed,
            stayed: $stayed,
            pagesNoLongerAudited: count($before),
            issuesNoLongerAudited: $issuesNoLongerAudited,
        );
    }

    /**
     * @return array<string, array<string, array{issue: Issue, count: int}>> page => issue key => the issue
     */
    private static function index(SeoReport $report): array
    {
        $indexed = [];

        foreach ($report->pages as $page) {
            $pageKey = UrlResolver::dedupKey($page->url);
            $indexed[$pageKey] ??= [];

            foreach (self::groups($page) as $issueKey => $group) {
                $before = $indexed[$pageKey][$issueKey] ?? ['issue' => $group['issue'], 'count' => 0];

                $indexed[$pageKey][$issueKey] = [
                    'issue' => $before['issue'],
                    'count' => $before['count'] + $group['count'],
                ];
            }
        }

        return $indexed;
    }

    /**
     * @return array<string, array{issue: Issue, count: int}> the page's issues, those it cannot tell apart counted
     */
    private static function groups(PageReport $page): array
    {
        $groups = [];

        foreach ($page->issues as $issue) {
            $key = self::issueKey($issue);
            $group = $groups[$key] ?? ['issue' => $issue, 'count' => 0];

            $groups[$key] = ['issue' => $group['issue'], 'count' => $group['count'] + 1];
        }

        return $groups;
    }

    private static function issueKey(Issue $issue): string
    {
        $context = $issue->context;

        foreach (self::VOLATILE_CONTEXT_KEYS as $volatile) {
            unset($context[$volatile]);
        }

        ksort($context);

        return $issue->type->value."\0".json_encode($context, JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
