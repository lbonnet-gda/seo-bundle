<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Url;

final class UrlPattern
{
    public static function isValid(string $pattern): bool
    {
        return @preg_match($pattern, '') !== false;
    }

    /**
     * @param list<string> $patterns
     *
     * @return list<string> the patterns that are not valid regexes
     */
    public static function invalidOnes(array $patterns): array
    {
        return array_values(array_filter($patterns, static fn(string $pattern): bool => !self::isValid($pattern)));
    }

    /**
     * @param list<string> $patterns
     */
    public static function matchesAny(string $url, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (@preg_match($pattern, $url) === 1) {
                return true;
            }
        }

        return false;
    }
}
