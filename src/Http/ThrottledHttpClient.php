<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Http;

use Generator;
use SplObjectStorage;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

final class ThrottledHttpClient implements HttpClientInterface, ResetInterface, ThrottleExemptionInterface
{
    public const SCHEDULED = 'seo.scheduled';

    private const IDLE_WAIT_US = 1_000;

    private HttpClientInterface $client;
    private readonly HostRateLimiter $rateLimiter;
    private ?string $overrideHost = null;
    private int $leases = 0;

    /** @var array<string, int> host => the lease holding its slot, until the response it belongs to is done */
    private array $heldSlots = [];

    /**
     * @param HostRateLimiter|null $rateLimiter share one to keep a concurrent caller and this client in step
     */
    public function __construct(HttpClientInterface $client, int $delayMs = 0, ?HostRateLimiter $rateLimiter = null)
    {
        $this->client = $client;
        $this->rateLimiter = $rateLimiter ?? new HostRateLimiter($delayMs);
    }

    public function setHostDelay(?string $host, int $delayMs = 0, int $maxInFlight = 1): void
    {
        if ($this->overrideHost !== null) {
            $this->rateLimiter->clearHostLimits($this->overrideHost);
            $this->overrideHost = null;
        }

        if ($host === null) {
            return;
        }

        $this->overrideHost = strtolower($host);
        $this->rateLimiter->setHostLimits($this->overrideHost, $delayMs, $maxInFlight);
    }

    /**
     * @param array<string, mixed> $options
     * @throws Throwable
     * @throws TransportExceptionInterface
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));

        if ($host === '' || ($options['extra'][self::SCHEDULED] ?? false) === true) {
            return $this->client->request($method, $url, $options);
        }

        $lease = $this->awaitSlot($host);

        try {
            $response = $this->client->request($method, $url, $options);
        } catch (Throwable $e) {
            $this->giveBack($host, $lease);

            throw $e;
        }

        return new ThrottledResponse($response, function () use ($host, $lease): void {
            $this->giveBack($host, $lease);
        });
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        if ($responses instanceof ResponseInterface) {
            $responses = [$responses];
        }

        /** @var SplObjectStorage<ResponseInterface, ThrottledResponse> $throttled */
        $throttled = new SplObjectStorage();
        $inner = [];

        foreach ($responses as $response) {
            if ($response instanceof ThrottledResponse) {
                $throttled[$response->response()] = $response;
                $inner[] = $response->response();

                continue;
            }

            $inner[] = $response;
        }

        return new ResponseStream($this->yieldChunks($inner, $throttled, $timeout));
    }

    /**
     * @param list<ResponseInterface> $responses
     * @param SplObjectStorage<ResponseInterface, ThrottledResponse> $throttled
     *
     * @return Generator<ResponseInterface, ChunkInterface>
     */
    private function yieldChunks(array $responses, SplObjectStorage $throttled, ?float $timeout): Generator
    {
        foreach ($this->client->stream($responses, $timeout) as $response => $chunk) {
            yield ($throttled[$response] ?? $response) => $chunk;
        }
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

    private function awaitSlot(string $host): int
    {
        $this->giveBack($host, $this->heldSlots[$host] ?? null);

        while (!$this->rateLimiter->tryAcquire($host)) {
            usleep(max(self::IDLE_WAIT_US, (int)round($this->rateLimiter->waitTimeFor($host) * 1_000_000)));
        }

        return $this->heldSlots[$host] = ++$this->leases;
    }

    private function giveBack(string $host, ?int $lease): void
    {
        if ($lease === null || ($this->heldSlots[$host] ?? null) !== $lease) {
            return;
        }

        unset($this->heldSlots[$host]);
        $this->rateLimiter->release($host);
    }
}
