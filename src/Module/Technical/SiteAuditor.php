<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module\Technical;

use Lbonnet\SeoBundle\Model\DisabledChecks;
use Lbonnet\SeoBundle\Model\Issue;
use Lbonnet\SeoBundle\Model\IssueType;
use Lbonnet\SeoBundle\Model\PageResponse;
use Lbonnet\SeoBundle\Model\RedirectChain;
use Lbonnet\SeoBundle\Model\RedirectHop;
use Lbonnet\SeoBundle\Module\Technical\Http\HttpTargetProbe;
use Lbonnet\SeoBundle\Module\Technical\Http\TargetProbeInterface;
use Lbonnet\SeoBundle\Module\Technical\Model\CrawlContext;
use Lbonnet\SeoBundle\Module\Technical\Model\PageAudit;
use Lbonnet\SeoBundle\Robots\RobotsTxt;
use Lbonnet\SeoBundle\Robots\RobotsTxtProviderInterface;
use Lbonnet\SeoBundle\Robots\RobotsTxtStatus;
use Lbonnet\SeoBundle\Url\UrlResolver;

final class SiteAuditor implements SiteAuditorInterface
{
    private const GOOGLEBOT = 'Googlebot';

    private const CANONICAL_TARGET_CHECKS = [
        IssueType::CanonicalTargetNotOk,
        IssueType::CanonicalTargetRedirects,
        IssueType::CanonicalTargetNoindex,
    ];

    private const HREFLANG_TARGET_CHECKS = [
        IssueType::HreflangTargetNotOk,
        IssueType::HreflangTargetRedirects,
        IssueType::HreflangTargetNoindex,
    ];

    private readonly DisabledChecks $disabledChecks;

    /**
     * @param list<string> $disabledChecks IssueType values to drop from the report
     */
    public function __construct(
        private readonly TargetProbeInterface $probe,
        private readonly int $maxRedirectHops = 1,
        array $disabledChecks = [],
        private readonly ?RobotsTxtProviderInterface $robotsTxtProvider = null,
        private readonly ?UrlVariantAuditorInterface $urlVariantAuditor = null,
        private readonly ?SitemapAuditorInterface $sitemapAuditor = null,
    ) {
        $this->disabledChecks = new DisabledChecks($disabledChecks);
    }

    public function audit(array $pages, CrawlContext $context, bool $reportDeadRedirects = true): array
    {
        $this->probe->reset();

        /** @var array<string, PageAudit> $pagesByKey */
        $pagesByKey = [];
        /** @var array<string, array<string, string>> $hreflangUrlsByKey dedup key of a page URL => its hreflang URLs */
        $hreflangUrlsByKey = [];
        /** @var array<string, true> $crawledHosts lower-case host => true */
        $crawledHosts = [];

        foreach ($pages as $page) {
            $key = UrlResolver::dedupKey($page->url);
            $pagesByKey[$key] = $page;
            $hreflangUrlsByKey[$key] = $page->hreflangUrls();
            $crawledHosts[self::hostOf($page->url)] = true;
        }

        /** @var array<string, list<Issue>> $extraIssues dedup key of a page URL => issues to add */
        $extraIssues = [];
        /** @var list<PageAudit> $extraPages */
        $extraPages = [];

        foreach ($pages as $page) {
            $issues = [
                ...$this->auditCanonicalTarget($page, $context, $pagesByKey, $crawledHosts),
                ...$this->auditHreflangTargets($page, $context, $pagesByKey, $hreflangUrlsByKey, $crawledHosts),
            ];

            if ($issues !== []) {
                $extraIssues[UrlResolver::dedupKey($page->url)] = $issues;
            }
        }

        foreach ($context->redirectChains() as $chain) {
            $chainIssues = $this->auditRedirectChain($chain);

            if ($chainIssues !== []) {
                $extraPages[] = new PageAudit(
                    url: $chain->startUrl,
                    statusCode: $chain->startStatusCode,
                    issues: $chainIssues,
                );
            }

            foreach ($context->referrersOf($chain->startUrl) as $referrerUrl) {
                $key = UrlResolver::dedupKey($referrerUrl);
                $issue = $this->linkToRedirectIssue($chain, $reportDeadRedirects);

                if ($issue !== null) {
                    $extraIssues[$key] = [...($extraIssues[$key] ?? []), $issue];
                }
            }
        }

        $extraPages = [...$extraPages, ...$this->auditRobotsTxt($pages)];

        $entries = [
            ...$this->urlVariantAuditor?->audit($pages, $context) ?? [],
            ...$this->sitemapAuditor?->audit($pages, $context) ?? [],
        ];

        foreach ($entries as $entry) {
            $key = UrlResolver::dedupKey($entry->url);
            $crawledPage = $pagesByKey[$key] ?? null;

            if ($crawledPage !== null && UrlResolver::isSameUrl($crawledPage->url, $entry->url)) {
                $extraIssues[$key] = [...($extraIssues[$key] ?? []), ...$entry->issues];
            } else {
                $extraPages[] = $entry;
            }
        }

        $audited = array_map(
            function (PageAudit $page) use ($extraIssues): PageAudit {
                $key = UrlResolver::dedupKey($page->url);

                return $this->withoutDisabledChecks($page->withAddedIssues($extraIssues[$key] ?? []));
            },
            $pages,
        );

        foreach ($extraPages as $extraPage) {
            $extraPage = $this->withoutDisabledChecks($extraPage);

            if ($extraPage->issues !== []) {
                $audited[] = $extraPage;
            }
        }

        return $audited;
    }

