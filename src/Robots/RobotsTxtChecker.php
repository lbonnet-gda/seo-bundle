<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Robots;

use Lbonnet\SeoBundle\Http\BoundedContentReader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

final class RobotsTxtChecker implements RobotsTxtCheckerInterface, RobotsTxtProviderInterface, ResetInterface
{
    private const MAX_CONTENT_LENGTH = 500_000;
    private const MAX_REDIRECTS = 5;

    /** @var array<string, RobotsTxt> origin (scheme, host and port) => its robots.txt, fetched once per audit */
    private array $robotsTxtByOrigin = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $userAgent,
        private readonly bool $enabled = true,
    ) {
    }

    public function isAllowed(string $url): bool
    {
        if (!$this->enabled) {
            return true;
        }

        if ($this->isSiteBlocked($url)) {
            return false;
        }

        return $this->robotsTxt($url)?->isAllowed($url, $this->userAgent) ?? true;
    }

    public function isSiteBlocked(string $url): bool
    {
        return $this->enabled && $this->robotsTxt($url)?->status === RobotsTxtStatus::ServerError;
    }

    public function crawlDelay(string $url): ?float
    {
        if (!$this->enabled) {
            return null;
        }

        return $this->robotsTxt($url)?->crawlDelay($this->userAgent);
    }

    public function robotsTxt(string $url): ?RobotsTxt
    {
        $origin = self::originOf($url);

        if ($origin === null) {
            return null;
        }

        return $this->robotsTxtByOrigin[$origin] ??= $this->fetch($origin);
    }

    public function reset(): void
    {
        $this->robotsTxtByOrigin = [];
    }

    private static function originOf(string $url): ?string
    {
        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['host']) || $parts['host'] === '') {
            return null;
        }

        return sprintf(
            '%s://%s%s',
            strtolower($parts['scheme'] ?? 'https'),
            strtolower($parts['host']),
            isset($parts['port']) ? ':'.$parts['port'] : '',
        );
    }

    private function fetch(string $origin): RobotsTxt
    {
        $robotsUrl = $origin.'/robots.txt';

        try {
            $response = $this->httpClient->request(Request::METHOD_GET, $robotsUrl, [
                'timeout' => 5,
                'max_redirects' => self::MAX_REDIRECTS,
            ]);
            $statusCode = $response->getStatusCode();

            return match (self::statusFor($statusCode)) {
                RobotsTxtStatus::ServerError => RobotsTxt::serverError($robotsUrl, $statusCode),
                RobotsTxtStatus::NotFound => RobotsTxt::notFound($robotsUrl, $statusCode),
                RobotsTxtStatus::Found => RobotsTxt::parse(
                    $robotsUrl,
                    BoundedContentReader::read($this->httpClient, $response, self::MAX_CONTENT_LENGTH),
                    $statusCode,
                ),
            };
        } catch (Throwable) {
            return RobotsTxt::serverError($robotsUrl, null);
        }
    }

    private static function statusFor(int $statusCode): RobotsTxtStatus
    {
        if ($statusCode === Response::HTTP_TOO_MANY_REQUESTS || $statusCode >= 500) {
            return RobotsTxtStatus::ServerError;
        }

        return $statusCode >= 200 && $statusCode < 300 ? RobotsTxtStatus::Found : RobotsTxtStatus::NotFound;
    }
}
