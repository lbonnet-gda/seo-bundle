<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Crawl;

use Lbonnet\SeoBundle\Html\HtmlPageParser;
use Lbonnet\SeoBundle\Http\PageFetcher;
use Lbonnet\SeoBundle\Http\RedirectChainResolverInterface;
use Lbonnet\SeoBundle\Http\SiteThrottleExemption;
use Lbonnet\SeoBundle\Model\RedirectChain;
use Lbonnet\SeoBundle\Robots\RobotsTxtCheckerInterface;
use Lbonnet\SeoBundle\Url\UrlPattern;
use Lbonnet\SeoBundle\Url\UrlResolver;

final class SiteCrawler
{
    public function __construct(
        private readonly PageFetcher $pageFetcher,
        private readonly RedirectChainResolverInterface $redirectChainResolver,
        private readonly HtmlPageParser $parser = new HtmlPageParser(),
        private readonly ?RobotsTxtCheckerInterface $robotsTxtChecker = null,
    ) {
    }

    /**
     * @param SiteThrottleExemption|null $throttleExemption moved along when the start URL redirects to another host
     */
    public function crawl(
        string $startUrl,
        CrawlOptions $options,
        ?SiteThrottleExemption $throttleExemption = null,
    ): CrawlResult {
        /** @var array<string, true> $visited */
        $visited = [];
        /** @var array<string, true> $bodySkipped */
        $bodySkipped = [];
        $responses = [];
        /** @var array<string, RedirectChain> $chains */
        $chains = [];
        /** @var array<string, string> $unreachable */
        $unreachable = [];
        /** @var array<string, string> $disallowed dedup key => internal URL robots.txt keeps us from reading */
        $disallowed = [];
        /** @var list<CrawledPage> $pages */
        $pages = [];
        $urlsChecked = 0;
        $truncated = false;

        /** @var list<array{url: string, depth: int, read: bool, force: bool}> $queue */
        $queue = [['url' => $startUrl, 'depth' => 0, 'read' => true, 'force' => false]];

        $startKey = UrlResolver::dedupKey($startUrl);
        $siteHost = UrlResolver::hostOf($startUrl);
        $siteUrl = $startUrl;

        while ($queue !== []) {
            ['url' => $url, 'depth' => $depth, 'read' => $read, 'force' => $force] = array_shift($queue);
            $key = UrlResolver::dedupKey($url);

            if (isset($visited[$key]) && !$force && !($read && isset($bodySkipped[$key]))) {
                continue;
            }

            if ($read && $options->maxPages > 0 && count($pages) >= $options->maxPages) {
                $truncated = true;

                if (!$options->checkLinkTargets) {
                    break;
                }

                $read = false;
            }

            $visited[$key] = true;
            $response = $this->pageFetcher->fetch($url, $depth, $read);
            $urlsChecked++;

            if ($response === null) {
                unset($bodySkipped[$key]);
                $unreachable[$key] = $url;

                continue;
            }

            if ($read) {
                unset($bodySkipped[$key]);
            } else {
                $bodySkipped[$key] = true;
            }

            $responses[$key] = $response;

            if ($response->isRedirect()) {
                $chain = $this->redirectChainResolver->resolve($response);
                $chains[$key] = $chain;
                $urlsChecked += $chain->isLoop || $chain->truncated ? $chain->hopCount() - 1 : $chain->hopCount();
                $finalUrl = $chain->finalUrl;

                if ($key === $startKey && $finalUrl !== null && $chain->endsSuccessfully()) {
                    $finalHost = UrlResolver::hostOf($finalUrl);

                    if ($finalHost !== null && strcasecmp($finalHost, (string)$siteHost) !== 0) {
                        $siteHost = $finalHost;
                        $siteUrl = $finalUrl;
                        $throttleExemption?->moveTo($finalUrl);
                    }
                }

                if (
                    $read
                    && $finalUrl !== null
                    && !$chain->isLoop
                    && $chain->finalStatusCode !== null
                    && $this->isCrawlable($finalUrl, $siteHost, $options->excludePatterns, $disallowed)
                ) {
                    // A redirect from http:// to https:// lands on a URL sharing its dedup key: it is the page we
                    // came for, so it must be read even though that key is already marked visited.
                    $queue[] = [
                        'url' => $finalUrl,
                        'depth' => $depth,
                        'read' => true,
                        'force' => UrlResolver::dedupKey($finalUrl) === $key,
                    ];
                }

                continue;
            }

            if (!$read || $response->html === null) {
                continue;
            }

            $signals = $this->parser->parse($response->html, $url, $options->excludePatterns);
            $pages[] = new CrawledPage($url, $depth, $response, $signals);

            if ($options->progressCallback !== null) {
                ($options->progressCallback)($url, count($pages));
            }

            $readLinks = $depth < $options->maxDepth;

            if (!$readLinks && !$options->checkLinkTargets) {
                continue;
            }

            foreach ($signals->links as $link) {
                $linkKey = UrlResolver::dedupKey($link->url);

                $alreadyRequested = isset($visited[$linkKey]);
                $readableNow = $readLinks && isset($bodySkipped[$linkKey]);

                if ($link->isExternal || ($alreadyRequested && !$readableNow)) {
                    continue;
                }

                if ($this->isDisallowed($link->url)) {
                    $disallowed[$linkKey] = $link->url;

                    continue;
                }

                $queue[] = ['url' => $link->url, 'depth' => $depth + 1, 'read' => $readLinks, 'force' => false];
            }
        }

        return new CrawlResult(
            startUrl: $startUrl,
            siteUrl: $siteUrl,
            pages: $pages,
            responses: $responses,
            redirectChains: $chains,
            unreachable: $unreachable,
            disallowed: $disallowed,
            urlsChecked: $urlsChecked,
            truncated: $truncated,
            blockedByRobotsTxt: $this->robotsTxtChecker?->isSiteBlocked($siteUrl) === true,
        );
    }

    /**
     * @param list<string> $excludePatterns
     * @param array<string, string> $disallowed collects what robots.txt keeps out, so the audit can own up to it
     */
    private function isCrawlable(string $url, ?string $siteHost, array $excludePatterns, array &$disallowed): bool
    {
        $host = UrlResolver::hostOf($url);

        if ($siteHost === null || $host === null || strcasecmp($host, $siteHost) !== 0) {
            return false;
        }

        if (UrlPattern::matchesAny($url, $excludePatterns)) {
            return false;
        }

        if ($this->isDisallowed($url)) {
            $disallowed[UrlResolver::dedupKey($url)] = $url;

            return false;
        }

        return true;
    }

    private function isDisallowed(string $url): bool
    {
        return $this->robotsTxtChecker?->isAllowed($url) === false;
    }
}
