<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module\Technical\Model;

use Lbonnet\SeoBundle\Crawl\CrawlResult;
use Lbonnet\SeoBundle\Model\PageResponse;
use Lbonnet\SeoBundle\Model\RedirectChain;
use Lbonnet\SeoBundle\Url\UrlResolver;

final class CrawlContext
{
    /**
     * @param array<string, PageResponse> $responses dedup key => response
     * @param array<string, RedirectChain> $redirectChains dedup key of the chain's start URL => chain
     * @param array<string, list<string>> $referrers dedup key of a target URL => URLs of the pages linking to it
     */
    public function __construct(
        private readonly array $responses = [],
        private readonly array $redirectChains = [],
        private readonly array $referrers = [],
    ) {
    }

    public static function fromCrawl(CrawlResult $crawl): self
    {
        $referrers = [];

        foreach (array_keys($crawl->internalLinks()) as $url) {
            $referrers[UrlResolver::dedupKey($url)] = $crawl->referrersOf($url);
        }

        return new self($crawl->responses(), $crawl->redirectChains(), $referrers);
    }

    public function responseFor(string $url): ?PageResponse
    {
        return $this->responses[UrlResolver::dedupKey($url)] ?? null;
    }

    /**
     * @return array<string, RedirectChain>
     */
    public function redirectChains(): array
    {
        return $this->redirectChains;
    }

    /**
     * @return list<string> URLs of the crawled pages that link to $url
     */
    public function referrersOf(string $url): array
    {
        return $this->referrers[UrlResolver::dedupKey($url)] ?? [];
    }
}
