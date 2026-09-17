<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Http;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class BoundedContentReader
{
    /**
     * @throws TransportExceptionInterface
     */
    public static function read(HttpClientInterface $httpClient, ResponseInterface $response, int $maxLength): string
    {
        $content = '';

        foreach ($httpClient->stream($response) as $chunk) {
            $content .= $chunk->getContent();

            if (strlen($content) > $maxLength) {
                $response->cancel();
                break;
            }
        }

        return $content;
    }
}
