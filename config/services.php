<?php

declare(strict_types=1);

use Lbonnet\SeoBundle\Audit\SeoAuditor;
use Lbonnet\SeoBundle\Command\CheckSeoCommand;
use Lbonnet\SeoBundle\Http\PageFetcher;
use Lbonnet\SeoBundle\Http\RedirectChainResolver;
use Lbonnet\SeoBundle\Http\RedirectChainResolverInterface;
use Lbonnet\SeoBundle\MessageHandler\CheckSeoMessageHandler;
use Lbonnet\SeoBundle\Module\Links\UrlChecker;
use Lbonnet\SeoBundle\Module\OnPage\PageAuditor as OnPagePageAuditor;
use Lbonnet\SeoBundle\Module\Technical\Http\HttpTargetProbe;
use Lbonnet\SeoBundle\Module\Technical\Http\SitemapFetcher;
use Lbonnet\SeoBundle\Module\Technical\SiteAuditor;
use Lbonnet\SeoBundle\Module\Technical\SitemapAuditor;
use Lbonnet\SeoBundle\Module\Technical\UrlVariantAuditor;
use Lbonnet\SeoBundle\Robots\RobotsTxtChecker;
use Lbonnet\SeoBundle\Robots\RobotsTxtCheckerInterface;
use Lbonnet\SeoBundle\Robots\RobotsTxtProviderInterface;
use Lbonnet\SeoBundle\Storage\JsonFileReportStorage;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('Lbonnet\\SeoBundle\\', '../src/')
        ->exclude([
            '../src/SeoBundle.php',
            '../src/Model/',
            '../src/Event/',
            '../src/Message/',
            '../src/Http/',
            '../src/Robots/',
            '../src/Url/',
            '../src/Crawl/CrawlOptions.php',
            '../src/Crawl/CrawledPage.php',
            '../src/Crawl/CrawlResult.php',
            '../src/Module/ModuleOptions.php',
            '../src/Module/Links/Model/',
            '../src/Module/Technical/Model/',
            '../src/Module/Technical/Sitemap/',
            '../src/Module/Technical/Hreflang/',
        ]);

    $services->set(PageFetcher::class)
        ->arg('$httpClient', service('seo.http_client'))
        ->arg('$timeout', param('seo.crawl.timeout'))
        ->arg('$userAgent', param('seo.crawl.user_agent'));

    $services->set(RedirectChainResolver::class);
    $services->alias(RedirectChainResolverInterface::class, RedirectChainResolver::class);

    $services->set(SeoAuditor::class)
        ->arg('$httpClient', service('seo.http_client'))
        ->arg('$modules', tagged_iterator('seo.module'))
        ->arg('$defaultMaxDepth', param('seo.crawl.max_depth'))
        ->arg('$defaultMaxPages', param('seo.crawl.max_pages'))
        ->arg('$defaultExcludePatterns', param('seo.crawl.exclude_patterns'))
        ->arg('$defaultCheckExternal', param('seo.links.check_external'))
        ->arg('$disabledChecks', param('seo.disabled_checks'));

    $services->set(UrlChecker::class)
        ->arg('$httpClient', service('seo.http_client'))
        ->arg('$defaultTimeout', param('seo.crawl.timeout'))
        ->arg('$userAgent', param('seo.crawl.user_agent'));

    $services->set(OnPagePageAuditor::class)
        ->arg('$maxTitleLength', param('seo.on_page.max_title_length'))
        ->arg('$maxDescriptionLength', param('seo.on_page.max_description_length'));

    $services->set(HttpTargetProbe::class)
        ->arg('$enabled', param('seo.technical.resolve_external_targets'))
        ->arg('$maxProbes', param('seo.technical.max_external_target_checks'));

    $services->set(SitemapFetcher::class)
        ->arg('$httpClient', service('seo.http_client'))
        ->arg('$timeout', param('seo.crawl.timeout'))
        ->arg('$userAgent', param('seo.crawl.user_agent'));

    $services->set(SiteAuditor::class)
        ->arg('$maxRedirectHops', param('seo.technical.max_redirect_hops'))
        ->arg('$disabledChecks', param('seo.disabled_checks'));

    $services->set(UrlVariantAuditor::class)
        ->arg('$sampleSize', param('seo.technical.url_variants_sample_size'))
        ->arg('$disabledChecks', param('seo.disabled_checks'));

    $services->set(SitemapAuditor::class)
        ->arg('$maxFiles', param('seo.technical.max_sitemap_files'))
        ->arg('$disabledChecks', param('seo.disabled_checks'));

    $services->set(RobotsTxtChecker::class)
        ->arg('$httpClient', service('seo.http_client'))
        ->arg('$userAgent', param('seo.crawl.user_agent'))
        ->arg('$enabled', param('seo.crawl.respect_robots_txt'))
        ->tag('kernel.reset', ['method' => 'reset']);

    $services->alias(RobotsTxtCheckerInterface::class, RobotsTxtChecker::class);
    $services->alias(RobotsTxtProviderInterface::class, RobotsTxtChecker::class);

    $services->set(CheckSeoCommand::class)
        ->arg('$defaultBaseUrl', param('seo.base_url'))
        ->arg('$defaultFailOn', param('seo.fail_on'));

    $services->set(CheckSeoMessageHandler::class)
        ->arg('$defaultBaseUrl', param('seo.base_url'));

    $services->set(JsonFileReportStorage::class)
        ->arg('$storageDirectory', param('seo.storage.dir'))
        ->arg('$maxReports', param('seo.storage.max_reports'));
};
