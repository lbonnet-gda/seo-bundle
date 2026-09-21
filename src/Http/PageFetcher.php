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
    public function fetch(string $url, int $depth = 0, bool $readHtml = true): ?PageResponse
    {
        try {
            $response = $this->httpClient->request(Request::METHOD_GET, $url, [
                'timeout' => $this->timeout,
                'max_redirects' => 0,
                'headers' => [
                    'User-Agent' => $this->userAgent,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            /** @var array<string, list<string>> $headers */
            $headers = $response->getHeaders(false);
            $redirectLocation = $response->getInfo('redirect_url');

            $page = new PageResponse(
                url: $url,
                statusCode: $statusCode,
                headers: $headers,
                depth: $depth,
                redirectLocation: is_string($redirectLocation) ? $redirectLocation : null,
            );

            if (!$readHtml || !$page->isSuccessful() || !$page->isHtml()) {
                $response->cancel();

                return $page;
            }

            return $page->withHtml(
                BoundedContentReader::read($this->httpClient, $response, self::MAX_HTML_LENGTH)
            );
        } catch (Throwable $e) {
            $this->logger->debug(sprintf('[Seo] Could not fetch "%s": %s', $url, $e->getMessage()));

            return null;
        }
    }
}
