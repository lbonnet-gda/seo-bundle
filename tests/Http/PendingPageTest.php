<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Http;

use Lbonnet\SeoBundle\Http\PendingPage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\ChunkInterface;

final class PendingPageTest extends TestCase
{
    private MockHttpClient $httpClient;

    public function testItIsOverAsSoonAsTheHeadersArriveWhenTheBodyIsNotWanted(): void
    {
        $page = $this->pending('<html>body</html>', readHtml: false);
        $chunks = 0;

        foreach ($this->chunksOf($page) as $chunk) {
            $chunks++;

            if ($page->consume($chunk)) {
                break;
            }
        }

        $this->assertSame(1, $chunks);
        $this->assertNull($page->result()?->html);
    }

    public function testItStopsReadingPastItsLimitAndKeepsWhatItHasRead(): void
    {
        $page = $this->pending(str_repeat('a', 100), maxHtmlLength: 10);

        foreach ($this->chunksOf($page) as $chunk) {
            if ($page->consume($chunk)) {
                break;
            }
        }

        $this->assertSame(100, strlen((string)$page->result()?->html));
    }

    public function testItHasNoResultUntilTheHeadersAreIn(): void
    {
        $this->assertNull($this->pending('<html></html>')->result());
    }

    private function pending(string $body, bool $readHtml = true, int $maxHtmlLength = 5_000_000): PendingPage
    {
        $this->httpClient = new MockHttpClient(static fn(): MockResponse => new MockResponse($body, [
            'response_headers' => ['content-type' => 'text/html; charset=UTF-8'],
        ]));

        return new PendingPage(
            'https://example.com/a',
            0,
            $this->httpClient->request(Request::METHOD_GET, 'https://example.com/a'),
            $readHtml,
            $maxHtmlLength,
        );
    }

    /**
     * @return iterable<ChunkInterface>
     */
    private function chunksOf(PendingPage $page): iterable
    {
        return $this->httpClient->stream($page->response);
    }
}
