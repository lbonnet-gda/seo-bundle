<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Http;

use Lbonnet\SeoBundle\Http\PageFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class PageFetcherTest extends TestCase
{
    public function testItReadsTheBodyOfAnHtmlPage(): void
    {
        $httpClient = new MockHttpClient(static fn(): MockResponse => new MockResponse('<html>page</html>', [
            'response_headers' => ['content-type' => 'text/html; charset=UTF-8'],
        ]));

        $response = (new PageFetcher($httpClient))->fetch('https://example.com/a', depth: 2);

        $this->assertSame('<html>page</html>', $response?->html);
        $this->assertSame(2, $response->depth);
    }

    public function testItDoesNotReadTheBodyOfANonHtmlResponse(): void
    {
        $httpClient = new MockHttpClient(static fn(): MockResponse => new MockResponse('%PDF-1.4', [
            'response_headers' => ['content-type' => 'application/pdf'],
        ]));

        $response = (new PageFetcher($httpClient))->fetch('https://example.com/a.pdf');

        $this->assertSame(Response::HTTP_OK, $response?->statusCode);
        $this->assertNull($response->html);
    }

    public function testItReturnsTheStatusAndHeaders(): void
    {
        $httpClient = new MockHttpClient(static fn(): MockResponse => new MockResponse('<html></html>', [
            'response_headers' => ['content-type' => 'text/html', 'x-robots-tag' => 'noindex'],
        ]));

        $response = (new PageFetcher($httpClient))->fetch('https://example.com/a', readHtml: false);

        $this->assertNotNull($response);
        $this->assertSame('https://example.com/a', $response->url);
        $this->assertSame(Response::HTTP_OK, $response->statusCode);
        $this->assertTrue($response->isSuccessful());
        $this->assertTrue($response->isHtml());
        $this->assertTrue($response->headerRobotsDirectives()->hasNoindex());
        $this->assertNull($response->html);
    }

    public function testItReportsARedirectInsteadOfFollowingIt(): void
    {
        $httpClient = new MockHttpClient(static fn(): MockResponse => new MockResponse('', [
            'http_code' => Response::HTTP_MOVED_PERMANENTLY,
            'response_headers' => ['location' => 'https://example.com/b'],
        ]));

        $response = (new PageFetcher($httpClient))->fetch('https://example.com/a', readHtml: false);

        $this->assertNotNull($response);
        $this->assertTrue($response->isRedirect());
        $this->assertSame(['https://example.com/b'], $response->headers['location']);
    }

    public function testItReturnsNullWhenTheUrlCannotBeReached(): void
    {
        $httpClient = new MockHttpClient(
            static fn(): MockResponse => new MockResponse('', ['error' => 'Connection refused'])
        );

        $this->assertNull((new PageFetcher($httpClient))->fetch('https://example.com/a', readHtml: false));
    }

    public function testAnErrorStatusIsStillAResult(): void
    {
        $httpClient = new MockHttpClient(
            static fn(): MockResponse => new MockResponse('', ['http_code' => Response::HTTP_NOT_FOUND])
        );

        $response = (new PageFetcher($httpClient))->fetch('https://example.com/a', readHtml: false);

        $this->assertNotNull($response);
        $this->assertTrue($response->isError());
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->statusCode);
    }
}
