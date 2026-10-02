<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Http;

use Lbonnet\SeoBundle\Robots\RobotsTxtCheckerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SiteThrottleExemption
{
    private ?string $host = null;

    private function __construct(
        private readonly ?ThrottleExemptionInterface $client,
        private readonly ?RobotsTxtCheckerInterface $robotsTxtChecker,
        private readonly int $concurrency,
    ) {
    }

    public static function begin(
        HttpClientInterface $httpClient,
        string $siteUrl,
        ?RobotsTxtCheckerInterface $robotsTxtChecker = null,
        int $concurrency = 1,
    ): self {
        $exemption = new self(
            $httpClient instanceof ThrottleExemptionInterface ? $httpClient : null,
            $robotsTxtChecker,
            $concurrency,
        );
        $exemption->moveTo($siteUrl);

        return $exemption;
    }

    public function moveTo(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);

        if ($this->client === null || !is_string($host) || $host === '') {
            return;
        }

        if ($this->host !== null && strcasecmp($host, $this->host) === 0) {
            return;
        }

        $crawlDelay = $this->robotsTxtChecker?->crawlDelay($url);

        $this->client->setHostDelay(
            $host,
            $crawlDelay !== null ? (int)round($crawlDelay * 1000) : 0,
            $crawlDelay !== null ? 1 : $this->concurrency,
        );
        $this->host = $host;
    }

    public function end(): void
    {
        if ($this->client === null || $this->host === null) {
            return;
        }

        $this->client->setHostDelay(null);
        $this->host = null;
    }
}
