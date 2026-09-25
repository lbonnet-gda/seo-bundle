<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module\Technical;

use Lbonnet\SeoBundle\Model\Issue;
use Lbonnet\SeoBundle\Model\IssueType;
use Lbonnet\SeoBundle\Model\PageResponse;
use Lbonnet\SeoBundle\Module\Technical\Http\HttpTargetProbe;
use Lbonnet\SeoBundle\Module\Technical\Http\SitemapFetcher;
use Lbonnet\SeoBundle\Module\Technical\Http\TargetProbeInterface;
use Lbonnet\SeoBundle\Module\Technical\Model\CrawlContext;
use Lbonnet\SeoBundle\Module\Technical\Model\PageAudit;
use Lbonnet\SeoBundle\Module\Technical\Model\SitemapDocument;
use Lbonnet\SeoBundle\Module\Technical\Sitemap\SitemapParser;
use Lbonnet\SeoBundle\Robots\RobotsTxt;
use Lbonnet\SeoBundle\Robots\RobotsTxtProviderInterface;
use Lbonnet\SeoBundle\Robots\RobotsTxtStatus;
use Lbonnet\SeoBundle\Url\UrlResolver;

final class SitemapAuditor implements SitemapAuditorInterface
{
    private const GOOGLEBOT = 'Googlebot';

    private const CHECKS = [
        IssueType::SitemapMissing,
        IssueType::SitemapNotOk,
        IssueType::SitemapInvalid,
        IssueType::SitemapUrlInvalid,
        IssueType::SitemapUrlNotOk,
        IssueType::SitemapUrlRedirects,
        IssueType::SitemapUrlNoindex,
        IssueType::SitemapUrlNotCanonical,
        IssueType::SitemapUrlBlockedByRobotsTxt,
        IssueType::PageMissingFromSitemap,
    ];

    private const RESPONSE_CHECKS = [
        IssueType::SitemapUrlNotOk,
        IssueType::SitemapUrlRedirects,
        IssueType::SitemapUrlNoindex,
        IssueType::SitemapUrlNotCanonical,
    ];

    /** @var array<string, true> */
    private readonly array $disabledChecks;

    /** @var array<string, PageAudit> URL => entry being built during an audit */
    private array $entries = [];

    /**
     * @param list<string> $disabledChecks
     */
    public function __construct(
        private readonly SitemapFetcher $fetcher,
        private readonly TargetProbeInterface $probe,
        private readonly ?RobotsTxtProviderInterface $robotsTxtProvider = null,
        private readonly int $maxFiles = 10,
        array $disabledChecks = [],
    ) {
        $disabled = [];

        foreach ($disabledChecks as $check) {
            $disabled[$check] = true;
        }

        $this->disabledChecks = $disabled;
    }

    public function audit(array $pages, CrawlContext $context): array
    {
        $this->entries = [];
        $startPage = self::startPage($pages);
        $parts = $startPage !== null ? parse_url($startPage->url) : null;

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $this->areAllDisabled(self::CHECKS)) {
            return [];
        }

        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $rootUrl = sprintf('%s://%s%s/', strtolower($parts['scheme']), $host, $port);
        $robotsTxt = $this->robotsTxtProvider?->robotsTxt($rootUrl);

        if ($robotsTxt?->status === RobotsTxtStatus::ServerError) {
            return [];
        }

        [$listedUrls, $isComplete] = $this->readSitemaps($robotsTxt, $rootUrl, $host);

        /** @var array<string, PageAudit> $pagesByKey */
        $pagesByKey = [];

        foreach ($pages as $page) {
            $pagesByKey[self::key($page->url)] = $page;
        }

        foreach ($listedUrls as $key => ['url' => $url, 'sitemap' => $sitemap]) {
            $issue = $this->listedUrlIssue($url, $pagesByKey[$key] ?? null, $context, $robotsTxt);

            if ($issue !== null) {
                $this->add($sitemap->url, $sitemap->statusCode, $issue);
            }
        }

        if ($isComplete && !$this->isDisabled(IssueType::PageMissingFromSitemap)) {
            $this->auditMissingPages($pages, $listedUrls, $context, $robotsTxt, $host);
        }

