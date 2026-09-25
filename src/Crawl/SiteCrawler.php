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
        $responses = [];
        /** @var array<string, RedirectChain> $chains */
        $chains = [];
        /** @var array<string, string> $unreachable */
        $unreachable = [];
        /** @var list<CrawledPage> $pages */
        $pages = [];
        $urlsChecked = 0;
        $truncated = false;

        /** @var list<array{url: string, depth: int, read: bool}> $queue */
        $queue = [['url' => $startUrl, 'depth' => 0, 'read' => true]];

        $startKey = UrlResolver::dedupKey($startUrl);
        $siteHost = self::hostOf($startUrl);
        $siteUrl = $startUrl;

        while ($queue !== []) {
            ['url' => $url, 'depth' => $depth, 'read' => $read] = array_shift($queue);
            $key = UrlResolver::dedupKey($url);

            if (isset($visited[$key])) {
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
                $unreachable[$key] = $url;

                continue;
            }

            $responses[$key] = $response;

            if ($response->isRedirect()) {
                $chain = $this->redirectChainResolver->resolve($response);
                $chains[$key] = $chain;
                $urlsChecked += $chain->isLoop || $chain->truncated ? $chain->hopCount() - 1 : $chain->hopCount();
                $finalUrl = $chain->finalUrl;

                if ($key === $startKey && $finalUrl !== null && $chain->endsSuccessfully()) {
                    $finalHost = self::hostOf($finalUrl);

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
                    && $this->isCrawlable($finalUrl, $siteHost, $options->excludePatterns)
                ) {
                    $queue[] = ['url' => $finalUrl, 'depth' => $depth, 'read' => true];
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
                if (
                    $link->isExternal
                    || isset($visited[UrlResolver::dedupKey($link->url)])
                    || $this->robotsTxtChecker?->isAllowed($link->url) === false
                ) {
                    continue;
                }

                $queue[] = ['url' => $link->url, 'depth' => $depth + 1, 'read' => $readLinks];
            }
        }

        return new CrawlResult(
            startUrl: $startUrl,
            siteUrl: $siteUrl,
            pages: $pages,
            responses: $responses,
            redirectChains: $chains,
            unreachable: $unreachable,
            urlsChecked: $urlsChecked,
            truncated: $truncated,
            blockedByRobotsTxt: $this->robotsTxtChecker?->isSiteBlocked($siteUrl) === true,
        );
    }

    /**
     * @param list<string> $excludePatterns
     */
    private function isCrawlable(string $url, ?string $siteHost, array $excludePatterns): bool
    {
        $host = self::hostOf($url);

        if ($siteHost === null || $host === null || strcasecmp($host, $siteHost) !== 0) {
            return false;
        }

        return !UrlPattern::matchesAny($url, $excludePatterns)
            && $this->robotsTxtChecker?->isAllowed($url) !== false;
    }

    private static function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : null;
    }
}
