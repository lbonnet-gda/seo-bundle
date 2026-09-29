<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module\Links;

use Lbonnet\SeoBundle\Http\HostRateLimiter;
use Lbonnet\SeoBundle\Http\PendingPage;
use Lbonnet\SeoBundle\Http\ThrottledHttpClient;
use Lbonnet\SeoBundle\Model\PageResponse;
use Lbonnet\SeoBundle\Module\Links\Model\BotProvider;
use Lbonnet\SeoBundle\Module\Links\Model\CheckResult;
use Lbonnet\SeoBundle\SeoBundle;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class UrlChecker implements UrlCheckerInterface
{
    private const IDLE_WAIT_US = 1_000;

    private const FALLBACK_STATUS_CODES = [
        Response::HTTP_BAD_REQUEST,
        Response::HTTP_FORBIDDEN,
        Response::HTTP_NOT_FOUND,
        Response::HTTP_METHOD_NOT_ALLOWED,
        Response::HTTP_NOT_ACCEPTABLE,
        Response::HTTP_NOT_IMPLEMENTED,
    ];

    private const BOT_PROTECTION_STATUS_CODES = [
        Response::HTTP_FORBIDDEN,
        Response::HTTP_TOO_MANY_REQUESTS,
        Response::HTTP_SERVICE_UNAVAILABLE,
    ];

    /**
     * @var list<array{provider: BotProvider, header: string, needle: string}>
     */
    private const BOT_PROTECTION_SIGNATURES = [
        ['provider' => BotProvider::Akamai, 'header' => 'server-timing', 'needle' => 'ak_p'],
        ['provider' => BotProvider::Akamai, 'header' => 'server', 'needle' => 'akamaighost'],
        ['provider' => BotProvider::Cloudflare, 'header' => 'cf-ray', 'needle' => ''],
        ['provider' => BotProvider::Cloudflare, 'header' => 'cf-mitigated', 'needle' => ''],
        ['provider' => BotProvider::Sucuri, 'header' => 'x-sucuri-id', 'needle' => ''],
        ['provider' => BotProvider::Incapsula, 'header' => 'x-iinfo', 'needle' => ''],
        ['provider' => BotProvider::DataDome, 'header' => 'x-datadome', 'needle' => ''],
    ];

    private readonly HostRateLimiter $rateLimiter;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly int $defaultTimeout = 10,
        private readonly string $userAgent = SeoBundle::DEFAULT_USER_AGENT,
        ?HostRateLimiter $rateLimiter = null,
        private readonly int $concurrency = 4,
    ) {
        $this->rateLimiter = $rateLimiter ?? new HostRateLimiter();
    }

    public function check(string $url, ?int $timeout = null): CheckResult
    {
        return $this->checkMany([$url], $timeout)[$url];
    }

    /**
     * @param list<string> $urls
     *
     * @return array<string, CheckResult> keyed by URL, in the order they were given
     */
    public function checkMany(array $urls, ?int $timeout = null): array
    {
        $requestTimeout = $timeout ?? $this->defaultTimeout;
        /** @var array<string, CheckResult> $results */
        $results = [];
        /** @var list<array{url: string, method: string}> $queue */
        $queue = array_map(
            static fn(string $url): array => ['url' => $url, 'method' => Request::METHOD_HEAD],
            array_values(array_unique($urls)),
        );
        /** @var array<int, array{url: string, host: string, method: string, pending: PendingPage}> $inFlight */
        $inFlight = [];

        while ($queue !== [] || $inFlight !== []) {
            $waiting = [];

            foreach ($queue as $item) {
                $host = self::hostOf($item['url']);

                $full = count($inFlight) >= $this->concurrency;

                if ($full || ($host !== '' && !$this->rateLimiter->tryAcquire($host))) {
                    $waiting[] = $item;

                    continue;
                }

                $sent = $this->send($item['url'], $item['method'], $requestTimeout);

                if ($sent instanceof CheckResult) {
                    $this->releaseHost($host);
                    $results[$item['url']] = $sent;

                    continue;
                }

                $inFlight[spl_object_id($sent->response)] = [
                    'url' => $item['url'],
                    'host' => $host,
                    'method' => $item['method'],
                    'pending' => $sent,
                ];
            }

            $queue = $waiting;

            if ($inFlight === []) {
                usleep(self::IDLE_WAIT_US);

                continue;
            }

            $this->advance($inFlight, $queue, $results, $requestTimeout);
        }

        $ordered = [];

        foreach ($urls as $url) {
            if (isset($results[$url])) {
                $ordered[$url] = $results[$url];
            }
        }

        return $ordered;
    }

    /**
     * @param array<int, array{url: string, host: string, method: string, pending: PendingPage}> $inFlight
     * @param list<array{url: string, method: string}> $queue
     * @param array<string, CheckResult> $results
     */
    private function advance(array &$inFlight, array &$queue, array &$results, int $timeout): void
    {
        $responses = array_map(static fn(array $entry): object => $entry['pending']->response, $inFlight);

        foreach ($this->httpClient->stream($responses, $timeout) as $response => $chunk) {
            $entry = $inFlight[spl_object_id($response)];

            try {
                if (!$entry['pending']->consume($chunk)) {
                    continue;
                }

                $outcome = $this->outcome($entry['url'], $entry['method'], $entry['pending']->result());
            } catch (Throwable $e) {
                $entry['pending']->abandon();
                $outcome = self::unreachable($entry['url'], $e);
            }

            unset($inFlight[spl_object_id($response)]);
            $this->releaseHost($entry['host']);

            if ($outcome instanceof CheckResult) {
                $results[$entry['url']] = $outcome;
            } else {
                $queue[] = $outcome;
            }

            return;
        }
    }

    /**
     * @return CheckResult|array{url: string, method: string} the answer, or the retry it calls for
     */
    private function outcome(string $url, string $method, ?PageResponse $page): CheckResult|array
    {
        if ($page === null) {
            return new CheckResult(url: $url, errorMessage: 'No answer');
        }

        if ($method === Request::METHOD_HEAD && in_array($page->statusCode, self::FALLBACK_STATUS_CODES, true)) {
            return ['url' => $url, 'method' => Request::METHOD_GET];
        }

        $blockedBy = self::detectBotProtection($page->statusCode, $page->headers);

        return new CheckResult(
            url: $url,
            statusCode: $page->statusCode,
            likelyBlocked: $blockedBy !== null,
            blockedBy: $blockedBy,
        );
    }

    private function send(string $url, string $method, int $timeout): PendingPage|CheckResult
    {
        $options = [
            'timeout' => $timeout,
            'max_redirects' => 5,
            'headers' => ['User-Agent' => $this->userAgent],
            'extra' => [ThrottledHttpClient::SCHEDULED => true],
        ];

        if ($method === Request::METHOD_GET) {
            $options['headers']['Range'] = 'bytes=0-1024';
        }

        try {
            $response = $this->httpClient->request($method, $url, $options);
        } catch (Throwable $e) {
            return self::unreachable($url, $e);
        }

        return new PendingPage($url, 0, $response, false, 0);
    }

    private function releaseHost(string $host): void
    {
        if ($host !== '') {
            $this->rateLimiter->release($host);
        }
    }

    private static function unreachable(string $url, Throwable $e): CheckResult
    {
        $message = $e instanceof TransportExceptionInterface ? $e->getMessage() : 'Unexpected error: '.$e->getMessage();

        return new CheckResult(url: $url, errorMessage: $message);
    }

    private static function hostOf(string $url): string
    {
        return strtolower((string)parse_url($url, PHP_URL_HOST));
    }

    /**
     * @param array<string, list<string>> $headers response headers, lower-cased keys
     */
    private static function detectBotProtection(int $statusCode, array $headers): ?BotProvider
    {
        if (!in_array($statusCode, self::BOT_PROTECTION_STATUS_CODES, true)) {
            return null;
        }

        foreach (self::BOT_PROTECTION_SIGNATURES as $signature) {
            foreach ($headers[$signature['header']] ?? [] as $value) {
                if ($signature['needle'] === '' || stripos($value, $signature['needle']) !== false) {
                    return $signature['provider'];
                }
            }
        }

        return null;
    }
}