        return array_values($this->entries);
    }

    /**
     * @return array{array<string, array{url: string, sitemap: SitemapDocument}>, bool}
     */
    private function readSitemaps(?RobotsTxt $robotsTxt, string $rootUrl, string $host): array
    {
        $declared = $robotsTxt?->status === RobotsTxtStatus::Found ? $robotsTxt->sitemaps() : [];
        /** @var list<array{url: string, index: string|null}> $queue */
        $queue = [];

        foreach ($declared as $location) {
            if (UrlResolver::isAbsoluteHttpUrl($location)) {
                $queue[] = ['url' => $location, 'index' => null];
            } elseif ($robotsTxt !== null) {
                $this->add(
                    $robotsTxt->url,
                    $robotsTxt->statusCode ?? 0,
                    new Issue(
                        IssueType::SitemapUrlInvalid,
                        sprintf(
                            'The "Sitemap:" line "%s" is not an absolute URL, so search engines ignore it.',
                            $location,
                        ),
                    )
                );
            }
        }

        $isFallback = $declared === [];

        if ($isFallback) {
            $queue[] = ['url' => $rootUrl.'sitemap.xml', 'index' => null];
        }

        $listedUrls = [];
        $isComplete = true;
        $hasReadUrlSet = false;
        $filesFetched = 0;

        while ($queue !== []) {
            ['url' => $url, 'index' => $index] = array_shift($queue);

            if ($this->maxFiles > 0 && $filesFetched >= $this->maxFiles) {
                $isComplete = false;

                break;
            }

            $filesFetched++;
            $document = $this->fetcher->fetch($url);

            if ($document === null || !$document->isSuccessful()) {
                $isComplete = false;
                $this->addUnreachable($url, $document?->statusCode, $isFallback);

                continue;
            }

            $parsed = SitemapParser::parse($document->content ?? '');
            $error = $document->error ?? $parsed->error;

            if ($error === null && $parsed->isIndex && $index !== null) {
                $error = sprintf('it is a sitemap index itself, listed in the sitemap index "%s"', $index);
            }

            if ($error !== null) {
                $isComplete = false;
                $this->add(
                    $url,
                    $document->statusCode,
                    new Issue(
                        IssueType::SitemapInvalid,
                        sprintf('The sitemap "%s" cannot be used: %s.', $url, $error),
                    )
                );

                continue;
            }

            if ($parsed->tooManyEntries) {
                $isComplete = false;
                $this->add(
                    $url,
                    $document->statusCode,
                    new Issue(
                        IssueType::SitemapInvalid,
                        sprintf(
                            'The sitemap "%s" lists more than %s entries: '
                            .'split it and list the parts in a sitemap index.',
                            $url,
                            number_format(SitemapParser::MAX_ENTRIES),
                        ),
                    )
                );
            }

            foreach ($parsed->locations as $location) {
                $problem = $parsed->isIndex
                    ? self::childSitemapProblem($location, $url)
                    : self::listedUrlProblem($location, $host);

                if ($problem !== null) {
                    $this->add($url, $document->statusCode, new Issue(IssueType::SitemapUrlInvalid, $problem));
                } elseif ($parsed->isIndex) {
                    $queue[] = ['url' => $location, 'index' => $url];
                } else {
                    $listedUrls[self::key($location)] ??= ['url' => $location, 'sitemap' => $document];
                }
            }

            $hasReadUrlSet = $hasReadUrlSet || !$parsed->isIndex;
        }

        return [$listedUrls, $isComplete && $hasReadUrlSet];
    }

    private function addUnreachable(string $url, ?int $statusCode, bool $isFallback): void
    {
        $answer = $statusCode !== null ? sprintf('answers %d', $statusCode) : 'could not be fetched';

        if ($isFallback) {
            $this->add(
                $url,
                $statusCode ?? 0,
                new Issue(
                    IssueType::SitemapMissing,
                    sprintf(
                        'robots.txt declares no "Sitemap:" line and "%s" %s: '
                        .'search engines have no sitemap to discover the site\'s pages from.',
                        $url,
                        $answer,
                    ),
                )
            );

            return;
        }

        $this->add(
            $url,
            $statusCode ?? 0,
            new Issue(
                IssueType::SitemapNotOk,
                sprintf('The sitemap "%s" %s, so search engines cannot read it.', $url, $answer),
            )
        );
    }

    private function listedUrlIssue(
        string $url,
        ?PageAudit $page,
        CrawlContext $context,
        ?RobotsTxt $robotsTxt,
    ): ?Issue {
        if (self::isBlockedForGooglebot($url, $robotsTxt)) {
            if ($this->isDisabled(IssueType::SitemapUrlBlockedByRobotsTxt)) {
                return null;
            }

            return new Issue(
                IssueType::SitemapUrlBlockedByRobotsTxt,
                sprintf('The sitemap lists "%s", which robots.txt blocks for Googlebot.', $url),
            );
        }

        if ($this->areAllDisabled(self::RESPONSE_CHECKS)) {
            return null;
        }

        $response = self::crawledResponse($url, $context) ?? $this->probe->probe($url);

        if ($response === null) {
            return null;
        }

        if ($response->statusCode === HttpTargetProbe::NO_ANSWER) {
            return new Issue(
                IssueType::SitemapUrlNotOk,
                sprintf('The sitemap lists "%s", which could not be reached at all.', $url),
            );
        }

        if ($response->isRedirect()) {
            return new Issue(
                IssueType::SitemapUrlRedirects,
                sprintf(
                    'The sitemap lists "%s", which answers %d: list the final URL instead.',
                    $url,
                    $response->statusCode,
                ),
            );
        }

        if ($response->isError()) {
            return new Issue(
                IssueType::SitemapUrlNotOk,
                sprintf('The sitemap lists "%s", which answers %d.', $url, $response->statusCode),
            );
        }

        if (
            $response->headerRobotsDirectives()->hasNoindex()
            || $page?->signals?->metaRobotsDirectives()->hasNoindex() === true
        ) {
            return new Issue(
                IssueType::SitemapUrlNoindex,
                sprintf(
                    'The sitemap lists "%s", which carries a noindex directive: '
                    .'a sitemap should only list URLs meant to be indexed.',
                    $url,
                ),
            );
        }

        $canonical = $page?->canonicalElsewhere();

        if ($canonical !== null) {
            return new Issue(
                IssueType::SitemapUrlNotCanonical,
                sprintf(
                    'The sitemap lists "%s", whose canonical is "%s": list the canonical URL instead.',
                    $url,
                    $canonical,
                ),
            );
        }

        return null;
    }

    /**
     * @param list<PageAudit> $pages
     * @param array<string, array{url: string, sitemap: SitemapDocument}> $listedUrls
     */
    private function auditMissingPages(
        array $pages,
        array $listedUrls,
        CrawlContext $context,
        ?RobotsTxt $robotsTxt,
        string $host,
    ): void {
        foreach ($pages as $page) {
            if (
                isset($listedUrls[self::key($page->url)])
                || strtolower((string)parse_url($page->url, PHP_URL_HOST)) !== $host
                || $page->canonicalElsewhere() !== null
                || $page->signals?->metaRobotsDirectives()->hasNoindex() === true
                || self::crawledResponse($page->url, $context)?->headerRobotsDirectives()->hasNoindex() === true
                || self::isBlockedForGooglebot($page->url, $robotsTxt)
            ) {
                continue;
            }

            $this->add(
                $page->url,
                $page->statusCode,
                new Issue(
                    IssueType::PageMissingFromSitemap,
                    'This indexable page is not listed in any sitemap of the site.',
                )
            );
        }
    }

    private function add(string $url, int $statusCode, Issue $issue): void
    {
        $entry = $this->entries[$url] ?? new PageAudit(url: $url, statusCode: $statusCode);
        $this->entries[$url] = $entry->withAddedIssues([$issue]);
    }

    private static function listedUrlProblem(string $location, string $host): ?string
    {
        if (!UrlResolver::isAbsoluteHttpUrl($location)) {
            return sprintf('The sitemap lists "%s", which is not an absolute URL.', $location);
        }

        if (strtolower((string)parse_url($location, PHP_URL_HOST)) !== $host) {
            return sprintf(
                'The sitemap lists "%s", which is on another host than "%s": search engines ignore it.',
                $location,
                $host,
            );
        }

        return null;
    }

    private static function childSitemapProblem(string $location, string $indexUrl): ?string
    {
        if (!UrlResolver::isAbsoluteHttpUrl($location)) {
            return sprintf('The sitemap index lists "%s", which is not an absolute URL.', $location);
        }

        $indexHost = strtolower((string)parse_url($indexUrl, PHP_URL_HOST));
        $indexPath = (string)parse_url($indexUrl, PHP_URL_PATH);
        $indexDirectory = substr($indexPath, 0, (int)strrpos($indexPath, '/') + 1);

        if (
            strtolower((string)parse_url($location, PHP_URL_HOST)) !== $indexHost
            || !str_starts_with((string)parse_url($location, PHP_URL_PATH), $indexDirectory)
        ) {
            return sprintf(
                'The sitemap index lists "%s", which is not in its directory "%s" or below: '
                .'search engines ignore it.',
                $location,
                $indexHost.$indexDirectory,
            );
        }

        return null;
    }

    private static function crawledResponse(string $url, CrawlContext $context): ?PageResponse
    {
        $response = $context->responseFor($url);

        return $response !== null && UrlResolver::isSameUrl($response->url, $url) ? $response : null;
    }

    private static function isBlockedForGooglebot(string $url, ?RobotsTxt $robotsTxt): bool
    {
        return $robotsTxt?->status === RobotsTxtStatus::Found && !$robotsTxt->isAllowed($url, self::GOOGLEBOT);
    }

    private static function key(string $url): string
    {
        return UrlResolver::exactKey($url);
    }

    /**
     * @param list<PageAudit> $pages
     */
    private static function startPage(array $pages): ?PageAudit
    {
        $startPage = null;

        foreach ($pages as $page) {
            if ($startPage === null || $page->depth < $startPage->depth) {
                $startPage = $page;
            }
        }

        return $startPage;
    }

    private function isDisabled(IssueType $type): bool
    {
        return isset($this->disabledChecks[$type->value]);
    }

    /**
     * @param list<IssueType> $types
     */
    private function areAllDisabled(array $types): bool
    {
        foreach ($types as $type) {
            if (!$this->isDisabled($type)) {
                return false;
            }
        }

        return true;
    }
}
