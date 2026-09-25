<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Module\Links;

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

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly int $defaultTimeout = 10,
        private readonly string $userAgent = SeoBundle::DEFAULT_USER_AGENT,
    ) {
    }

    public function check(string $url, ?int $timeout = null): CheckResult
    {
        $requestTimeout = $timeout ?? $this->defaultTimeout;
        $startTime = microtime(true);

        try {
            $response = $this->httpClient->request(Request::METHOD_HEAD, $url, [
                'timeout' => $requestTimeout,
                'max_redirects' => 5,
                'headers' => [
                    'User-Agent' => $this->userAgent,
                ],
            ]);

            $statusCode = $response->getStatusCode();

            if (in_array($statusCode, self::FALLBACK_STATUS_CODES, true)) {
                $response = $this->httpClient->request(Request::METHOD_GET, $url, [
                    'timeout' => $requestTimeout,
                    'max_redirects' => 5,
                    'headers' => [
                        'User-Agent' => $this->userAgent,
                        'Range' => 'bytes=0-1024',
                    ],
                ]);
                $statusCode = $response->getStatusCode();
            }

            $duration = microtime(true) - $startTime;
            /** @var array<string, list<string>> $headers */
            $headers = $response->getHeaders(false);
            $contentType = $headers['content-type'][0] ?? null;
            $redirectUrl = $response->getInfo('redirect_url');
            $blockedBy = $this->detectBotProtection($statusCode, $headers);

            return new CheckResult(
                url: $url,
                statusCode: $statusCode,
                duration: round($duration, 3),
                redirectUrl: $redirectUrl ?: null,
                contentType: $contentType,
                likelyBlocked: $blockedBy !== null,
                blockedBy: $blockedBy,
            );
        } catch (TransportExceptionInterface $e) {
            $duration = microtime(true) - $startTime;

            return new CheckResult(
                url: $url,
                duration: round($duration, 3),
                errorMessage: $e->getMessage()
            );
        } catch (Throwable $e) {
            $duration = microtime(true) - $startTime;

            return new CheckResult(
                url: $url,
                duration: round($duration, 3),
                errorMessage: 'Unexpected error: '.$e->getMessage()
            );
        }
    }

    /**
     * @param array<string, list<string>> $headers response headers, lower-cased keys
     */
    private function detectBotProtection(int $statusCode, array $headers): ?BotProvider
    {
        if (!in_array($statusCode, self::BOT_PROTECTION_STATUS_CODES, true)) {
            return null;
        }

        foreach (self::BOT_PROTECTION_SIGNATURES as $signature) {
            $values = $headers[$signature['header']] ?? [];

            foreach ($values as $value) {
                if ($signature['needle'] === '' || stripos($value, $signature['needle']) !== false) {
                    return $signature['provider'];
                }
            }
        }

        return null;
    }
}
