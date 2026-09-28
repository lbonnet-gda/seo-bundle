<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Http;

use Lbonnet\SeoBundle\Model\PageResponse;
use Lbonnet\SeoBundle\SeoBundle;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class PageFetcher
{
    private const MAX_HTML_LENGTH = 5_000_000;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly int $timeout = 10,
        private readonly string $userAgent = SeoBundle::DEFAULT_USER_AGENT,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param bool $readHtml false to learn the status and headers only, cancelling the body
     */
    public function start(string $url, int $depth = 0, bool $readHtml = true): ?PendingPage
    {
        try {
            $response = $this->httpClient->request(Request::METHOD_GET, $url, [
                'timeout' => $this->timeout,
                'max_redirects' => 0,
                'headers' => [
                    'User-Agent' => $this->userAgent,
                ],
            ]);
        } catch (Throwable $e) {
            $this->report($url, $e);

            return null;
        }

        return new PendingPage($url, $depth, $response, $readHtml, self::MAX_HTML_LENGTH);
    }

    public function fetch(string $url, int $depth = 0, bool $readHtml = true): ?PageResponse
    {
        $pending = $this->start($url, $depth, $readHtml);

        if ($pending === null) {
            return null;
        }

        try {
            foreach ($this->httpClient->stream($pending->response) as $chunk) {
                if ($pending->consume($chunk)) {
                    break;
                }
            }
        } catch (Throwable $e) {
            $pending->abandon();
            $this->report($url, $e);

            return null;
        }

        return $pending->result();
    }

    private function report(string $url, Throwable $e): void
    {
        $this->logger->debug(sprintf('[Seo] Could not fetch "%s": %s', $url, $e->getMessage()));
    }
}
