<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Html;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Lbonnet\SeoBundle\Model\HreflangLink;
use Lbonnet\SeoBundle\Model\PageLink;
use Lbonnet\SeoBundle\Model\PageSignals;
use Lbonnet\SeoBundle\Url\UrlResolver;

final class HtmlPageParser
{
    /**
     * @param string $pageUrl the URL the page was served from, to resolve its links against
     * @param list<string> $excludePatterns regex patterns of link targets to leave out
     */
    public function parse(string $html, string $pageUrl, array $excludePatterns = []): PageSignals
    {
        if (trim($html) === '') {
            return new PageSignals();
        }

        $xpath = new DOMXPath(self::document($html));

        $canonicalHrefs = [];
        $bodyCanonicalHrefs = [];
        $hreflangLinks = [];
        $bodyHreflangLinks = [];

        foreach (self::elements($xpath, '//link[@rel]') as $element) {
            if (self::isHreflangLink($element)) {
                $link = new HreflangLink(
                    trim($element->getAttribute('hreflang')),
                    trim($element->getAttribute('href')),
                );

                if (self::hasAncestor($element, 'head')) {
                    $hreflangLinks[] = $link;
                } else {
                    $bodyHreflangLinks[] = $link;
                }

                continue;
            }

            if (strcasecmp(trim($element->getAttribute('rel')), 'canonical') !== 0) {
                continue;
            }

            $href = trim($element->getAttribute('href'));

            if (self::hasAncestor($element, 'head')) {
                $canonicalHrefs[] = $href;

                continue;
            }

            $bodyCanonicalHrefs[] = $href;
        }

        return new PageSignals(
            canonicalHrefs: $canonicalHrefs,
            bodyCanonicalHrefs: $bodyCanonicalHrefs,
            metaRobots: $this->extractMetaRobots($xpath),
            metaRefreshUrl: $this->extractMetaRefreshUrl($xpath),
            htmlLang: $this->extractHtmlLang($xpath),
            hreflangLinks: $hreflangLinks,
            bodyHreflangLinks: $bodyHreflangLinks,
            title: $this->extractTitle($xpath),
            metaDescription: $this->extractMetaDescription($xpath),
            h1Headings: array_map(
                static fn(DOMElement $element): string => trim($element->textContent),
                self::elements($xpath, '//h1'),
            ),
            imagesMissingAlt: array_map(
                static fn(DOMElement $element): string => trim($element->getAttribute('src')),
                array_values(
                    array_filter(
                        self::elements($xpath, '//img'),
                        static fn(DOMElement $element): bool => !$element->hasAttribute('alt'),
                    )
                ),
            ),
            links: $this->extractLinks($xpath, $pageUrl, $excludePatterns),
        );
    }

    private function extractTitle(DOMXPath $xpath): ?string
    {
        $titleElement = self::elements($xpath, '//title')[0] ?? null;
        $title = $titleElement !== null ? trim($titleElement->textContent) : '';

        return $title !== '' ? $title : null;
    }

    private function extractMetaDescription(DOMXPath $xpath): ?string
    {
        foreach (self::elements($xpath, '//meta[@name]') as $element) {
            if (strcasecmp(trim($element->getAttribute('name')), 'description') !== 0) {
                continue;
            }

            $content = trim($element->getAttribute('content'));

            return $content !== '' ? $content : null;
        }

        return null;
    }

    /**
     * @param list<string> $excludePatterns
     *
     * @return list<PageLink>
     */
    private function extractLinks(DOMXPath $xpath, string $pageUrl, array $excludePatterns): array
    {
        $baseUrl = $pageUrl;
        $baseElement = self::elements($xpath, '//base[@href]')[0] ?? null;

        if ($baseElement !== null) {
            $baseUrl = UrlResolver::resolve($pageUrl, $baseElement->getAttribute('href')) ?? $pageUrl;
        }

        $pageHost = parse_url($pageUrl, PHP_URL_HOST);
        $links = [];

        foreach (self::elements($xpath, '//a[@href]') as $element) {
            $href = trim($element->getAttribute('href'));

            if (self::isIgnoredHref($href)) {
                continue;
            }

            $url = UrlResolver::resolve($baseUrl, $href);
            $url = $url !== null ? (string)preg_replace('/#.*$/s', '', $url) : null;

            if ($url === null || !UrlResolver::isAbsoluteHttpUrl($url) || isset($links[$url])) {
                continue;
            }

            if (self::isExcluded($url, $excludePatterns)) {
                continue;
            }

            $host = parse_url($url, PHP_URL_HOST);

            $links[$url] = new PageLink(
                url: $url,
                anchorText: trim((string)preg_replace('/\s+/u', ' ', $element->textContent)),
                isExternal: is_string($host) && is_string($pageHost) && strcasecmp($host, $pageHost) !== 0,
            );
        }

        return array_values($links);
    }

    private static function isIgnoredHref(string $href): bool
    {
        if ($href === '' || str_starts_with($href, '#')) {
            return true;
        }

        return preg_match('#^[a-z][a-z0-9+.-]*:#i', $href) === 1 && preg_match('#^https?:#i', $href) !== 1;
    }

    /**
     * @param list<string> $patterns
     */
    private static function isExcluded(string $url, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (@preg_match($pattern, $url) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function document(string $html): DOMDocument
    {
        $document = new DOMDocument();
        $internalErrors = libxml_use_internal_errors(true);

        $document->loadHTML(mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8'));

        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);

        return $document;
    }

    /**
     * @return list<DOMElement>
     */
    private static function elements(DOMXPath $xpath, string $expression): array
    {
        $elements = [];

        foreach ($xpath->query($expression) ?: [] as $node) {
            if ($node instanceof DOMElement && !self::hasAncestor($node, 'template')) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    private static function isHreflangLink(DOMElement $element): bool
    {
        if (!$element->hasAttribute('hreflang')) {
            return false;
        }

        $relTokens = preg_split('/\s+/', strtolower(trim($element->getAttribute('rel')))) ?: [];

        return in_array('alternate', $relTokens, true);
    }

    private static function hasAncestor(DOMNode $node, string $name): bool
    {
        for ($parent = $node->parentNode; $parent !== null; $parent = $parent->parentNode) {
            if (strcasecmp($parent->nodeName, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function extractMetaRobots(DOMXPath $xpath): array
    {
        $values = [];

        foreach (self::elements($xpath, '//meta[@name]') as $element) {
            if (strcasecmp(trim($element->getAttribute('name')), 'robots') !== 0) {
                continue;
            }

            $content = trim($element->getAttribute('content'));

            if ($content === '') {
                continue;
            }

            $values[] = $content;
        }

        return $values;
    }

    private function extractMetaRefreshUrl(DOMXPath $xpath): ?string
    {
        foreach (self::elements($xpath, '//meta[@http-equiv]') as $element) {
            if (strcasecmp(trim($element->getAttribute('http-equiv')), 'refresh') !== 0) {
                continue;
            }

            if (preg_match('#url\s*=\s*[\'"]?([^\'";]+)#i', $element->getAttribute('content'), $matches) === 1) {
                $url = trim($matches[1]);

                if ($url !== '') {
                    return $url;
                }
            }
        }

        return null;
    }

    private function extractHtmlLang(DOMXPath $xpath): ?string
    {
        $htmlElement = self::elements($xpath, '/html')[0] ?? null;
        $lang = $htmlElement !== null ? trim($htmlElement->getAttribute('lang')) : '';

        return $lang !== '' ? $lang : null;
    }
}
