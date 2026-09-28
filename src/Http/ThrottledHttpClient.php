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
    private const IDLE_WAIT_US = 1_000;

    private HttpClientInterface $client;
    private readonly HostRateLimiter $rateLimiter;
    private ?string $overrideHost = null;

    /** @var array<string, true> hosts whose slot this client is still holding */
    private array $heldSlots = [];

    /**
     * @param HostRateLimiter|null $rateLimiter share one to keep a concurrent caller and this client in step
     */
    public function __construct(HttpClientInterface $client, int $delayMs = 0, ?HostRateLimiter $rateLimiter = null)
    {
        $this->client = $client;
        $this->rateLimiter = $rateLimiter ?? new HostRateLimiter($delayMs);
    }

    public function setHostDelay(?string $host, int $delayMs = 0): void
    {
        if ($this->overrideHost !== null) {
            $this->rateLimiter->clearHostLimits($this->overrideHost);
            $this->overrideHost = null;
        }

        if ($host === null) {
            return;
        }

        $this->overrideHost = strtolower($host);
        $this->rateLimiter->setHostLimits($this->overrideHost, $delayMs);
    }

    /**
     * @param array<string, mixed> $options
     * @throws TransportExceptionInterface
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));

        if ($host !== '') {
            $this->awaitSlot($host);
        }

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

        $this->heldSlots = [];
        $this->rateLimiter->reset();
    }

    private function awaitSlot(string $host): void
    {
        if (isset($this->heldSlots[$host])) {
            unset($this->heldSlots[$host]);
            $this->rateLimiter->release($host);
        }

        while (!$this->rateLimiter->tryAcquire($host)) {
            usleep(max(self::IDLE_WAIT_US, (int)round($this->rateLimiter->waitTimeFor($host) * 1_000_000)));
        }

        $this->heldSlots[$host] = true;
    }
}
