<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Module\Links;

use Lbonnet\SeoBundle\Audit\SeoAuditor;
use Lbonnet\SeoBundle\Crawl\SiteCrawler;
use Lbonnet\SeoBundle\Http\PageFetcher;
use Lbonnet\SeoBundle\Http\RedirectChainResolver;
use Lbonnet\SeoBundle\Model\Issue;
use Lbonnet\SeoBundle\Model\IssueType;
use Lbonnet\SeoBundle\Model\SeoReport;
use Lbonnet\SeoBundle\Module\Links\LinksModule;
use Lbonnet\SeoBundle\Module\Links\UrlChecker;
use Lbonnet\SeoBundle\Robots\RobotsTxtCheckerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class LinksModuleTest extends TestCase
{
    /** @var list<string> */
    private array $requestedUrls = [];

    public function testReportsABrokenInternalLinkOnEveryPageCarryingIt(): void
    {
        $report = $this->audit([
            'https://example.com/' => self::page('<a href="/gone">Gone</a><a href="/a">A</a>'),
            'https://example.com/a' => self::page('<a href="/gone">Gone too</a>'),
        ]);

        $this->assertSame(
            [
                'https://example.com/' => [IssueType::BrokenInternalLink],
                'https://example.com/a' => [IssueType::BrokenInternalLink],
            ],
            self::typesByUrl($report),
        );

        $issue = $report->pages[0]->issues[0];
        $this->assertSame('This page links to "https://example.com/gone", which answers 404.', $issue->message);
        $this->assertSame(
            ['target' => 'https://example.com/gone', 'statusCode' => 404, 'anchorText' => 'Gone'],
            $issue->context,
        );
    }

    public function testAnInternalLinkIsBrokenWhenItsRedirectEndsBadly(): void
    {
        $report = $this->audit([
            'https://example.com/' => self::page(
                '<a href="/moved">Moved</a><a href="/dead-end">Dead end</a><a href="/loop">Loop</a>'
            ),
            'https://example.com/moved' => self::redirect('https://example.com/new'),
            'https://example.com/new' => self::page(),
            'https://example.com/dead-end' => self::redirect('https://example.com/gone'),
            'https://example.com/loop' => self::redirect('https://example.com/loop'),
        ]);

        $messages = array_map(
            static fn(Issue $issue): string => $issue->message,
            $report->pages[0]->issues,
        );

        $this->assertSame(
            [
                'This page links to "https://example.com/dead-end", which redirects to '
                .'"https://example.com/gone", which answers 404.',
                'This page links to "https://example.com/loop", which redirects in a loop.',
            ],
            $messages,
        );
    }

    public function testAnInternalLinkThatCannotBeReachedIsBroken(): void
    {
        $report = $this->audit([
            'https://example.com/' => self::page('<a href="/timeout">Slow</a>'),
            'https://example.com/timeout' => ['', ['error' => 'Operation timed out']],
        ]);

        $this->assertSame(
            'This page links to "https://example.com/timeout", which could not be reached at all.',
            $report->pages[0]->issues[0]->message,
        );
    }

    public function testALinkDisallowedByRobotsTxtIsNotReported(): void
    {
        $robotsTxtChecker = $this->createMock(RobotsTxtCheckerInterface::class);
        $robotsTxtChecker->method('isAllowed')->willReturnCallback(
            static fn(string $url): bool => !str_contains($url, '/private'),
        );

        $report = $this->audit(
            ['https://example.com/' => self::page('<a href="/private/page">Private</a>')],
            robotsTxtChecker: $robotsTxtChecker,
        );

        $this->assertFalse($report->hasIssues());
        $this->assertNotContains('https://example.com/private/page', $this->requestedUrls);
    }

    public function testChecksEachExternalLinkOnceAndReportsItEverywhere(): void
    {
        $report = $this->audit([
            'https://example.com/' => self::page(
                '<a href="https://other.com/gone">Gone</a>'
                .'<a href="https://other.com/fine">Fine</a>'
                .'<a href="/a">A</a>'
            ),
            'https://example.com/a' => self::page('<a href="https://other.com/gone">Gone too</a>'),
            'https://other.com/fine' => self::page(),
        ]);

        $this->assertSame(
            [
                'https://example.com/' => [IssueType::BrokenExternalLink],
                'https://example.com/a' => [IssueType::BrokenExternalLink],
            ],
            self::typesByUrl($report),
        );
        $this->assertCount(2, array_keys($this->requestedUrls, 'https://other.com/gone', true));
    }

    public function testAnExternalLinkBehindBotProtectionIsOnlyANotice(): void
    {
        $report = $this->audit([
            'https://example.com/' => self::page('<a href="https://other.com/shielded">Shielded</a>'),
            'https://other.com/shielded' => [
                '',
                ['http_code' => Response::HTTP_FORBIDDEN, 'response_headers' => ['cf-ray' => '7d9f']],
            ],
        ]);

        $this->assertSame(
            ['https://example.com/' => [IssueType::ExternalLinkLikelyBlocked]],
            self::typesByUrl($report),
        );
        $this->assertStringContainsString(
            'looks like Cloudflare bot protection',
            $report->pages[0]->issues[0]->message,
        );
    }

    public function testExternalLinksAreLeftAloneWhenCheckingThemIsOff(): void
    {
        $report = $this->audit(
            ['https://example.com/' => self::page('<a href="https://other.com/gone">Gone</a>')],
            checkExternal: false,
        );

        $this->assertFalse($report->hasIssues());
        $this->assertNotContains('https://other.com/gone', $this->requestedUrls);
    }

    /**
     * @param array<string, array{string, array<string, mixed>}> $site URL => [body, info]
     */
    private function audit(
        array $site,
        bool $checkExternal = true,
        ?RobotsTxtCheckerInterface $robotsTxtChecker = null,
    ): SeoReport {
        $httpClient = new MockHttpClient(function (string $method, string $url) use ($site): MockResponse {
            $this->requestedUrls[] = $url;
            [$body, $info] = $site[$url] ?? ['', ['http_code' => Response::HTTP_NOT_FOUND]];

            return new MockResponse($body, $info);
        });
        $pageFetcher = new PageFetcher($httpClient);

        return (new SeoAuditor(
            crawler: new SiteCrawler(
                $pageFetcher,
                new RedirectChainResolver($pageFetcher),
                robotsTxtChecker: $robotsTxtChecker,
            ),
            httpClient: $httpClient,
            modules: [new LinksModule(new UrlChecker($httpClient))],
            robotsTxtChecker: $robotsTxtChecker,
        ))->audit('https://example.com/', checkExternal: $checkExternal);
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function page(string $body = ''): array
    {
        return [
            '<!DOCTYPE html><html><head><title>T</title></head><body>'.$body.'</body></html>',
            ['response_headers' => ['content-type' => 'text/html; charset=UTF-8']],
        ];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function redirect(string $location): array
    {
        return ['', ['http_code' => Response::HTTP_MOVED_PERMANENTLY, 'response_headers' => ['location' => $location]]];
    }

    /**
     * @return array<string, list<IssueType>>
     */
    private static function typesByUrl(SeoReport $report): array
    {
        $types = [];

        foreach ($report->pages as $page) {
            $types[$page->url] = array_map(static fn(Issue $issue): IssueType => $issue->type, $page->issues);
        }

        return $types;
    }
}
