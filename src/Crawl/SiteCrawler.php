<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Crawl;

use Lbonnet\SeoBundle\Html\HtmlPageParser;
use Lbonnet\SeoBundle\Http\HostRateLimiter;
use Lbonnet\SeoBundle\Http\PageFetcher;
use Lbonnet\SeoBundle\Http\PendingPage;
use Lbonnet\SeoBundle\Http\RedirectChainResolverInterface;
use Lbonnet\SeoBundle\Http\SiteThrottleExemption;
use Lbonnet\SeoBundle\Model\PageLink;
use Lbonnet\SeoBundle\Model\PageResponse;
use Lbonnet\SeoBundle\Model\RedirectChain;
use Lbonnet\SeoBundle\Robots\RobotsTxtCheckerInterface;
use Lbonnet\SeoBundle\Url\UrlPattern;
use Lbonnet\SeoBundle\Url\UrlResolver;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

final class SiteCrawler
{
    private const IDLE_WAIT_US = 1_000;

    private readonly HostRateLimiter $rateLimiter;

    public function __construct(
        private readonly PageFetcher $pageFetcher,
        private readonly RedirectChainResolverInterface $redirectChainResolver,
        private readonly HtmlPageParser $parser = new HtmlPageParser(),
        private readonly ?RobotsTxtCheckerInterface $robotsTxtChecker = null,
        ?HostRateLimiter $rateLimiter = null,
    ) {
        $this->rateLimiter = $rateLimiter ?? new HostRateLimiter();
    }

    /**
     * @param SiteThrottleExemption|null $throttleExemption moved along when the start URL redirects to another host
     */
    public function crawl(
        string $startUrl,
        CrawlOptions $options,
        ?SiteThrottleExemption $throttleExemption = null,
    ): CrawlResult {
        $state = new CrawlState($startUrl);

        while ($state->busy()) {
            $this->dispatch($state, $options);
            $this->walkRedirects($state, $options, $throttleExemption);

            if ($state->inFlight === []) {
                if ($state->queue === [] && $state->redirects === []) {
                    break;
                }

                usleep(self::IDLE_WAIT_US);

                continue;
            }

            $this->settle($state, $options);
        }

        return new CrawlResult(
            startUrl: $startUrl,
            siteUrl: $state->siteUrl,
            pages: $state->pages(),
            responses: $state->responses,
            redirectChains: $state->chains,
            unreachable: $state->unreachable,
            disallowed: $state->disallowed,
            urlsChecked: $state->urlsChecked,
            truncated: $state->truncated,
            blockedByRobotsTxt: $this->robotsTxtChecker?->isSiteBlocked($state->siteUrl) === true,
        );
    }

    private function dispatch(CrawlState $state, CrawlOptions $options): void
    {
        $waiting = [];

        foreach ($state->queue as $item) {
            if ($state->stopped) {
                break;
            }

            $key = UrlResolver::dedupKey($item['url']);

            if (isset($state->inFlightKeys[$key])) {
                $waiting[] = $item;

                continue;
            }

            $read = $item['read'];

            if (isset($state->visited[$key]) && !$item['force'] && !($read && isset($state->bodySkipped[$key]))) {
                continue;
            }

            $booked = count($state->pages) + $state->readsInFlight;

            if ($read && $options->maxPages > 0 && $booked >= $options->maxPages) {
                if (count($state->pages) < $options->maxPages) {
                    $waiting[] = $item;

                    continue;
                }

                $state->truncated = true;

                if (!$options->checkLinkTargets) {
                    $state->stopped = true;

                    break;
                }

                $read = false;
            }

            $host = UrlResolver::hostOf($item['url']) ?? '';

            if (count($state->inFlight) >= max(1, $options->concurrency) || !$this->take($host)) {
                $waiting[] = $item;

                continue;
            }

            $this->send($state, $item['url'], $item['depth'], $read, $key, $host);
        }

        $state->queue = $state->stopped ? [] : $waiting;
    }

    private function send(CrawlState $state, string $url, int $depth, bool $read, string $key, string $host): void
    {
        $state->visited[$key] = true;
        $state->urlsChecked++;

        $pending = $this->pageFetcher->start($url, $depth, $read, paced: true);

        if ($pending === null) {
            $this->give($host);
            unset($state->bodySkipped[$key]);
            $state->unreachable[$key] = $url;

            return;
        }

        if ($read) {
            $state->readsInFlight++;
        }

        $state->inFlight[spl_object_id($pending->response)] = [
            'url' => $url,
            'depth' => $depth,
            'read' => $read,
            'key' => $key,
            'host' => $host,
            'seq' => $state->dispatched++,
            'pending' => $pending,
        ];
        $state->inFlightKeys[$key] = true;
    }

    private function settle(CrawlState $state, CrawlOptions $options): void
    {
        $responses = array_map(
            static fn(array $entry): ResponseInterface => $entry['pending']->response,
            $state->inFlight,
        );

        foreach ($this->pageFetcher->stream($responses) as $response => $chunk) {
            $entry = $state->inFlight[spl_object_id($response)];

            try {
                if (!$entry['pending']->consume($chunk)) {
                    continue;
                }

                $answer = $entry['pending']->result();
            } catch (Throwable $e) {
                $entry['pending']->abandon();
                $answer = null;
            }

            unset($state->inFlight[spl_object_id($response)], $state->inFlightKeys[$entry['key']]);
            $this->give($entry['host']);
            $this->accept($state, $options, $entry, $answer);

            return;
        }
    }

