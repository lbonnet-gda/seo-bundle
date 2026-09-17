<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Message;

final class CheckSeoMessage
{
    /**
     * Every null argument falls back to the bundle configuration.
     *
     * @param list<string> $excludePatterns
     * @param list<string>|null $modules module names ("links", "on_page", "technical")
     */
    public function __construct(
        public readonly ?string $startUrl = null,
        public readonly ?int $maxDepth = null,
        public readonly array $excludePatterns = [],
        public readonly ?int $maxPages = null,
        public readonly ?array $modules = null,
        public readonly ?bool $checkExternal = null,
    ) {
    }
}
