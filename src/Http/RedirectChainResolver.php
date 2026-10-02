<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Http;

use Lbonnet\SeoBundle\Model\PageResponse;
use Lbonnet\SeoBundle\Model\RedirectChain;
use Lbonnet\SeoBundle\Model\RedirectHop;
use Lbonnet\SeoBundle\Url\UrlResolver;

final class RedirectChainResolver implements RedirectChainResolverInterface
{
    public function __construct(
        private readonly PageFetcher $pageFetcher,
        private readonly int $maxFollowedHops = 10,
    ) {
    }

    public function resolve(PageResponse $response, bool $paced = false): RedirectChain
    {
        $startUrl = $response->url;
        $startStatusCode = $response->statusCode;
        /** @var list<RedirectHop> $hops */
        $hops = [];
        // Keyed on the exact URL: http:// and https:// share a dedup key, and a site moving to https is not a loop.
        $seen = [UrlResolver::exactKey($startUrl) => true];
        $current = $response;

        while (true) {
            $location = $this->nextLocation($current);

            if ($location === null) {
                return new RedirectChain($startUrl, $startStatusCode, $hops, $current->url, $current->statusCode);
            }

            $hops[] = new RedirectHop($current->url, $current->statusCode, $location);
            $key = UrlResolver::exactKey($location);

            if (isset($seen[$key])) {
                return new RedirectChain($startUrl, $startStatusCode, $hops, $location, null, isLoop: true);
            }

            $seen[$key] = true;

            if (count($hops) >= $this->maxFollowedHops) {
                return new RedirectChain($startUrl, $startStatusCode, $hops, $location, null, truncated: true);
            }

            $next = $this->pageFetcher->fetch($location, readHtml: false, paced: $paced);

            if ($next === null) {
                return new RedirectChain($startUrl, $startStatusCode, $hops, $location);
            }

            if (!$next->isRedirect()) {
                return new RedirectChain($startUrl, $startStatusCode, $hops, $location, $next->statusCode);
            }

            $current = $next;
        }
    }

    private function nextLocation(PageResponse $response): ?string
    {
        if ($response->redirectLocation !== null && UrlResolver::isAbsoluteHttpUrl($response->redirectLocation)) {
            return $response->redirectLocation;
        }

        $location = $response->headers['location'][0] ?? null;

        if (!is_string($location) || trim($location) === '') {
            return null;
        }

        return UrlResolver::resolve($response->url, $location);
    }
}
