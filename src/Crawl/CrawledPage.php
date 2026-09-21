<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Crawl;

use Lbonnet\SeoBundle\Model\PageResponse;
use Lbonnet\SeoBundle\Model\PageSignals;

final class CrawledPage
{
    public function __construct(
        public readonly string $url,
        public readonly int $depth,
        public readonly PageResponse $response,
        public readonly PageSignals $signals,
    ) {
    }

    public function canonicalElsewhere(): ?string
    {
        return $this->signals->canonicalElsewhere($this->url);
    }

    /**
     * @return array<string, string> dedup key => URL
     */
    public function hreflangUrls(): array
    {
        return $this->signals->hreflangUrls($this->url);
    }

    public function isCanonicalizedVariant(): bool
    {
        return $this->signals->isCanonicalizedVariant($this->url);
    }
}
