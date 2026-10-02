<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Crawl;

use Lbonnet\SeoBundle\Http\PendingPage;
use Lbonnet\SeoBundle\Model\PageResponse;
use Lbonnet\SeoBundle\Model\RedirectChain;
use Lbonnet\SeoBundle\Url\UrlResolver;

final class CrawlState
{
    /** @var list<array{url: string, depth: int, read: bool, force: bool}> */
    public array $queue = [];

    /**
     * @var array<int, array{url: string, depth: int, read: bool, key: string, host: string, seq: int,
     *     pending: PendingPage}>
     */
    public array $inFlight = [];

    /** @var array<string, true> dedup keys currently being requested */
    public array $inFlightKeys = [];

    /** @var list<array{url: string, depth: int, host: string, response: PageResponse}> redirects left to walk */
    public array $redirects = [];

    /** @var array<string, true> */
    public array $visited = [];

    /** @var array<string, true> keys fetched without their body, worth reading if we come back */
    public array $bodySkipped = [];

    /** @var array<string, PageResponse> */
    public array $responses = [];

    /** @var array<string, RedirectChain> */
    public array $chains = [];

    /** @var array<string, string> */
    public array $unreachable = [];

    /** @var array<string, string> dedup key => internal URL robots.txt keeps us from reading */
    public array $disallowed = [];

    /** @var array<int, CrawledPage> dispatch order => page */
    public array $pages = [];

    public int $urlsChecked = 0;
    public bool $truncated = false;
    public bool $stopped = false;
    public int $dispatched = 0;
    public int $pagesDispatched = 0;
    public string $siteUrl;
    public ?string $siteHost;

    public function __construct(public readonly string $startUrl)
    {
        $this->siteUrl = $startUrl;
        $this->siteHost = UrlResolver::hostOf($startUrl);
        $this->queue[] = ['url' => $startUrl, 'depth' => 0, 'read' => true, 'force' => false];
    }

    public function busy(): bool
    {
        return $this->queue !== [] || $this->inFlight !== [] || $this->redirects !== [];
    }

    /**
     * @return list<CrawledPage>
     */
    public function pages(): array
    {
        $pages = array_values($this->pages);

        usort($pages, static fn(CrawledPage $a, CrawledPage $b): int => [$a->depth, $a->url] <=> [$b->depth, $b->url]);

        return $pages;
    }
}
