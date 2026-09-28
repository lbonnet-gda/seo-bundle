<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Url;

final class UrlResolver
{
    public static function resolve(string $baseUrl, string $reference): ?string
    {
        $reference = trim($reference);

        if ($reference === '') {
            return null;
        }

        if (self::isAbsoluteHttpUrl($reference)) {
            return $reference;
        }

        $parsedBase = parse_url($baseUrl);

        if (!is_array($parsedBase) || !isset($parsedBase['host'])) {
            return null;
        }

        $scheme = $parsedBase['scheme'] ?? 'https';
        $authority = $parsedBase['host'].(isset($parsedBase['port']) ? ':'.$parsedBase['port'] : '');
        $basePath = $parsedBase['path'] ?? '/';

        if (str_starts_with($reference, '//')) {
            return $scheme.':'.$reference;
        }

        if (str_starts_with($reference, '?')) {
            return sprintf('%s://%s%s%s', $scheme, $authority, $basePath, $reference);
        }

        if (str_starts_with($reference, '/')) {
            return sprintf('%s://%s%s', $scheme, $authority, self::normalizePath($reference));
        }

        $directory = preg_replace('#/[^/]*$#', '/', $basePath);

        return sprintf('%s://%s%s', $scheme, $authority, self::normalizePath(((string)$directory).$reference));
    }

    public static function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    public static function isAbsoluteHttpUrl(string $url): bool
    {
        return preg_match('#^https?://[^/?\#]+#i', $url) === 1;
    }

    public static function dedupKey(string $url): string
    {
        $normalized = preg_replace('#^http://#i', 'https://', $url, 1) ?? $url;
        $parsed = parse_url($normalized);

        if (!is_array($parsed) || !isset($parsed['host'])) {
            return $normalized;
        }

        $userInfo = '';

        if (isset($parsed['user'])) {
            $userInfo = $parsed['user'].(isset($parsed['pass']) ? ':'.$parsed['pass'] : '').'@';
        }

        $path = $parsed['path'] ?? '';

        return sprintf(
            '%s://%s%s%s%s%s',
            strtolower($parsed['scheme'] ?? 'https'),
            $userInfo,
            strtolower($parsed['host']),
            isset($parsed['port']) ? ':'.$parsed['port'] : '',
            $path === '' ? '/' : $path,
            isset($parsed['query']) ? '?'.$parsed['query'] : '',
        );
    }

    public static function isSameUrl(string $url, string $otherUrl): bool
    {
        return self::exactKey($url) === self::exactKey($otherUrl);
    }

    public static function exactKey(string $url): string
    {
        return strtolower((string)parse_url($url, PHP_URL_SCHEME)).'|'.self::dedupKey($url);
    }

    private static function normalizePath(string $path): string
    {
        $queryPosition = strpos($path, '?');
        $query = '';

        if ($queryPosition !== false) {
            $query = substr($path, $queryPosition);
            $path = substr($path, 0, $queryPosition);
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        $normalized = '/'.implode('/', $segments);

        if ($segments !== [] && str_ends_with($path, '/')) {
            $normalized .= '/';
        }

        return $normalized.$query;
    }
}