    /**
     * @param array<string, PageAudit> $pagesByKey
     * @param array<string, true> $crawledHosts
     *
     * @return list<Issue>
     */
    private function auditCanonicalTarget(
        PageAudit $page,
        CrawlContext $context,
        array $pagesByKey,
        array $crawledHosts,
    ): array {
        $target = $page->canonicalElsewhere();

        if ($target === null) {
            return [];
        }

        if ($this->isBlockedForGooglebot($target, $crawledHosts)) {
            return [
                new Issue(
                    IssueType::RobotsTxtBlocksCanonicalTarget,
                    sprintf(
                        'The canonical URL "%s" is blocked for Googlebot by robots.txt, '
                        .'so Google cannot crawl it to confirm the canonical.',
                        $target,
                    ),
                ),
            ];
        }

        $response = self::crawledResponse($target, $context)
            ?? ($this->disabledChecks->hasAll(self::CANONICAL_TARGET_CHECKS) ? null : $this->probe->probe($target));

        if ($response === null) {
            return [];
        }

        if ($response->statusCode === HttpTargetProbe::NO_ANSWER) {
            return [
                new Issue(
                    IssueType::CanonicalTargetNotOk,
                    sprintf(
                        'The canonical URL "%s" could not be reached at all (DNS, timeout or TLS failure), '
                        .'so this page has no valid canonical.',
                        $target,
                    ),
                ),
            ];
        }

        if ($response->isRedirect()) {
            return [
                new Issue(
                    IssueType::CanonicalTargetRedirects,
                    sprintf(
                        'The canonical URL "%s" answers %d instead of 200; point it at the final URL.',
                        $target,
                        $response->statusCode,
                    ),
                ),
            ];
        }

        if ($response->isError()) {
            return [
                new Issue(
                    IssueType::CanonicalTargetNotOk,
                    sprintf(
                        'The canonical URL "%s" answers %d, so this page has no valid canonical.',
                        $target,
                        $response->statusCode,
                    ),
                ),
            ];
        }

        $targetPage = $pagesByKey[UrlResolver::dedupKey($target)] ?? null;

        return [
            ...$this->auditCanonicalTargetNoindex($target, $response, $targetPage),
            ...$this->auditCanonicalChain($page, $target, $targetPage),
        ];
    }

    /**
     * @return list<Issue>
     */
    private function auditCanonicalTargetNoindex(string $target, PageResponse $response, ?PageAudit $targetPage): array
    {
        $noindex = $response->headerRobotsDirectives()->hasNoindex()
            || $targetPage?->signals?->metaRobotsDirectives()->hasNoindex() === true;

        if (!$noindex) {
            return [];
        }

        return [
            new Issue(
                IssueType::CanonicalTargetNoindex,
                sprintf(
                    'The canonical URL "%s" carries a noindex directive: '
                    .'this page defers to a URL that refuses to be indexed.',
                    $target,
                ),
            ),
        ];
    }

    /**
     * @return list<Issue>
     */
    private function auditCanonicalChain(PageAudit $page, string $target, ?PageAudit $targetPage): array
    {
        if ($targetPage === null) {
            return [];
        }

        $nextTarget = $targetPage->canonicalElsewhere();

        if ($nextTarget === null) {
            return [];
        }

        if (UrlResolver::dedupKey($nextTarget) === UrlResolver::dedupKey($page->url)) {
            return [
                new Issue(
                    IssueType::CanonicalChain,
                    sprintf(
                        'The canonical URL "%s" points back to this page; the two pages cancel each other out.',
                        $target,
                    ),
                ),
            ];
        }

        return [
            new Issue(
                IssueType::CanonicalChain,
                sprintf(
                    'The canonical URL "%s" itself declares "%s" as canonical; '
                    .'point this page straight at the final one.',
                    $target,
                    $nextTarget,
                ),
            ),
        ];
    }

