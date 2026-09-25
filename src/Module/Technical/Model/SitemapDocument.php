<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module\Technical\Model;

final class SitemapDocument
{
    public function __construct(
        public readonly string $url,
        public readonly int $statusCode,
        public readonly ?string $content = null,
        public readonly ?string $error = null,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
