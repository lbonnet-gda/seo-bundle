<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Http;

use Lbonnet\SeoBundle\Model\PageResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class PendingPage
{
    private ?PageResponse $page = null;
    private string $html = '';
    private bool $readingBody = false;
    private bool $finished = false;

    public function __construct(
        public readonly string $url,
        public readonly int $depth,
        public readonly ResponseInterface $response,
        private readonly bool $readHtml,
        private readonly int $maxHtmlLength,
    ) {
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ClientExceptionInterface
     */
    public function consume(ChunkInterface $chunk): bool
    {
        if ($this->finished) {
            return true;
        }

        if ($chunk->isFirst()) {
            /** @var array<string, list<string>> $headers */
            $headers = $this->response->getHeaders(false);
            $redirectLocation = $this->response->getInfo('redirect_url');

            $this->page = new PageResponse(
                url: $this->url,
                statusCode: $this->response->getStatusCode(),
                headers: $headers,
                depth: $this->depth,
                redirectLocation: is_string($redirectLocation) ? $redirectLocation : null,
            );
            $this->readingBody = $this->readHtml && $this->page->isSuccessful() && $this->page->isHtml();

            return !$this->readingBody && $this->stop();
        }

        if ($this->readingBody) {
            $this->html .= $chunk->getContent();

            if (strlen($this->html) > $this->maxHtmlLength) {
                return $this->stop();
            }
        }

        return $chunk->isLast() && $this->stop();
    }

    public function abandon(): void
    {
        $this->stop();
    }

    public function result(): ?PageResponse
    {
        if ($this->page === null) {
            return null;
        }

        return $this->readingBody ? $this->page->withHtml($this->html) : $this->page;
    }

    private function stop(): bool
    {
        $this->finished = true;
        $this->response->cancel();

        return true;
    }
}
