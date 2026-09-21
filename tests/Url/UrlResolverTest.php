<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Url;

use Lbonnet\SeoBundle\Url\UrlResolver;
use PHPUnit\Framework\TestCase;

final class UrlResolverTest extends TestCase
{
    /**
     * @dataProvider referenceProvider
     */
    public function testResolve(string $baseUrl, string $reference, ?string $expected): void
    {
        $this->assertSame($expected, UrlResolver::resolve($baseUrl, $reference));
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function referenceProvider(): iterable
    {
        yield 'absolute reference is kept as is' => [
            'https://example.com/a',
            'https://other.example.org/x',
            'https://other.example.org/x',
        ];
        yield 'root relative' => ['https://example.com/a/b', '/x', 'https://example.com/x'];
        yield 'document relative' => ['https://example.com/a/b', 'c', 'https://example.com/a/c'];
        yield 'document relative from a directory' => ['https://example.com/a/b/', 'c', 'https://example.com/a/b/c'];
        yield 'parent segment' => ['https://example.com/a/b', '../c', 'https://example.com/c'];
        yield 'protocol relative' => ['https://example.com/a', '//cdn.example.com/x', 'https://cdn.example.com/x'];
        yield 'query only' => ['https://example.com/a/b', '?page=2', 'https://example.com/a/b?page=2'];
        yield 'port is preserved' => ['https://example.com:8443/a', '/x', 'https://example.com:8443/x'];
        yield 'empty reference' => ['https://example.com/a', '', null];
        yield 'base without host' => ['/not-a-url', 'x', null];
    }

    public function testIsAbsoluteHttpUrl(): void
    {
        $this->assertTrue(UrlResolver::isAbsoluteHttpUrl('https://example.com'));
        $this->assertTrue(UrlResolver::isAbsoluteHttpUrl('http://example.com/a?b=1'));
        $this->assertFalse(UrlResolver::isAbsoluteHttpUrl('/page'));
        $this->assertFalse(UrlResolver::isAbsoluteHttpUrl('//cdn.example.com/x'));
        $this->assertFalse(UrlResolver::isAbsoluteHttpUrl(''));
    }

    public function testIsSameUrl(): void
    {
        $this->assertTrue(UrlResolver::isSameUrl('https://Example.com', 'HTTPS://example.com/'));
        $this->assertFalse(UrlResolver::isSameUrl('http://example.com/', 'https://example.com/'));
        $this->assertFalse(UrlResolver::isSameUrl('https://example.com/a', 'https://example.com/a/'));
        $this->assertFalse(UrlResolver::isSameUrl('https://example.com/a', 'https://example.com/A'));
    }

    /**
     * @dataProvider dedupKeyProvider
     */
    public function testDedupKey(string $url, string $expected): void
    {
        $this->assertSame($expected, UrlResolver::dedupKey($url));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dedupKeyProvider(): iterable
    {
        yield 'scheme is normalized' => ['http://example.com/a', 'https://example.com/a'];
        yield 'host is lower-cased' => ['https://Example.COM/a', 'https://example.com/a'];
        yield 'empty path becomes a slash' => ['https://example.com', 'https://example.com/'];
        yield 'path case is preserved' => ['https://example.com/Page', 'https://example.com/Page'];
        yield 'fragment is dropped' => ['https://example.com/a#top', 'https://example.com/a'];
        yield 'query is kept' => ['https://example.com/a?p=2', 'https://example.com/a?p=2'];
        yield 'port is kept' => ['https://example.com:8443/a', 'https://example.com:8443/a'];
    }

    public function testHttpAndHttpsVariantsShareOneKey(): void
    {
        $this->assertSame(
            UrlResolver::dedupKey('http://example.com'),
            UrlResolver::dedupKey('https://example.com/'),
        );
    }
}