    /**
     * @param array{url: string, depth: int, read: bool, key: string, host: string, seq: int, pending: PendingPage} $entry
     */
    private function accept(CrawlState $state, CrawlOptions $options, array $entry, ?PageResponse $answer): void
    {
        $key = $entry['key'];

        if ($entry['read']) {
            $state->readsInFlight--;
        }

        if ($answer === null) {
            unset($state->bodySkipped[$key]);
            $state->unreachable[$key] = $entry['url'];

            return;
        }

        if ($entry['read']) {
            unset($state->bodySkipped[$key]);
        } else {
            $state->bodySkipped[$key] = true;
        }

        $state->responses[$key] = $answer;

        if ($answer->isRedirect()) {
            $state->redirects[] = [
                'url' => $entry['url'],
                'depth' => $entry['depth'],
                'host' => $entry['host'],
                'response' => $answer,
            ];

            return;
        }

        if (!$entry['read'] || $answer->html === null) {
            return;
        }

        $signals = $this->parser->parse($answer->html, $entry['url'], $options->excludePatterns);
        $state->pages[$entry['seq']] = new CrawledPage($entry['url'], $entry['depth'], $answer, $signals);

        if ($options->progressCallback !== null) {
            ($options->progressCallback)($entry['url'], count($state->pages));
        }

        $this->queueLinks($state, $options, $entry['url'], $entry['depth'], $signals->links);
    }

    /**
     * @param list<PageLink> $links
     */
    private function queueLinks(CrawlState $state, CrawlOptions $options, string $url, int $depth, array $links): void
    {
        $readLinks = $depth < $options->maxDepth;

        if (!$readLinks && !$options->checkLinkTargets) {
            return;
        }

        foreach ($links as $link) {
            $linkKey = UrlResolver::dedupKey($link->url);
            $alreadyRequested = isset($state->visited[$linkKey]);
            $readableNow = $readLinks && isset($state->bodySkipped[$linkKey]);

            if ($link->isExternal || ($alreadyRequested && !$readableNow)) {
                continue;
            }

            if ($this->isDisallowed($link->url)) {
                $state->disallowed[$linkKey] = $link->url;

                continue;
            }

            $state->queue[] = ['url' => $link->url, 'depth' => $depth + 1, 'read' => $readLinks, 'force' => false];
        }
    }

    private function walkRedirects(
        CrawlState $state,
        CrawlOptions $options,
        ?SiteThrottleExemption $throttleExemption,
    ): void {
        $waiting = [];

        foreach ($state->redirects as $redirect) {
            if (!$this->take($redirect['host'])) {
                $waiting[] = $redirect;

                continue;
            }

            $chain = $this->redirectChainResolver->resolve($redirect['response'], paced: true);
            $this->give($redirect['host']);

            $key = UrlResolver::dedupKey($redirect['url']);
            $state->chains[$key] = $chain;
            $state->urlsChecked += $chain->isLoop || $chain->truncated ? $chain->hopCount() - 1 : $chain->hopCount();

            $this->followChain($state, $options, $redirect, $chain, $key, $throttleExemption);
        }

        $state->redirects = $state->stopped ? [] : $waiting;
    }

    /**
     * @param array{url: string, depth: int, host: string, response: PageResponse} $redirect
     */
    private function followChain(
        CrawlState $state,
        CrawlOptions $options,
        array $redirect,
        RedirectChain $chain,
        string $key,
        ?SiteThrottleExemption $throttleExemption,
    ): void {
        $finalUrl = $chain->finalUrl;

        if ($key === UrlResolver::dedupKey($state->startUrl) && $finalUrl !== null && $chain->endsSuccessfully()) {
            $finalHost = UrlResolver::hostOf($finalUrl);

            if ($finalHost !== null && strcasecmp($finalHost, (string)$state->siteHost) !== 0) {
                $state->siteHost = $finalHost;
                $state->siteUrl = $finalUrl;
                $throttleExemption?->moveTo($finalUrl);
            }
        }

        if (
            $finalUrl === null
            || $chain->isLoop
            || $chain->finalStatusCode === null
            || !$this->isCrawlable($finalUrl, $state->siteHost, $options->excludePatterns, $state->disallowed)
        ) {
            return;
        }

        // A redirect from http:// to https:// lands on a URL sharing its dedup key: it is the page we came for,
        // so it must be read even though that key is already marked visited.
        $state->queue[] = [
            'url' => $finalUrl,
            'depth' => $redirect['depth'],
            'read' => true,
            'force' => UrlResolver::dedupKey($finalUrl) === $key,
        ];
    }

    private function take(string $host): bool
    {
        return $host === '' || $this->rateLimiter->tryAcquire($host);
    }

    private function give(string $host): void
    {
        if ($host !== '') {
            $this->rateLimiter->release($host);
        }
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
