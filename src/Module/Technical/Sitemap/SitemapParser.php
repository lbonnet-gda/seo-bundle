<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module\Technical\Sitemap;

use XMLReader;

final class SitemapParser
{
    public const MAX_ENTRIES = 50_000;

    public static function parse(string $xml): ParsedSitemap
    {
        if (trim($xml) === '') {
            return new ParsedSitemap(error: 'the file is empty');
        }

        $previousUseErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            return self::read($xml);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseErrors);
        }
    }

    private static function read(string $xml): ParsedSitemap
    {
        $reader = new XMLReader();

        if (!$reader->XML($xml, null, LIBXML_NONET)) {
            return new ParsedSitemap(error: 'the file is not XML');
        }

        $root = null;
        $locations = [];
        $tooManyEntries = false;

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            if ($root === null) {
                $root = $reader->localName;

                if ($root !== 'urlset' && $root !== 'sitemapindex') {
                    return new ParsedSitemap(
                        error: sprintf('its root element is <%s> instead of <urlset> or <sitemapindex>', $root),
                    );
                }

                continue;
            }

            if ($reader->localName !== 'loc' || $reader->depth !== 2) {
                continue;
            }

            if (count($locations) >= self::MAX_ENTRIES) {
                $tooManyEntries = true;

                break;
            }

            $locations[] = trim($reader->readString());
        }

        if ($root === null && !str_starts_with(ltrim($xml), '<')) {
            return new ParsedSitemap(error: 'the file is not XML');
        }

        foreach ($tooManyEntries ? [] : libxml_get_errors() as $error) {
            if ($error->level >= LIBXML_ERR_ERROR) {
                return new ParsedSitemap(error: sprintf('the XML is malformed (%s)', trim($error->message)));
            }
        }

        if ($root === null) {
            return new ParsedSitemap(error: 'the file is not XML');
        }

        return new ParsedSitemap($root === 'sitemapindex', $locations, tooManyEntries: $tooManyEntries);
    }
}
