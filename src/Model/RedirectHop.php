<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Model;

use Symfony\Component\HttpFoundation\Response;

final class RedirectHop
{
    private const TEMPORARY_STATUS_CODES = [
        Response::HTTP_FOUND,
        Response::HTTP_SEE_OTHER,
        Response::HTTP_TEMPORARY_REDIRECT,
    ];

    /**
     * @param string $url the URL that answered with a redirect
     * @param int $statusCode the 3xx status it answered with
     * @param string $location the absolute URL it pointed to
     */
    public function __construct(
        public readonly string $url,
        public readonly int $statusCode,
        public readonly string $location,
    ) {
    }

    public function isTemporary(): bool
    {
        return in_array($this->statusCode, self::TEMPORARY_STATUS_CODES, true);
    }
}
