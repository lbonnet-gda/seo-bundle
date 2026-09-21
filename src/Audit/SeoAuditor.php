<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Audit;

use Lbonnet\SeoBundle\Crawl\CrawlOptions;
use Lbonnet\SeoBundle\Crawl\CrawlResult;
use Lbonnet\SeoBundle\Crawl\SiteCrawler;
use Lbonnet\SeoBundle\Event\SeoAuditCompletedEvent;
use Lbonnet\SeoBundle\Http\SiteThrottleExemption;
use Lbonnet\SeoBundle\Model\Issue;
use Lbonnet\SeoBundle\Model\Module;
use Lbonnet\SeoBundle\Model\PageReport;
use Lbonnet\SeoBundle\Model\SeoReport;
use Lbonnet\SeoBundle\Module\ModuleInterface;
use Lbonnet\SeoBundle\Module\ModuleOptions;
use Lbonnet\SeoBundle\Robots\RobotsTxtCheckerInterface;
use Lbonnet\SeoBundle\Url\UrlResolver;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class SeoAuditor implements SeoAuditorInterface
{
    /** @var list<ModuleInterface> */
    private readonly array $modules;

    /** @var array<string, true> */
    private readonly array $disabledChecks;

    /**
     * @param iterable<ModuleInterface> $modules the enabled modules
     * @param list<string> $defaultExcludePatterns
     * @param list<string> $disabledChecks IssueType values to drop from the report
     */
    public function __construct(
        private readonly SiteCrawler $crawler,
        private readonly HttpClientInterface $httpClient,
        iterable $modules = [],
        private readonly ?RobotsTxtCheckerInterface $robotsTxtChecker = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly int $defaultMaxDepth = 3,
        private readonly int $defaultMaxPages = 500,
        private readonly array $defaultExcludePatterns = [],
        private readonly bool $defaultCheckExternal = true,
        array $disabledChecks = [],
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $moduleList = [];

        foreach ($modules as $module) {
            $moduleList[] = $module;
        }

        $this->modules = $moduleList;

        $disabled = [];

        foreach ($disabledChecks as $check) {
            $disabled[$check] = true;
        }

        $this->disabledChecks = $disabled;
    }

    public function audit(
        string $startUrl,
        ?int $maxDepth = null,
        ?int $maxPages = null,
        array $excludePatterns = [],
        ?array $modules = null,
        ?bool $checkExternal = null,
        ?callable $progressCallback = null,
    ): SeoReport {
        $startTime = microtime(true);
        $selectedModules = $this->selectModules($modules);

        $options = new CrawlOptions(
            maxDepth: $maxDepth ?? $this->defaultMaxDepth,
            maxPages: $maxPages ?? $this->defaultMaxPages,
            excludePatterns: [...$this->defaultExcludePatterns, ...$excludePatterns],
            checkLinkTargets: self::includes($selectedModules, Module::Links),
            progressCallback: $progressCallback !== null ? $progressCallback(...) : null,
        );
        $moduleOptions = new ModuleOptions(checkExternal: $checkExternal ?? $this->defaultCheckExternal);

        // Modules request the audited site too (probes, URL variants, sitemaps): the exemption covers them.
        $throttleExemption = SiteThrottleExemption::begin($this->httpClient, $startUrl, $this->robotsTxtChecker);

        try {
            $crawl = $this->crawler->crawl($startUrl, $options, $throttleExemption);
            $entries = [];

            foreach ($selectedModules as $module) {
                $entries = [...$entries, ...$module->audit($crawl, $moduleOptions)];
            }
        } finally {
            $throttleExemption->end();
        }

        $report = new SeoReport(
            startUrl: $startUrl,
            pages: $this->merge($crawl, $entries),
            modules: array_map(static fn(ModuleInterface $module): Module => $module->module(), $selectedModules),
            pagesRead: count($crawl->pages),
            urlsChecked: $crawl->urlsChecked,
            totalDuration: round(microtime(true) - $startTime, 3),
            truncated: $crawl->truncated,
            blockedByRobotsTxt: $crawl->blockedByRobotsTxt,
        );

        try {
            $this->eventDispatcher?->dispatch(new SeoAuditCompletedEvent($report));
        } catch (Throwable $e) {
            $this->logger->error(sprintf('[Seo] A "SeoAuditCompletedEvent" listener failed: %s', $e->getMessage()));
        }

        return $report;
    }

    /**
     * @param list<Module>|null $requested
     *
     * @return list<ModuleInterface>
     */
    private function selectModules(?array $requested): array
    {
        if ($requested === null) {
            return $this->modules;
        }

        return array_values(
            array_filter(
                $this->modules,
                static fn(ModuleInterface $module): bool => in_array($module->module(), $requested, true),
            )
        );
    }

    /**
     * @param list<PageReport> $entries
     *
     * @return list<PageReport>
     */
    private function merge(CrawlResult $crawl, array $entries): array
    {
        /** @var array<string, PageReport> $pages */
        $pages = [];

        foreach ($crawl->pages as $page) {
            $pages[UrlResolver::dedupKey($page->url)] = new PageReport(
                url: $page->url,
                statusCode: $page->response->statusCode,
                depth: $page->depth,
                canonical: $page->signals->effectiveCanonicalHref(),
            );
        }

        $others = [];

        foreach ($entries as $entry) {
            $key = UrlResolver::dedupKey($entry->url);
            $page = $pages[$key] ?? null;

            if ($page !== null && UrlResolver::isSameUrl($page->url, $entry->url)) {
                $pages[$key] = $page->withAddedIssues($this->withoutDisabledChecks($entry->issues));

                continue;
            }

            $issues = $this->withoutDisabledChecks($entry->issues);

            if ($issues !== []) {
                $others[] = $entry->withIssues($issues);
            }
        }

        return [...array_values($pages), ...$others];
    }

    /**
     * @param list<Issue> $issues
     *
     * @return list<Issue>
     */
    private function withoutDisabledChecks(array $issues): array
    {
        if ($this->disabledChecks === []) {
            return $issues;
        }

        return array_values(
            array_filter($issues, fn(Issue $issue): bool => !isset($this->disabledChecks[$issue->type->value]))
        );
    }

    /**
     * @param list<ModuleInterface> $modules
     */
    private static function includes(array $modules, Module $wanted): bool
    {
        foreach ($modules as $module) {
            if ($module->module() === $wanted) {
                return true;
            }
        }

        return false;
    }
}
