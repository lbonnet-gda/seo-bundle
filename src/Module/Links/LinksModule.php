<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module\Links;

use Lbonnet\SeoBundle\Crawl\CrawlResult;
use Lbonnet\SeoBundle\Model\DisabledChecks;
use Lbonnet\SeoBundle\Model\Issue;
use Lbonnet\SeoBundle\Model\IssueType;
use Lbonnet\SeoBundle\Model\Module;
use Lbonnet\SeoBundle\Model\PageLink;
use Lbonnet\SeoBundle\Model\PageReport;
use Lbonnet\SeoBundle\Module\Links\Model\CheckResult;
use Lbonnet\SeoBundle\Module\ModuleInterface;
use Lbonnet\SeoBundle\Module\ModuleOptions;
use Lbonnet\SeoBundle\Url\UrlResolver;

final class LinksModule implements ModuleInterface
{
    private const EXTERNAL_CHECKS = [IssueType::BrokenExternalLink, IssueType::ExternalLinkLikelyBlocked];

    private readonly DisabledChecks $disabledChecks;

    /**
     * @param list<string> $disabledChecks IssueType values the user turned off
     */
    public function __construct(
        private readonly UrlCheckerInterface $urlChecker,
        array $disabledChecks = [],
    ) {
        $this->disabledChecks = new DisabledChecks($disabledChecks);
    }

    public function module(): Module
    {
        return Module::Links;
    }

    public function audit(CrawlResult $crawl, ModuleOptions $options): array
    {
        /** @var array<string, list<Issue>> $issuesByPage */
        $issuesByPage = [];

        foreach ($crawl->internalLinks() as $url => $entries) {
            $problem = self::internalLinkProblem($url, $crawl);

            if ($problem === null) {
                continue;
            }

            foreach ($entries as ['page' => $page, 'link' => $link]) {
                $issuesByPage[$page->url][] = self::brokenLink(
                    IssueType::BrokenInternalLink,
                    $url,
                    $problem,
                    $link,
                    $crawl->responseFor($url)?->statusCode,
                );
            }
        }

        if ($options->checkExternal && !$this->disabledChecks->hasAll(self::EXTERNAL_CHECKS)) {
            foreach ($crawl->externalLinks() as $url => $entries) {
                $result = $this->urlChecker->check($url);

                if (!$result->isBroken()) {
                    continue;
                }

                foreach ($entries as ['page' => $page, 'link' => $link]) {
                    $issuesByPage[$page->url][] = self::externalLinkIssue($url, $result, $link);
                }
            }
        }

        $reports = [];

        foreach ($issuesByPage as $url => $issues) {
            $page = $crawl->pageFor($url);
            $reports[] = new PageReport(
                url: $url,
                statusCode: $page?->response->statusCode ?? 0,
                issues: $issues,
                depth: $page?->depth,
            );
        }

        return $reports;
    }

    private static function internalLinkProblem(string $url, CrawlResult $crawl): ?string
    {
        if ($crawl->isUnreachable($url)) {
            return 'could not be reached at all';
        }

        $response = $crawl->responseFor($url);

        if ($response === null) {
            return null;
        }

        if ($response->isError()) {
            return sprintf('answers %d', $response->statusCode);
        }

        if (!$response->isRedirect()) {
            return null;
        }

        $chain = $crawl->redirectChains()[UrlResolver::dedupKey($url)] ?? null;

        if ($chain === null || $chain->endsSuccessfully()) {
            return null;
        }

        if ($chain->isLoop) {
            return 'redirects in a loop';
        }

        if ($chain->finalStatusCode === null) {
            return sprintf('redirects to "%s", which could not be reached', $chain->finalUrl);
        }

        return sprintf('redirects to "%s", which answers %d', $chain->finalUrl, $chain->finalStatusCode);
    }

    private static function externalLinkIssue(string $url, CheckResult $result, PageLink $link): Issue
    {
        if ($result->likelyBlocked) {
            return self::brokenLink(
                IssueType::ExternalLinkLikelyBlocked,
                $url,
                sprintf(
                    'answers %d, which looks like %s bot protection rather than a broken page',
                    (int)$result->statusCode,
                    $result->blockedBy->value ?? 'a',
                ),
                $link,
                $result->statusCode,
            );
        }

        $problem = $result->statusCode !== null
            ? sprintf('answers %d', $result->statusCode)
            : sprintf('could not be reached (%s)', $result->errorMessage);

        return self::brokenLink(IssueType::BrokenExternalLink, $url, $problem, $link, $result->statusCode);
    }

    private static function brokenLink(
        IssueType $type,
        string $url,
        string $problem,
        PageLink $link,
        ?int $statusCode,
    ): Issue {
        return new Issue(
            $type,
            sprintf('This page links to "%s", which %s.', $url, $problem),
            ['target' => $url, 'statusCode' => $statusCode, 'anchorText' => $link->anchorText],
        );
    }
}
