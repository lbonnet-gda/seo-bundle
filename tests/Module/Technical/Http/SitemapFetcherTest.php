<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Module\Technical\Http;

use Lbonnet\SeoBundle\Module\Technical\Http\SitemapFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class SitemapFetcherTest extends TestCase
{
    public function testReadsAPlainSitemap(): void
    {
        $document = $this->fetcher(new MockResponse('<urlset/>'))->fetch('https://example.com/sitemap.xml');

        $this->assertNotNull($document);
        $this->assertSame(Response::HTTP_OK, $document->statusCode);
        $this->assertSame('<urlset/>', $document->content);
        $this->assertNull($document->error);
    }

    public function testUncompressesAGzippedSitemap(): void
    {
        $document = $this->fetcher(new MockResponse((string)gzencode('<urlset/>')))
            ->fetch('https://example.com/sitemap.xml.gz');

        $this->assertSame('<urlset/>', $document?->content);
    }

    public function testExplainsAGzippedSitemapThatCannotBeUncompressed(): void
    {
        $document = $this->fetcher(new MockResponse("\x1f\x8b not really gzip"))->fetch('https://example.com/a.xml.gz');

        $this->assertNull($document?->content);
        $this->assertStringContainsString('cannot be uncompressed', (string)$document?->error);
    }

    public function testKeepsNoBodyForAnErrorStatus(): void
    {
        $document = $this->fetcher(new MockResponse('Not found', ['http_code' => Response::HTTP_NOT_FOUND]))
            ->fetch('https://example.com/sitemap.xml');

        $this->assertSame(Response::HTTP_NOT_FOUND, $document?->statusCode);
        $this->assertFalse($document->isSuccessful());
        $this->assertNull($document->content);
    }

    public function testReturnsNullWhenTheSitemapCannotBeRequested(): void
    {
        $document = $this->fetcher(new MockResponse('', ['error' => 'Could not resolve host']))
            ->fetch('https://example.com/sitemap.xml');

        $this->assertNull($document);
    }

    public function testFollowsRedirects(): void
    {
        $options = [];
        $httpClient = new MockHttpClient(
            static function (string $method, string $url, array $requestOptions) use (&$options): MockResponse {
                $options = $requestOptions;

                return new MockResponse('<urlset/>');
            }
        );

        (new SitemapFetcher($httpClient))->fetch('https://example.com/sitemap.xml');

        $this->assertSame(5, $options['max_redirects']);
    }

    private function fetcher(MockResponse $response): SitemapFetcher
    {
        return new SitemapFetcher(new MockHttpClient($response));
    }
}
