<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Html;

use Lbonnet\SeoBundle\Html\HtmlPageParser;
use Lbonnet\SeoBundle\Model\PageSignals;
use PHPUnit\Framework\TestCase;

final class HtmlPageParserTest extends TestCase
{
    private const PAGE_URL = 'https://example.com/sub/index.html';

    public function testExtractsCanonicalRobotsAndLang(): void
    {
        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="fr">
                <head>
                    <title>Accueil</title>
                    <link rel="stylesheet" href="/app.css">
                    <link rel="Canonical" href="https://example.com/accueil">
                    <meta name="Robots" content="noindex, follow">
                </head>
                <body><p>Bonjour</p></body>
            </html>
            HTML;

        $signals = self::parse($html);

        $this->assertSame(['https://example.com/accueil'], $signals->canonicalHrefs);
        $this->assertSame([], $signals->bodyCanonicalHrefs);
        $this->assertSame(['noindex, follow'], $signals->metaRobots);
        $this->assertSame('fr', $signals->htmlLang);
        $this->assertNull($signals->metaRefreshUrl);
        $this->assertSame('https://example.com/accueil', $signals->effectiveCanonicalHref());
        $this->assertTrue($signals->metaRobotsDirectives()->hasNoindex());
    }

    public function testDetectsSeveralConflictingCanonicals(): void
    {
        $html = <<<HTML
            <html><head>
                <link rel="canonical" href="https://example.com/a">
                <link rel="canonical" href="https://example.com/b">
            </head><body></body></html>
            HTML;

        $signals = self::parse($html);

        $this->assertSame(['https://example.com/a', 'https://example.com/b'], $signals->canonicalHrefs);
        $this->assertNull($signals->effectiveCanonicalHref());
    }

    public function testRepeatingTheSameCanonicalIsNotAConflict(): void
    {
        $html = <<<HTML
            <html><head>
                <link rel="canonical" href="https://example.com/a">
                <link rel="canonical" href="https://example.com/a">
            </head><body></body></html>
            HTML;

        $signals = self::parse($html);

        $this->assertSame('https://example.com/a', $signals->effectiveCanonicalHref());
    }

    public function testSeparatesCanonicalsFoundOutsideHead(): void
    {
        $html = <<<HTML
            <html><head><title>T</title></head>
            <body>
                <div><link rel="canonical" href="https://example.com/a"></div>
            </body></html>
            HTML;

        $signals = self::parse($html);

        $this->assertSame([], $signals->canonicalHrefs);
        $this->assertSame(['https://example.com/a'], $signals->bodyCanonicalHrefs);
    }

    public function testExtractsMetaRefreshTarget(): void
    {
        $html = <<<HTML
            <html><head>
                <meta http-equiv="refresh" content="0; url=https://example.com/new">
            </head><body></body></html>
            HTML;

        $signals = self::parse($html);

        $this->assertSame('https://example.com/new', $signals->metaRefreshUrl);
    }

    public function testIgnoresMetaRefreshWithoutTarget(): void
    {
        $html = '<html><head><meta http-equiv="refresh" content="30"></head><body></body></html>';

        $this->assertNull(self::parse($html)->metaRefreshUrl);
    }

    public function testReturnsEmptySignalsForEmptyHtml(): void
    {
        $signals = self::parse('   ');

        $this->assertSame([], $signals->canonicalHrefs);
        $this->assertNull($signals->htmlLang);
    }

    public function testMissingLangAttributeIsNull(): void
    {
        $html = '<html><head><title>T</title></head><body></body></html>';

        $this->assertNull(self::parse($html)->htmlLang);
    }

    public function testKeepsCanonicalWithEmptyHref(): void
    {
        $html = '<html><head><link rel="canonical" href=""></head><body></body></html>';

        $this->assertSame([''], self::parse($html)->canonicalHrefs);
    }

    public function testSeparatesHreflangLinksFoundOutsideHead(): void
    {
        $html = <<<HTML
            <html><head>
                <link rel="alternate" hreflang="fr" href="https://example.com/fr">
                <link rel="Alternate" hreflang="en-GB" href="/en">
                <link rel="alternate" type="application/rss+xml" href="/feed.xml">
            </head><body>
                <link rel="alternate" hreflang="de" href="https://example.com/de">
            </body></html>
            HTML;

        $signals = self::parse($html);

        $this->assertCount(2, $signals->hreflangLinks);
        $this->assertCount(1, $signals->bodyHreflangLinks);
        $this->assertSame('de', $signals->bodyHreflangLinks[0]->hreflang);
        $this->assertSame('fr', $signals->hreflangLinks[0]->hreflang);
        $this->assertSame('https://example.com/fr', $signals->hreflangLinks[0]->href);
        $this->assertSame('en-GB', $signals->hreflangLinks[1]->hreflang);
        $this->assertSame('/en', $signals->hreflangLinks[1]->href);
        $this->assertSame(
            [
                'https://example.com/fr' => 'https://example.com/fr',
                'https://example.com/en' => 'https://example.com/en',
            ],
            $signals->hreflangUrls('https://example.com/fr'),
        );
    }

