<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module\Technical\Http;

use Lbonnet\SeoBundle\Http\BoundedContentReader;
use Lbonnet\SeoBundle\Module\Technical\Model\SitemapDocument;
use Lbonnet\SeoBundle\SeoBundle;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class SitemapFetcher
{
    public const MAX_LENGTH = 52_428_800;

    private const MAX_REDIRECTS = 5;
    private const GZIP_MAGIC_BYTES = "\x1f\x8b";

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly int $timeout = 10,
        private readonly string $userAgent = SeoBundle::DEFAULT_USER_AGENT,
    ) {
    }

    public function fetch(string $url): ?SitemapDocument
    {
        try {
            $response = $this->httpClient->request(Request::METHOD_GET, $url, [
                'timeout' => $this->timeout,
                'max_redirects' => self::MAX_REDIRECTS,
                'headers' => [
                    'User-Agent' => $this->userAgent,
                ],
            ]);

            $document = new SitemapDocument($url, $response->getStatusCode());

            if (!$document->isSuccessful()) {
                $response->cancel();

                return $document;
            }

            $content = BoundedContentReader::read($this->httpClient, $response, self::MAX_LENGTH);
        } catch (Throwable) {
            return null;
        }

        return self::decoded($document, $content);
    }

    private static function decoded(SitemapDocument $document, string $content): SitemapDocument
    {
        if (strlen($content) > self::MAX_LENGTH) {
            return new SitemapDocument($document->url, $document->statusCode, error: 'it weighs more than 50 MB');
        }

        if (!str_starts_with($content, self::GZIP_MAGIC_BYTES)) {
            return new SitemapDocument($document->url, $document->statusCode, $content);
        }

        $uncompressed = @gzdecode($content, self::MAX_LENGTH + 1);

        if (!is_string($uncompressed)) {
            return new SitemapDocument(
                $document->url,
                $document->statusCode,
                error: 'it cannot be uncompressed, or weighs more than 50 MB once uncompressed',
            );
        }

        if (strlen($uncompressed) > self::MAX_LENGTH) {
            return new SitemapDocument(
                $document->url,
                $document->statusCode,
                error: 'it weighs more than 50 MB once uncompressed',
            );
        }

        return new SitemapDocument($document->url, $document->statusCode, $uncompressed);
    }
}
