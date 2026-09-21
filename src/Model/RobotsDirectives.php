<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Model;

final class RobotsDirectives
{
    /**
     * @param list<string> $directives lower-cased, trimmed tokens (e.g. ['noindex', 'nofollow'])
     */
    public function __construct(
        public readonly array $directives = [],
    ) {
    }

    /**
     * @param list<string> $values
     */
    public static function parse(array $values): self
    {
        $directives = [];

        foreach ($values as $value) {
            foreach (explode(',', $value) as $token) {
                $token = strtolower(trim($token));

                if (str_contains($token, ':')) {
                    $token = trim(substr($token, (int)strpos($token, ':') + 1));
                }

                if ($token === '' || in_array($token, $directives, true)) {
                    continue;
                }

                $directives[] = $token;
            }
        }

        return new self($directives);
    }

    public function hasNoindex(): bool
    {
        return $this->has('noindex') || $this->has('none');
    }

    public function hasIndex(): bool
    {
        return $this->has('index') && !$this->hasNoindex();
    }

    private function has(string $directive): bool
    {
        return in_array($directive, $this->directives, true);
    }
}
