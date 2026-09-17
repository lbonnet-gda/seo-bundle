<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Robots;

final class RobotsTxt
{
    /**
     * @param array<string, array{rules: list<array{pattern: string, allow: bool}>, crawlDelay: float|null}> $groups
     * @param list<string> $sitemaps
     */
    private function __construct(
        public readonly string $url,
        public readonly RobotsTxtStatus $status,
        public readonly ?int $statusCode,
        private readonly array $groups = [],
        private readonly array $sitemaps = [],
    ) {
    }

    public static function parse(string $url, string $content, int $statusCode): self
    {
        /**
         * @var array<string, array{rules: list<array{pattern: string, allow: bool}>, crawlDelay: float|null}> $groups
         */
        $groups = [];
        $sitemaps = [];
        $agents = [];
        $rules = [];
        $crawlDelay = null;
        $collectingAgents = true;

        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $line = trim((string)preg_replace('/#.*/', '', $line));
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'sitemap') {
                if ($value !== '' && !in_array($value, $sitemaps, true)) {
                    $sitemaps[] = $value;
                }
                continue;
            }

            if ($field === 'user-agent') {
                if (!$collectingAgents) {
                    $groups = self::commitGroup($groups, $agents, $rules, $crawlDelay);
                    $agents = [];
                    $rules = [];
                    $crawlDelay = null;
                }

                $token = self::productToken($value);
                if ($token !== null) {
                    $agents[] = $token;
                }

                $collectingAgents = true;
                continue;
            }

            if ($field === 'crawl-delay') {
                $collectingAgents = false;

                if (is_numeric($value)) {
                    $crawlDelay = (float)$value;
                }
                continue;
            }

            if (!in_array($field, ['allow', 'disallow'], true)) {
                continue;
            }

            $collectingAgents = false;

            if ($field === 'disallow' && $value === '') {
                continue; // an empty Disallow means "no restriction"
            }

            $rules[] = ['pattern' => $value, 'allow' => $field === 'allow'];
        }

        return new self(
            $url,
            RobotsTxtStatus::Found,
            $statusCode,
            self::commitGroup($groups, $agents, $rules, $crawlDelay),
            $sitemaps,
        );
    }

    public static function notFound(string $url, int $statusCode): self
    {
        return new self($url, RobotsTxtStatus::NotFound, $statusCode);
    }

    public static function serverError(string $url, ?int $statusCode): self
    {
        return new self($url, RobotsTxtStatus::ServerError, $statusCode);
    }

    public function isAllowed(string $url, string $userAgent): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $query = parse_url($url, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $path .= '?'.$query;
        }

        $bestLength = -1;
        $allowed = true;

        foreach ($this->groupFor($userAgent)['rules'] as $rule) {
            if (!self::matchesPattern($path, $rule['pattern'])) {
                continue;
            }

            $length = strlen($rule['pattern']);
            if ($length > $bestLength || ($length === $bestLength && $rule['allow'])) {
                $bestLength = $length;
                $allowed = $rule['allow'];
            }
        }

        return $allowed;
    }

    public function crawlDelay(string $userAgent): ?float
    {
        return $this->groupFor($userAgent)['crawlDelay'];
    }

    /**
     * @return list<string>
     */
    public function sitemaps(): array
    {
        return $this->sitemaps;
    }

    /**
     * @return array{rules: list<array{pattern: string, allow: bool}>, crawlDelay: float|null}
     */
    private function groupFor(string $userAgent): array
    {
        $userAgent = strtolower($userAgent);
        $best = null;

        foreach (array_keys($this->groups) as $token) {
            if ($token === '*' || preg_match('/(?<![a-z_-])'.preg_quote($token, '/').'(?![a-z_])/', $userAgent) !== 1) {
                continue;
            }

            if ($best === null || strlen($token) > strlen($best)) {
                $best = $token;
            }
        }

        return $this->groups[$best ?? '*'] ?? ['rules' => [], 'crawlDelay' => null];
    }

    private static function productToken(string $value): ?string
    {
        $value = strtolower($value);

        if (str_starts_with($value, '*')) {
            return '*';
        }

        return preg_match('/^[a-z_-]+/', $value, $matches) === 1 ? $matches[0] : null;
    }

    /**
     * @param array<string, array{rules: list<array{pattern: string, allow: bool}>, crawlDelay: float|null}> $groups
     * @param list<string> $agents
     * @param list<array{pattern: string, allow: bool}> $rules
     *
     * @return array<string, array{rules: list<array{pattern: string, allow: bool}>, crawlDelay: float|null}>
     */
    private static function commitGroup(array $groups, array $agents, array $rules, ?float $crawlDelay): array
    {
        foreach ($agents as $agent) {
            $existing = $groups[$agent] ?? ['rules' => [], 'crawlDelay' => null];
            $groups[$agent] = [
                'rules' => array_merge($existing['rules'], $rules),
                'crawlDelay' => $crawlDelay ?? $existing['crawlDelay'],
            ];
        }

        return $groups;
    }

    private static function matchesPattern(string $path, string $pattern): bool
    {
        $endAnchor = str_ends_with($pattern, '$');
        $rawPattern = $endAnchor ? substr($pattern, 0, -1) : $pattern;

        $regex = '#^'.str_replace('\*', '.*', preg_quote($rawPattern, '#')).($endAnchor ? '$' : '').'#';

        return preg_match($regex, $path) === 1;
    }
}
