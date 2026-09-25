<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module\Technical\Http;

use Lbonnet\SeoBundle\Http\PageFetcher;
use Lbonnet\SeoBundle\Model\PageResponse;
use Lbonnet\SeoBundle\Robots\RobotsTxt;
use Lbonnet\SeoBundle\Robots\RobotsTxtProviderInterface;
use Lbonnet\SeoBundle\Url\UrlResolver;

final class HttpTargetProbe implements TargetProbeInterface
{
    /** @var array<string, PageResponse|null> dedup key => response (null = probed and failed) */
    private array $cache = [];

    /** @var array<string, RobotsTxt|null> lower-case host => robots.txt */
    private array $robotsTxtCache = [];

    private int $probesUsed = 0;

    public function __construct(
        private readonly PageFetcher $pageFetcher,
        private readonly bool $enabled = true,
        private readonly int $maxProbes = 200,
        private readonly ?RobotsTxtProviderInterface $robotsTxtProvider = null,
    ) {
    }

    public function probe(string $url): ?PageResponse
    {
        if (!$this->enabled || !UrlResolver::isAbsoluteHttpUrl($url)) {
            return null;
        }

        $key = UrlResolver::dedupKey($url);

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        if ($this->isBudgetExhausted()) {
            return null;
        }

        $this->probesUsed++;

        return $this->cache[$key] = $this->pageFetcher->fetch($url, readHtml: false);
    }

    public function robotsTxt(string $url): ?RobotsTxt
    {
        if (!$this->enabled || $this->robotsTxtProvider === null || !UrlResolver::isAbsoluteHttpUrl($url)) {
            return null;
        }

        $host = strtolower((string)parse_url($url, PHP_URL_HOST));

        if (array_key_exists($host, $this->robotsTxtCache)) {
            return $this->robotsTxtCache[$host];
        }

        if ($this->isBudgetExhausted()) {
            return null;
        }

        $this->probesUsed++;

        return $this->robotsTxtCache[$host] = $this->robotsTxtProvider->robotsTxt($url);
    }

    public function reset(): void
    {
        $this->cache = [];
        $this->robotsTxtCache = [];
        $this->probesUsed = 0;
    }

    private function isBudgetExhausted(): bool
    {
        return $this->maxProbes > 0 && $this->probesUsed >= $this->maxProbes;
    }
}