    /**
     * @param array<string, PageAudit> $pagesByKey
     * @param array<string, array<string, string>> $hreflangUrlsByKey
     * @param array<string, true> $crawledHosts
     *
     * @return list<Issue>
     */
    private function auditHreflangTargets(
        PageAudit $page,
        CrawlContext $context,
        array $pagesByKey,
        array $hreflangUrlsByKey,
        array $crawledHosts,
    ): array {
        if ($page->isCanonicalizedVariant()) {
            return [];
        }

        $pageKey = UrlResolver::dedupKey($page->url);
        $issues = [];

        foreach ($hreflangUrlsByKey[$pageKey] ?? [] as $targetKey => $target) {
            if ($targetKey === $pageKey) {
                continue;
            }

            if ($this->isBlockedForGooglebot($target, $crawledHosts)) {
                $issues[] = new Issue(
                    IssueType::RobotsTxtBlocksHreflangAlternate,
                    sprintf(
                        'The hreflang alternate "%s" is blocked for Googlebot by robots.txt, '
                        .'so Google cannot crawl it to confirm the return link.',
                        $target,
                    ),
                );

                continue;
            }

            $response = self::crawledResponse($target, $context)
                ?? ($this->disabledChecks->hasAll(self::HREFLANG_TARGET_CHECKS) ? null : $this->probe->probe($target));

            if ($response === null) {
                continue;
            }

            if ($response->statusCode === HttpTargetProbe::NO_ANSWER) {
                $issues[] = new Issue(
                    IssueType::HreflangTargetNotOk,
                    sprintf(
                        'The hreflang alternate "%s" could not be reached at all (DNS, timeout or TLS failure).',
                        $target,
                    ),
                );

                continue;
            }

            if ($response->isRedirect()) {
                $issues[] = new Issue(
                    IssueType::HreflangTargetRedirects,
                    sprintf(
                        'The hreflang alternate "%s" answers %d instead of 200; point it at the final URL.',
                        $target,
                        $response->statusCode,
                    ),
                );

                continue;
            }

            if ($response->isError()) {
                $issues[] = new Issue(
                    IssueType::HreflangTargetNotOk,
                    sprintf('The hreflang alternate "%s" answers %d.', $target, $response->statusCode),
                );

                continue;
            }

            $targetPage = $pagesByKey[$targetKey] ?? null;
            $targetCanonical = $targetPage?->canonicalElsewhere();

            if ($targetCanonical !== null) {
                $issues[] = new Issue(
                    IssueType::HreflangTargetNotCanonical,
                    sprintf(
                        'The hreflang alternate "%s" is not a canonical URL (its canonical is "%s"); '
                        .'point the annotation at the canonical URL.',
                        $target,
                        $targetCanonical,
                    ),
                );

                continue;
            }

            if (
                $response->headerRobotsDirectives()->hasNoindex()
                || $targetPage?->signals?->metaRobotsDirectives()->hasNoindex() === true
            ) {
                $issues[] = new Issue(
                    IssueType::HreflangTargetNoindex,
                    sprintf(
                        'The hreflang alternate "%s" carries a noindex directive, '
                        .'so that language version cannot show up in search results.',
                        $target,
                    ),
                );
            }

            if ($targetPage !== null && !isset($hreflangUrlsByKey[$targetKey][$pageKey])) {
                $issues[] = new Issue(
                    IssueType::HreflangNotReciprocal,
                    sprintf(
                        'The hreflang alternate "%s" does not link back to this page; '
                        .'search engines ignore annotations that are not confirmed both ways.',
                        $target,
                    ),
                );
            }
        }

        return $issues;
    }

    /**
     * @param list<PageAudit> $pages
     *
     * @return list<PageAudit>
     */
    private function auditRobotsTxt(array $pages): array
    {
        $provider = $this->robotsTxtProvider;

        if ($provider === null) {
            return [];
        }

        /** @var array<string, string> $urlByHost lower-case host => one crawled URL on that host */
        $urlByHost = [];

        foreach ($pages as $page) {
            $host = parse_url($page->url, PHP_URL_HOST);

            if (is_string($host)) {
                $urlByHost[strtolower($host)] ??= $page->url;
            }
        }

        $audits = [];

        foreach ($urlByHost as $url) {
            $robotsTxt = $provider->robotsTxt($url);

            if ($robotsTxt === null) {
                continue;
            }

            $issue = $this->robotsTxtIssue($robotsTxt, $url);

            if ($issue !== null) {
                $audits[] = new PageAudit(
                    url: $robotsTxt->url,
                    statusCode: $robotsTxt->statusCode ?? 0,
                    issues: [$issue],
                );
            }
        }

        return $audits;
    }

