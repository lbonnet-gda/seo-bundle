<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Robots;

/**
 * What a robots.txt response means to a crawler, following
 * https://developers.google.com/crawling/docs/robots-txt/robots-txt-spec.
 */
enum RobotsTxtStatus: string
{
    /** Fetched with a 2xx: its rules apply. */
    case Found = 'found';

    /** A 4xx other than 429, or a redirect chain too long to follow: there are no crawl restrictions. */
    case NotFound = 'not_found';

    /**
     * A 5xx, a 429, or a network failure (timeout, DNS, reset connection): Google stops crawling the whole site until
     * it can fetch the file again and falls back to its last cached copy after 12 hours.
     */
    case ServerError = 'server_error';
}
