<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Http;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;

final class ThrottledHttpClient implements HttpClientInterface, ResetInterface, ThrottleExemptionInterface
{
    private HttpClientInterface $client;
    private int $delayMs;
    private ?string $overrideHost = null;
    private int $overrideDelayMs = 0;

    /** @var array<string, float> host => microtime() of the last request start */
    private array $lastRequestAt = [];

    public function __construct(HttpClientInterface $client, int $delayMs = 0)
    {
        $this->client = $client;
        $this->delayMs = $delayMs;
    }

    public function setHostDelay(?string $host, int $delayMs = 0): void
    {
        $this->overrideHost = $host !== null ? strtolower($host) : null;
        $this->overrideDelayMs = $delayMs;
    }

    /**
     * @param array<string, mixed> $options
     * @throws TransportExceptionInterface
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->throttle($url);

        return $this->client->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->client = $this->client->withOptions($options);

        return $clone;
    }

    public function reset(): void
    {
        if ($this->client instanceof ResetInterface) {
            $this->client->reset();
        }

        $this->lastRequestAt = [];
    }

    private function throttle(string $url): void
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return;
        }

        $delayMs = $host === $this->overrideHost ? $this->overrideDelayMs : $this->delayMs;

        if ($delayMs > 0) {
            $elapsedMs = (microtime(true) - ($this->lastRequestAt[$host] ?? 0.0)) * 1000;
            $remainingMs = $delayMs - $elapsedMs;

            if ($remainingMs > 0) {
                usleep((int)round($remainingMs * 1000));
            }
        }

        $this->lastRequestAt[$host] = microtime(true);
    }
}