    private function robotsTxtIssue(RobotsTxt $robotsTxt, string $siteUrl): ?Issue
    {
        if ($robotsTxt->status === RobotsTxtStatus::ServerError) {
            return new Issue(
                IssueType::RobotsTxtServerError,
                ($robotsTxt->statusCode !== null
                    ? sprintf('The robots.txt answers %d.', $robotsTxt->statusCode)
                    : 'The robots.txt could not be fetched (timeout, DNS or connection failure).')
                .' While it cannot be fetched, Google stops crawling the whole site.',
            );
        }

        if (!$robotsTxt->isAllowed(UrlResolver::resolve($siteUrl, '/') ?? $siteUrl, self::GOOGLEBOT)) {
            return new Issue(
                IssueType::RobotsTxtDisallowAll,
                'The robots.txt blocks Googlebot from the site root, so Google cannot crawl the site.',
            );
        }

        return null;
    }

    /**
     * @param array<string, true> $crawledHosts
     */
    private function isBlockedForGooglebot(string $url, array $crawledHosts): bool
    {
        if ($this->robotsTxtProvider === null) {
            return false;
        }

        $robotsTxt = isset($crawledHosts[self::hostOf($url)])
            ? $this->robotsTxtProvider->robotsTxt($url)
            : $this->probe->robotsTxt($url);

        return $robotsTxt?->isAllowed($url, self::GOOGLEBOT) === false;
    }

    private static function crawledResponse(string $url, CrawlContext $context): ?PageResponse
    {
        $response = $context->responseFor($url);

        return $response !== null && UrlResolver::isSameUrl($response->url, $url) ? $response : null;
    }

    private static function hostOf(string $url): string
    {
        return strtolower((string)parse_url($url, PHP_URL_HOST));
    }

    /**
     * @return list<Issue>
     */
    private function auditRedirectChain(RedirectChain $chain): array
    {
        if ($chain->isLoop) {
            return [
                new Issue(
                    IssueType::RedirectLoop,
                    sprintf(
                        'The redirect from "%s" loops back on itself after %d hop(s).',
                        $chain->startUrl,
                        $chain->hopCount(),
                    ),
                ),
            ];
        }

        $issues = [];

        if ($chain->hopCount() > $this->maxRedirectHops) {
            $issues[] = new Issue(
                IssueType::RedirectChainTooLong,
                sprintf(
                    '"%s" goes through %d redirects%s before reaching "%s".',
                    $chain->startUrl,
                    $chain->hopCount(),
                    $chain->truncated ? ' or more' : '',
                    $chain->finalUrl ?? 'an unknown URL',
                ),
            );
        }

        $temporaryHops = $chain->temporaryHops();

        if ($temporaryHops !== []) {
            $issues[] = new Issue(
                IssueType::TemporaryRedirect,
                sprintf(
                    'The redirect from "%s" is temporary (%s): search engines tend to keep the original URL indexed. '
                    .'Use 301 or 308 if the move is permanent.',
                    $chain->startUrl,
                    implode(
                        ', ',
                        array_map(
                            static fn(RedirectHop $hop): string => sprintf('%d at "%s"', $hop->statusCode, $hop->url),
                            $temporaryHops,
                        ),
                    ),
                ),
            );
        }

        if ($chain->endsInError()) {
            $issues[] = new Issue(
                IssueType::RedirectToError,
                sprintf(
                    'The redirect from "%s" ends on "%s", which answers %d; fix the redirect target.',
                    $chain->startUrl,
                    $chain->finalUrl,
                    (int)$chain->finalStatusCode,
                ),
            );
        }

        return $issues;
    }

    private function linkToRedirectIssue(RedirectChain $chain, bool $reportDeadRedirects): ?Issue
    {
        if (!$chain->endsSuccessfully()) {
            if (!$reportDeadRedirects) {
                return null;
            }

            return new Issue(
                IssueType::InternalLinkToRedirect,
                sprintf(
                    'This page links to "%s", which answers %d and never leads to a working page.',
                    $chain->startUrl,
                    $chain->startStatusCode,
                ),
            );
        }

        return new Issue(
            IssueType::InternalLinkToRedirect,
            sprintf(
                'This page links to "%s", which answers %d and redirects to "%s"; link to the final URL instead.',
                $chain->startUrl,
                $chain->startStatusCode,
                $chain->finalUrl,
            ),
        );
    }

    private function withoutDisabledChecks(PageAudit $page): PageAudit
    {
        $kept = $this->disabledChecks->filter($page->issues);

        return count($kept) === count($page->issues) ? $page : $page->withIssues($kept);
    }
}
