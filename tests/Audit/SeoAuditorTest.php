<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Audit;

use Lbonnet\SeoBundle\Audit\SeoAuditor;
use Lbonnet\SeoBundle\Crawl\SiteCrawler;
use Lbonnet\SeoBundle\Event\SeoAuditCompletedEvent;
use Lbonnet\SeoBundle\Http\PageFetcher;
use Lbonnet\SeoBundle\Http\RedirectChainResolver;
use Lbonnet\SeoBundle\Model\Issue;
use Lbonnet\SeoBundle\Model\IssueType;
use Lbonnet\SeoBundle\Model\Module;
use Lbonnet\SeoBundle\Model\PageReport;
use Lbonnet\SeoBundle\Model\SeoReport;
use Lbonnet\SeoBundle\Module\ModuleInterface;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class SeoAuditorTest extends TestCase
{
    private const SITE = [
        'https://example.com/' => '<a href="/one">1</a><a href="/admin">Admin</a>',
        'https://example.com/one' => '<a href="/two">2</a>',
        'https://example.com/two' => '',
    ];

    /** @var list<string> */
    private array $requestedUrls = [];

    public function testMergesTheIssuesOfEveryModuleIntoOneReport(): void
    {
        $links = new FakeModule(Module::Links, [
            new PageReport('https://example.com/one', 0, [new Issue(IssueType::BrokenInternalLink, 'Broken.')]),
        ]);
        $technical = new FakeModule(Module::Technical, [
            new PageReport('https://example.com/one', 0, [new Issue(IssueType::MissingHtmlLang, 'No lang.')]),
            new PageReport('https://example.com/sitemap.xml', Response::HTTP_OK, [
                new Issue(IssueType::SitemapUrlNotOk, 'Gone.'),
            ]),
            new PageReport('http://example.com/', Response::HTTP_OK, [
                new Issue(IssueType::HttpNotRedirectedToHttps, 'Plain http.'),
            ]),
        ]);

        $report = $this->auditor([$links, $technical])->audit('https://example.com/');

        $this->assertSame([Module::Links, Module::Technical], $report->modules);
        $this->assertSame(3, $report->pagesRead);
        $this->assertSame(4, $report->urlsChecked);
        $this->assertFalse($report->truncated);
        $this->assertSame(
            [
                'https://example.com/' => [],
                'https://example.com/one' => [IssueType::BrokenInternalLink, IssueType::MissingHtmlLang],
                'https://example.com/two' => [],
                'https://example.com/sitemap.xml' => [IssueType::SitemapUrlNotOk],
                'http://example.com/' => [IssueType::HttpNotRedirectedToHttps],
            ],
            self::typesByUrl($report),
        );
        $this->assertSame(1, $report->pages[1]->depth);
        $this->assertSame(Response::HTTP_OK, $report->pages[1]->statusCode);
    }

    public function testRunsOnlyTheRequestedModules(): void
    {
        $links = new FakeModule(Module::Links);
        $onPage = new FakeModule(Module::OnPage);

        $report = $this->auditor([$links, $onPage])->audit('https://example.com/', modules: [Module::OnPage]);

        $this->assertSame([Module::OnPage], $report->modules);
        $this->assertNull($links->crawl);
        $this->assertNotNull($onPage->crawl);
    }

    public function testChecksLinkTargetsPastTheMaxDepthOnlyForTheLinksModule(): void
    {
        $this->auditor([new FakeModule(Module::OnPage)])->audit('https://example.com/', maxDepth: 1);
        $this->assertNotContains('https://example.com/two', $this->requestedUrls);

        $this->requestedUrls = [];
        $this->auditor([new FakeModule(Module::Links)])->audit('https://example.com/', maxDepth: 1);
        $this->assertContains('https://example.com/two', $this->requestedUrls);
    }

    public function testCombinesTheConfiguredDefaultsWithTheCallArguments(): void
    {
        $module = new FakeModule(Module::Links);
        $auditor = $this->auditor([$module], defaultMaxPages: 2, defaultExcludePatterns: ['#/admin#']);

        $report = $auditor->audit('https://example.com/', excludePatterns: ['#/two#'], checkExternal: false);

        $this->assertNotContains('https://example.com/admin', $this->requestedUrls);
        $this->assertNotContains('https://example.com/two', $this->requestedUrls);
        $this->assertSame(2, $report->pagesRead);
        $this->assertFalse($module->options?->checkExternal);

        $auditor->audit('https://example.com/');
        $this->assertTrue($module->options?->checkExternal);
    }

    public function testDropsDisabledChecksAndTheEntriesTheyLeaveEmpty(): void
    {
        $module = new FakeModule(Module::Technical, [
            new PageReport('https://example.com/', 0, [
                new Issue(IssueType::MissingHtmlLang, 'No lang.'),
                new Issue(IssueType::CanonicalRelative, 'Relative.'),
            ]),
            new PageReport('https://example.com/index.php', Response::HTTP_OK, [
                new Issue(IssueType::IndexFileDuplicate, 'Duplicate.'),
            ]),
        ]);

        $report = $this->auditor(
            [$module],
            disabledChecks: [IssueType::MissingHtmlLang->value, IssueType::IndexFileDuplicate->value],
        )->audit('https://example.com/');

        $this->assertSame(
            [
                'https://example.com/' => [IssueType::CanonicalRelative],
                'https://example.com/one' => [],
                'https://example.com/two' => [],
            ],
            self::typesByUrl($report),
        );
    }

    public function testDispatchesTheCompletedEventAndSurvivesAFailingListener(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(SeoAuditCompletedEvent::class))
            ->willThrowException(new RuntimeException('Slack is down'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('Slack is down'));

        $report = $this->auditor([], dispatcher: $dispatcher, logger: $logger)->audit('https://example.com/');

        $this->assertSame('https://example.com/', $report->startUrl);
    }

    /**
     * @param list<ModuleInterface> $modules
     * @param list<string> $defaultExcludePatterns
     * @param list<string> $disabledChecks
     */
    private function auditor(
        array $modules,
        int $defaultMaxPages = 500,
        array $defaultExcludePatterns = [],
        array $disabledChecks = [],
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ): SeoAuditor {
        $httpClient = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->requestedUrls[] = $url;
            $body = self::SITE[$url] ?? null;

            if ($body === null) {
                return new MockResponse('', ['http_code' => Response::HTTP_NOT_FOUND]);
            }

            return new MockResponse(
                '<!DOCTYPE html><html><head><title>T</title></head><body>'.$body.'</body></html>',
                ['response_headers' => ['content-type' => 'text/html']],
            );
        });
        $pageFetcher = new PageFetcher($httpClient);

        return new SeoAuditor(
            crawler: new SiteCrawler($pageFetcher, new RedirectChainResolver($pageFetcher)),
            httpClient: $httpClient,
            modules: $modules,
            eventDispatcher: $dispatcher,
            defaultMaxPages: $defaultMaxPages,
            defaultExcludePatterns: $defaultExcludePatterns,
            disabledChecks: $disabledChecks,
            logger: $logger ?? $this->createMock(LoggerInterface::class),
        );
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
