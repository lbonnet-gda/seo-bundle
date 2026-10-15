<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Storage;

use DateTimeImmutable;
use DateTimeInterface;
use Lbonnet\SeoBundle\Model\Issue;
use Lbonnet\SeoBundle\Model\IssueType;
use Lbonnet\SeoBundle\Model\Module;
use Lbonnet\SeoBundle\Model\PageReport;
use Lbonnet\SeoBundle\Model\SeoReport;
use stdClass;

final class ReportJson
{
    /**
     * @return array<string, mixed>
     */
    public static function encode(SeoReport $report, string $createdAt): array
    {
        return [
            'startUrl' => $report->startUrl,
            'createdAt' => $createdAt,
            'modules' => array_map(static fn(Module $module): string => $module->value, $report->modules),
            'pagesRead' => $report->pagesRead,
            'urlsChecked' => $report->urlsChecked,
            'totalDuration' => $report->totalDuration,
            'truncated' => $report->truncated,
            'blockedByRobotsTxt' => $report->blockedByRobotsTxt,
            'startUrlStatusCode' => $report->startUrlStatusCode,
            'urlsDisallowedByRobotsTxt' => $report->urlsDisallowedByRobotsTxt,
            'urlsBeyondMaxDepth' => $report->urlsBeyondMaxDepth,
            'issuesCount' => $report->getIssuesCount(),
            'issuesBySeverity' => $report->getIssuesCountBySeverity(),
            'issuesByModule' => $report->getIssuesCountByModule(),
            'pages' => array_map(static fn(PageReport $page): array => [
                'url' => $page->url,
                'statusCode' => $page->statusCode,
                'depth' => $page->depth,
                'canonical' => $page->canonical,
                'issues' => array_map(static fn(Issue $issue): array => [
                    'type' => $issue->type->value,
                    'module' => $issue->module()->value,
                    'severity' => $issue->severity()->value,
                    'message' => $issue->message,
                    'context' => $issue->context === [] ? new stdClass() : $issue->context,
                ], $page->issues),
            ], $report->pages),
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function decode(array $data): ?SeoReport
    {
        $startUrl = $data['startUrl'] ?? null;
        $statusCode = $data['startUrlStatusCode'] ?? null;

        if (!is_string($startUrl) || $startUrl === '') {
            return null;
        }

        return new SeoReport(
            startUrl: $startUrl,
            pages: self::decodePages($data['pages'] ?? null),
            modules: self::decodeModules($data['modules'] ?? null),
            pagesRead: self::toInt($data['pagesRead'] ?? null),
            urlsChecked: self::toInt($data['urlsChecked'] ?? null),
            totalDuration: self::toFloat($data['totalDuration'] ?? null),
            truncated: ($data['truncated'] ?? null) === true,
            blockedByRobotsTxt: ($data['blockedByRobotsTxt'] ?? null) === true,
            startUrlStatusCode: is_int($statusCode) ? $statusCode : null,
            urlsDisallowedByRobotsTxt: self::toInt($data['urlsDisallowedByRobotsTxt'] ?? null),
            urlsBeyondMaxDepth: self::toInt($data['urlsBeyondMaxDepth'] ?? null),
        );
    }

    /**
     * @param array<mixed> $data
     */
    public static function createdAt(array $data): ?DateTimeImmutable
    {
        $createdAt = $data['createdAt'] ?? null;

        if (!is_string($createdAt)) {
            return null;
        }

        return DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $createdAt) ?: null;
    }

    /**
     * @return list<PageReport>
     */
    private static function decodePages(mixed $pages): array
    {
        if (!is_array($pages)) {
            return [];
        }

        $decoded = [];

        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }

            $url = $page['url'] ?? null;
            $depth = $page['depth'] ?? null;
            $canonical = $page['canonical'] ?? null;

            if (!is_string($url) || $url === '') {
                continue;
            }

            $decoded[] = new PageReport(
                url: $url,
                statusCode: self::toInt($page['statusCode'] ?? null),
                issues: self::decodeIssues($page['issues'] ?? null),
                depth: is_int($depth) ? $depth : null,
                canonical: is_string($canonical) ? $canonical : null,
            );
        }

        return $decoded;
    }

    /**
     * @return list<Issue>
     */
    private static function decodeIssues(mixed $issues): array
    {
        if (!is_array($issues)) {
            return [];
        }

        $decoded = [];

        foreach ($issues as $issue) {
            if (!is_array($issue)) {
                continue;
            }

            $type = is_string($issue['type'] ?? null) ? IssueType::tryFrom($issue['type']) : null;
            $message = $issue['message'] ?? null;

            // A report written by an older version may name a check that has since been removed or renamed.
            if ($type === null) {
                continue;
            }

            $decoded[] = new Issue(
                type: $type,
                message: is_string($message) ? $message : '',
                context: self::decodeContext($issue['context'] ?? null),
            );
        }

        return $decoded;
    }

    /**
     * @return array<string, scalar|null>
     */
    private static function decodeContext(mixed $context): array
    {
        if (!is_array($context)) {
            return [];
        }

        return array_filter($context, function ($value, $key) {
            return is_string($key) && (is_scalar($value) || $value === null);
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @return list<Module>
     */
    private static function decodeModules(mixed $modules): array
    {
        if (!is_array($modules)) {
            return [];
        }

        $decoded = [];

        foreach ($modules as $module) {
            $decodedModule = is_string($module) ? Module::tryFrom($module) : null;

            if ($decodedModule !== null) {
                $decoded[] = $decodedModule;
            }
        }

        return $decoded;
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }

    private static function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float)$value : 0.0;
    }
}