    public function testAnInvalidElementInHeadPushesTheLinksAfterItOutOfHead(): void
    {
        $html = <<<HTML
            <!DOCTYPE html>
            <html><head>
                <title>T</title>
                <link rel="alternate" hreflang="fr" href="https://example.com/fr">
                <div>A tracking snippet pasted in the wrong place</div>
                <link rel="canonical" href="https://example.com/fr">
                <link rel="alternate" hreflang="en" href="https://example.com/en">
            </head><body></body></html>
            HTML;

        $signals = self::parse($html);

        $this->assertSame(['fr'], array_map(static fn($link): string => $link->hreflang, $signals->hreflangLinks));
        $this->assertSame(['en'], array_map(static fn($link): string => $link->hreflang, $signals->bodyHreflangLinks));
        $this->assertSame([], $signals->canonicalHrefs);
        $this->assertSame(['https://example.com/fr'], $signals->bodyCanonicalHrefs);
    }

    /**
     * @dataProvider headBreakerProvider
     */
    public function testAStrayElementInHeadPushesTheCanonicalAfterItOutOfHead(string $breaker): void
    {
        $signals = self::parse(self::pageWithHead($breaker));

        $this->assertSame([], $signals->canonicalHrefs);
        $this->assertSame(['https://example.com/probe'], $signals->bodyCanonicalHrefs);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function headBreakerProvider(): iterable
    {
        yield 'div' => ['<div>stray</div>'];
        yield 'tracking pixel' => ['<img src="https://t.example/p.gif" alt="">'];
        yield 'stray text' => ['oops'];
        yield 'misplaced tag manager iframe' => ['<iframe src="https://gtm.example/ns.html"></iframe>'];
    }

    /**
     * @dataProvider validHeadContentProvider
     */
    public function testValidHeadContentKeepsTheCanonicalInHead(string $content): void
    {
        $signals = self::parse(self::pageWithHead($content));

        $this->assertSame(['https://example.com/probe'], $signals->canonicalHrefs);
        $this->assertSame([], $signals->bodyCanonicalHrefs);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validHeadContentProvider(): iterable
    {
        yield 'meta, base, script and style' => [
            '<meta charset="utf-8"><base href="/"><script>var a = "</div>";</script><style>p{}</style>',
        ];
        yield 'JSON-LD' => ['<script type="application/ld+json">{"a": "<div>"}</script>'];
        yield 'template' => ['<template><div>in template</div></template>'];
        yield 'comment' => ['<!-- a comment -->'];
        // With JavaScript enabled, as a rendering crawler reads the page, <noscript> content is plain text.
        yield 'noscript holding a tracking pixel' => [
            '<noscript><img src="https://t.example/p.gif" alt=""></noscript>',
        ];
    }

    public function testIgnoresSignalsInsideTemplate(): void
    {
        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="fr"><head>
                <template>
                    <link rel="canonical" href="https://example.com/template">
                    <link rel="alternate" hreflang="de" href="https://example.com/de">
                    <meta name="robots" content="noindex">
                    <meta http-equiv="refresh" content="0; url=https://example.com/elsewhere">
                </template>
                <link rel="canonical" href="https://example.com/real">
            </head><body></body></html>
            HTML;

        $signals = self::parse($html);

        $this->assertSame(['https://example.com/real'], $signals->canonicalHrefs);
        $this->assertSame([], $signals->bodyCanonicalHrefs);
        $this->assertSame([], $signals->hreflangLinks);
        $this->assertSame([], $signals->bodyHreflangLinks);
        $this->assertSame([], $signals->metaRobots);
        $this->assertNull($signals->metaRefreshUrl);
        $this->assertSame('fr', $signals->htmlLang);
    }

    public function testKeepsNonAsciiUrlsIntact(): void
    {
        $signals = self::parse(
            '<!DOCTYPE html><html><head><link rel="canonical" href="https://example.com/café"></head>'
            .'<body></body></html>'
        );

        $this->assertSame(['https://example.com/café'], $signals->canonicalHrefs);
    }

    private static function pageWithHead(string $content): string
    {
        return '<!DOCTYPE html><html><head><title>T</title>'.$content
            .'<link rel="canonical" href="https://example.com/probe"></head><body><p>content</p></body></html>';
    }

    // --- On-page content ---

    public function testExtractsTitleAndMetaDescription(): void
    {
        $signals = self::parse(
            '<!DOCTYPE html><html><head><title>  Home Page  </title>'
            .'<meta name="Description" content="A short description."></head><body></body></html>'
        );

        $this->assertSame('Home Page', $signals->title);
        $this->assertSame('A short description.', $signals->metaDescription);
    }

    public function testMissingOrEmptyTitleAndDescriptionAreNull(): void
    {
        foreach (
            [
                '<html><head></head><body><h1>Content</h1></body></html>',
                '<html><head><title>   </title><meta name="description" content="  "></head></html>',
            ] as $html
        ) {
            $signals = self::parse($html);

            $this->assertNull($signals->title, $html);
            $this->assertNull($signals->metaDescription, $html);
        }
    }

    public function testExtractsAllH1Headings(): void
    {
        $this->assertSame(['First', 'Second'], self::parse('<h1>First</h1><p>text</p><h1> Second </h1>')->h1Headings);
    }

    public function testFlagsImagesMissingAltAttribute(): void
    {
        $signals = self::parse(
            '<img src="/logo.png" alt="Logo"><img src="/decorative.png" alt=""><img src="/broken.png">'
        );

        $this->assertSame(['/broken.png'], $signals->imagesMissingAlt);
    }

    public function testIgnoresOnPageContentInsideTemplate(): void
    {
        $signals = self::parse(
            '<!DOCTYPE html><html><head><template><title>Template title</title>'
            .'<meta name="description" content="Template description"></template>'
            .'<title>Real title</title><meta name="description" content="Real description"></head>'
            .'<body><template><h1>Hidden</h1><img src="/hidden.png"><a href="/hidden">Hidden</a></template>'
            .'<h1>Visible</h1><img src="/visible.png"><a href="/visible">Visible</a></body></html>'
        );

        $this->assertSame('Real title', $signals->title);
        $this->assertSame('Real description', $signals->metaDescription);
        $this->assertSame(['Visible'], $signals->h1Headings);
        $this->assertSame(['/visible.png'], $signals->imagesMissingAlt);
        $this->assertSame(['https://example.com/visible'], array_column($signals->links, 'url'));
    }

    // --- Links ---

    public function testResolvesRelativeAndExternalLinks(): void
    {
        $signals = self::parse(
            '<!DOCTYPE html><html><body>'
            .'<a href="/contact">Contact</a>'
            .'<a href="blog/article-1">Article</a>'
            .'<a href="https://externalsite.com/page">External   link</a>'
            .'<a href="mailto:test@example.com">Email</a>'
            .'<a href="tel:+33100000000">Phone</a>'
            .'<a href="javascript:void(0)">Script</a>'
            .'<a href="#section-top">Anchor</a>'
            .'<a href="ftp://example.com/file">FTP</a>'
            .'</body></html>'
        );

        $this->assertCount(3, $signals->links);
        $this->assertSame('https://example.com/contact', $signals->links[0]->url);
        $this->assertSame('Contact', $signals->links[0]->anchorText);
        $this->assertFalse($signals->links[0]->isExternal);
        $this->assertSame('https://example.com/sub/blog/article-1', $signals->links[1]->url);
        $this->assertSame('https://externalsite.com/page', $signals->links[2]->url);
        $this->assertSame('External link', $signals->links[2]->anchorText);
        $this->assertTrue($signals->links[2]->isExternal);
    }

    public function testAMalformedTargetIsNotExternalAndDoesNotThrow(): void
    {
        $links = self::parse('<a href="https://example.com:-1/page">Malformed port</a>')->links;

        $this->assertCount(1, $links);
        $this->assertFalse($links[0]->isExternal);
    }

    public function testLeavesOutExcludedLinks(): void
    {
        $links = (new HtmlPageParser())->parse(
            '<a href="/admin/dashboard">Admin</a><a href="/public/page">Page</a>',
            'https://example.com/',
            ['#/admin#'],
        )->links;

        $this->assertSame(['https://example.com/public/page'], array_column($links, 'url'));
    }

    /**
     * @dataProvider linkResolutionProvider
     */
    public function testResolvesLinkTargets(string $pageUrl, string $html, string $expected): void
    {
        $this->assertSame([$expected], array_column((new HtmlPageParser())->parse($html, $pageUrl)->links, 'url'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function linkResolutionProvider(): iterable
    {
        yield 'parent directories' => [
            'https://example.com/blog/2024/post.html',
            '<a href="../../about">Up</a>',
            'https://example.com/about',
        ];
        yield 'parent directories past the root' => [
            'https://example.com/blog/post.html',
            '<a href="../../../overflow">Up</a>',
            'https://example.com/overflow',
        ];
        yield 'query kept, fragment stripped' => [
            'https://example.com/blog/index.html',
            '<a href="results?sort=asc#top">Results</a>',
            'https://example.com/blog/results?sort=asc',
        ];
        yield 'query-only reference' => [
            'https://example.com/blog/index.html',
            '<a href="?page=2">Next</a>',
            'https://example.com/blog/index.html?page=2',
        ];
        yield 'sibling of a directory-style URL' => [
            'https://example.com/section/',
            '<a href="sibling-page">Sibling</a>',
            'https://example.com/section/sibling-page',
        ];
        yield 'base href' => [
            'https://example.com/somewhere-else/index.html',
            '<base href="https://example.com/base-dir/"><a href="page">Page</a>',
            'https://example.com/base-dir/page',
        ];
        yield 'protocol-relative' => [
            'https://example.com/',
            '<a href="//cdn.example.com/file.pdf">File</a>',
            'https://cdn.example.com/file.pdf',
        ];
    }

    public function testDeduplicatesRepeatedLinks(): void
    {
        $this->assertCount(1, self::parse('<a href="/page">First</a><a href="/page#top">Second</a>')->links);
    }

    private static function parse(string $html): PageSignals
    {
        return (new HtmlPageParser())->parse($html, self::PAGE_URL);
    }
}
