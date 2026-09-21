<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Model;

final class PageResponse
{
    /**
     * @param array<string, list<string>> $headers response headers, lower-cased keys
     * @param string|null $html the response body, or null when it was not HTML or not a 2xx
     * @param int $depth crawl depth: 0 for the start URL, >= 1 for a page reached through an internal link
     * @param string|null $redirectLocation on a 3xx, the next hop as already resolved by the HTTP client (getInfo('redirect_url')), when the transport provided one
     */
    public function __construct(
        public readonly string $url,
        public readonly int $statusCode,
        public readonly array $headers = [],
        public readonly ?string $html = null,
        public readonly int $depth = 0,
        public readonly ?string $redirectLocation = null,
    ) {
    }

    public function withHtml(string $html): self
    {
        return new self(
            $this->url,
            $this->statusCode,
            $this->headers,
            $html,
            $this->depth,
            $this->redirectLocation,
        );
    }

    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function isRedirect(): bool
    {
        return $this->statusCode >= 300 && $this->statusCode < 400;
    }

    public function isError(): bool
    {
        return $this->statusCode >= 400;
    }

    public function isHtml(): bool
    {
        $contentType = $this->headers['content-type'][0] ?? null;

        return $contentType === null || str_contains($contentType, 'text/html');
    }

    public function headerRobotsDirectives(): RobotsDirectives
    {
        return RobotsDirectives::parse($this->headers['x-robots-tag'] ?? []);
    }
}
