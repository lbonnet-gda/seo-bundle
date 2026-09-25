<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Module\Technical\Sitemap;

use Lbonnet\SeoBundle\Module\Technical\Sitemap\SitemapParser;
use PHPUnit\Framework\TestCase;

final class SitemapParserTest extends TestCase
{
    public function testReadsTheLocationsOfAUrlSet(): void
    {
        $parsed = SitemapParser::parse(
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" '
            .'xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'
            .'<url><loc> https://example.com/ </loc><lastmod>2026-09-01</lastmod></url>'
            .'<url><loc>https://example.com/a?b=1&amp;c=2</loc>'
            .'<image:image><image:loc>https://example.com/a.jpg</image:loc></image:image></url>'
            .'</urlset>',
        );

        $this->assertNull($parsed->error);
        $this->assertFalse($parsed->isIndex);
        $this->assertFalse($parsed->tooManyEntries);
        $this->assertSame(['https://example.com/', 'https://example.com/a?b=1&c=2'], $parsed->locations);
    }

    public function testReadsTheLocationsOfASitemapIndex(): void
    {
        $parsed = SitemapParser::parse(
            '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .'<sitemap><loc>https://example.com/sitemap-1.xml</loc></sitemap>'
            .'</sitemapindex>',
        );

        $this->assertTrue($parsed->isIndex);
        $this->assertSame(['https://example.com/sitemap-1.xml'], $parsed->locations);
    }

    /**
     * @dataProvider unreadableProvider
     */
    public function testExplainsWhyAFileIsNotASitemap(string $content, string $expectedError): void
    {
        $parsed = SitemapParser::parse($content);

        $this->assertNotNull($parsed->error);
        $this->assertStringContainsString($expectedError, $parsed->error);
        $this->assertSame([], $parsed->locations);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unreadableProvider(): iterable
    {
        yield 'empty' => ['  ', 'empty'];
        yield 'HTML page' => ['<!DOCTYPE html><html><body>Not found</body></html>', 'root element is <html>'];
        yield 'plain text' => ['https://example.com/', 'not XML'];
        yield 'truncated XML' => ['<urlset><url><loc>https://example.com/</loc>', 'malformed'];
    }

    public function testStopsAtTheEntryLimit(): void
    {
        $parsed = SitemapParser::parse(
            '<urlset>'.str_repeat('<url><loc>https://example.com/</loc></url>', SitemapParser::MAX_ENTRIES + 1)
            .'</urlset>',
        );

        $this->assertNull($parsed->error);
        $this->assertTrue($parsed->tooManyEntries);
        $this->assertCount(SitemapParser::MAX_ENTRIES, $parsed->locations);
    }

    public function testDoesNotResolveExternalEntities(): void
    {
        $secretFile = (string)tempnam(sys_get_temp_dir(), 'sitemap');
        file_put_contents($secretFile, 'top-secret-marker');

        try {
            $parsed = SitemapParser::parse(
                sprintf(
                    '<?xml version="1.0"?><!DOCTYPE urlset [<!ENTITY secret SYSTEM "file://%s">]>'
                    .'<urlset><url><loc>https://example.com/&secret;</loc></url></urlset>',
                    $secretFile,
                ),
            );
        } finally {
            unlink($secretFile);
        }

        $this->assertStringNotContainsString('top-secret-marker', implode(' ', $parsed->locations));
    }
}
