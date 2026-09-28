<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Crawl;

use Lbonnet\SeoBundle\Model\PageLink;
use Lbonnet\SeoBundle\Model\PageResponse;
use Lbonnet\SeoBundle\Model\RedirectChain;
use Lbonnet\SeoBundle\Url\UrlResolver;

final class CrawlResult
{
    /** @var array<string, CrawledPage> dedup key => page */
    private readonly array $pagesByKey;

    /** @var array<string, list<array{page: CrawledPage, link: PageLink}>> dedup key of a target => its links */
    private readonly array $linksByTarget;

    /** @var array<string, list<array{page: CrawledPage, link: PageLink}>> exact key of an external target => links */
    private readonly array $externalLinksByTarget;

    /**
     * @param string $siteUrl where the start URL leads, when it redirects to another host (e.g. apex to www)
     * @param list<CrawledPage> $pages the HTML pages read, in crawl order
     * @param array<string, PageResponse> $responses dedup key => every internal URL requested, read or not
     * @param array<string, RedirectChain> $redirectChains dedup key of the chain's start URL => chain
     * @param array<string, string> $unreachable dedup key => internal URL that could not be requested at all
     * @param array<string, string> $disallowed dedup key => internal URL robots.txt disallows for our user agent
     * @param int $urlsChecked how many URLs the crawl requested, redirect hops included
     */
    public function __construct(
        public readonly string $startUrl,
        public readonly string $siteUrl,
        public readonly array $pages = [],
        private readonly array $responses = [],
        private readonly array $redirectChains = [],
        private readonly array $unreachable = [],
        private readonly array $disallowed = [],
        public readonly int $urlsChecked = 0,
        public readonly bool $truncated = false,
        public readonly bool $blockedByRobotsTxt = false,
    ) {
        $pagesByKey = [];
        $linksByTarget = [];
        $externalLinksByTarget = [];

        foreach ($pages as $page) {
            $pagesByKey[UrlResolver::dedupKey($page->url)] = $page;

            foreach ($page->signals->links as $link) {
                $linksByTarget[UrlResolver::dedupKey($link->url)][] = ['page' => $page, 'link' => $link];

                if ($link->isExternal) {
                    // Unlike the audited site, whose http:// and https:// versions the crawl folds together, another
                    // site's two schemes are two endpoints: one can answer where the other does not.
                    $externalLinksByTarget[UrlResolver::exactKey($link->url)][] = ['page' => $page, 'link' => $link];
                }
            }
        }

        $this->pagesByKey = $pagesByKey;
        $this->linksByTarget = $linksByTarget;
        $this->externalLinksByTarget = $externalLinksByTarget;
    }

    /**
     * @return array<string, PageResponse> dedup key => response
     */
    public function responses(): array
    {
        return $this->responses;
    }

    public function responseFor(string $url): ?PageResponse
    {
        return $this->responses[UrlResolver::dedupKey($url)] ?? null;
    }

    /**
     * @return list<string> the internal URLs robots.txt kept the crawl from reading, so they went unaudited
     */
    public function disallowedUrls(): array
    {
        return array_values($this->disallowed);
    }

    public function isUnreachable(string $url): bool
    {
        return isset($this->unreachable[UrlResolver::dedupKey($url)]);
    }

    public function pageFor(string $url): ?CrawledPage
    {
        return $this->pagesByKey[UrlResolver::dedupKey($url)] ?? null;
    }

    /**
     * @return array<string, RedirectChain>
     */
    public function redirectChains(): array
    {
        return $this->redirectChains;
    }

    /**
     * @return list<string> URLs of the pages read that link to $url
     */
    public function referrersOf(string $url): array
    {
        return array_values(
            array_unique(
                array_map(
                    static fn(array $entry): string => $entry['page']->url,
                    $this->linksByTarget[UrlResolver::dedupKey($url)] ?? [],
                )
            )
        );
    }

    /**
     * @return array<string, list<array{page: CrawledPage, link: PageLink}>> URL => the pages linking to it
     */
    public function externalLinks(): array
    {
        $externalLinks = [];

        foreach ($this->externalLinksByTarget as $entries) {
            $externalLinks[$entries[0]['link']->url] = $entries;
        }

        return $externalLinks;
    }

    /**
     * @return array<string, list<array{page: CrawledPage, link: PageLink}>> URL => the pages linking to it
     */
    public function internalLinks(): array
    {
        $internalLinks = [];

        foreach ($this->linksByTarget as $entries) {
            if (!$entries[0]['link']->isExternal) {
                $internalLinks[$entries[0]['link']->url] = $entries;
            }
        }

        return $internalLinks;
    }
}
