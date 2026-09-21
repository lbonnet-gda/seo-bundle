<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Model;

final class RedirectChain
{
    /**
     * @param string $startUrl the URL that started the chain
     * @param int $startStatusCode the 3xx status $startUrl answered with
     * @param list<RedirectHop> $hops every redirect that was followed, in order
     * @param string|null $finalUrl the last URL reached, or null if the chain could not be resolved
     * @param int|null $finalStatusCode the status of $finalUrl, or null when it was never requested (loop detected, hop budget exhausted, or transport failure)
     * @param bool $isLoop the chain came back to a URL it had already visited
     * @param bool $truncated the chain was abandoned because it exceeded the follow budget
     */
    public function __construct(
        public readonly string $startUrl,
        public readonly int $startStatusCode,
        public readonly array $hops = [],
        public readonly ?string $finalUrl = null,
        public readonly ?int $finalStatusCode = null,
        public readonly bool $isLoop = false,
        public readonly bool $truncated = false,
    ) {
    }

    public function hopCount(): int
    {
        return count($this->hops);
    }

    public function endsSuccessfully(): bool
    {
        return $this->finalStatusCode !== null && $this->finalStatusCode >= 200 && $this->finalStatusCode < 300;
    }

    public function endsInError(): bool
    {
        return $this->finalStatusCode !== null && $this->finalStatusCode >= 400;
    }

    /**
     * @return list<RedirectHop>
     */
    public function temporaryHops(): array
    {
        return array_values(array_filter($this->hops, static fn(RedirectHop $hop): bool => $hop->isTemporary()));
    }
}
