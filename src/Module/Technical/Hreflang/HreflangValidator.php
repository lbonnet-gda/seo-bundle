<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module\Technical\Hreflang;

final class HreflangValidator
{
    public const X_DEFAULT = 'x-default';

    public static function explain(string $value): ?string
    {
        if (trim($value) === '') {
            return 'the value is empty';
        }

        if (strcasecmp($value, self::X_DEFAULT) === 0) {
            return null;
        }

        if (str_contains($value, '_')) {
            return 'subtags must be separated by a hyphen, not an underscore';
        }

        $subtags = explode('-', $value);
        $language = strtolower($subtags[0]);

        if (!isset(IsoCodes::LANGUAGES[$language])) {
            return isset(IsoCodes::REGIONS[strtoupper($language)])
                ? sprintf('"%s" is not an ISO 639-1 language code, and a region cannot be used on its own', $language)
                : sprintf('"%s" is not an ISO 639-1 language code', $language);
        }

        $position = 1;

        if (isset($subtags[$position]) && preg_match('/^[a-z]{4}$/i', $subtags[$position]) === 1) {
            $script = ucfirst(strtolower($subtags[$position]));

            if (!isset(IsoCodes::SCRIPTS[$script])) {
                return sprintf('"%s" is not an ISO 15924 script code', $script);
            }

            $position++;
        }

        if (isset($subtags[$position])) {
            $region = strtoupper($subtags[$position]);

            if ($region === 'UK') {
                return '"UK" is not an ISO 3166-1 region code, the United Kingdom is "GB"';
            }

            if (!isset(IsoCodes::REGIONS[$region])) {
                return sprintf('"%s" is not an officially assigned ISO 3166-1 alpha-2 region code', $region);
            }

            $position++;
        }

        if (isset($subtags[$position])) {
            return 'it has more subtags than a language, a script and a region';
        }

        return null;
    }
}
