<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Http;

use Closure;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ThrottledResponse implements ResponseInterface
{
    /** @var (Closure(): void)|null */
    private ?Closure $release;

    /**
     * @param Closure(): void $release called once, as soon as the host can be called again
     */
    public function __construct(private readonly ResponseInterface $response, Closure $release)
    {
        $this->release = $release;
    }

    public function __destruct()
    {
        $this->finish();
    }

    public function response(): ResponseInterface
    {
        return $this->response;
    }

    public function finish(): void
    {
        $release = $this->release;
        $this->release = null;
        $release?->__invoke();
    }

    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }

    /**
     * @return array<string, list<string>>
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    public function getHeaders(bool $throw = true): array
    {
        /** @var array<string, list<string>> $headers */
        $headers = $this->response->getHeaders($throw);

        return $headers;
    }

    public function getContent(bool $throw = true): string
    {
        try {
            return $this->response->getContent($throw);
        } finally {
            $this->finish();
        }
    }

    /**
     * @return array<array-key, mixed>
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     * @throws DecodingExceptionInterface
     */
    public function toArray(bool $throw = true): array
    {
        try {
            return $this->response->toArray($throw);
        } finally {
            $this->finish();
        }
    }

    public function cancel(): void
    {
        try {
            $this->response->cancel();
        } finally {
            $this->finish();
        }
    }

    public function getInfo(?string $type = null): mixed
    {
        return $this->response->getInfo($type);
    }
}
