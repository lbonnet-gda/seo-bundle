<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle;

use Lbonnet\SeoBundle\Http\ThrottledHttpClient;
use Lbonnet\SeoBundle\Model\IssueType;
use Lbonnet\SeoBundle\Model\Severity;
use Lbonnet\SeoBundle\Module\Links\LinksModule;
use Lbonnet\SeoBundle\Module\ModuleInterface;
use Lbonnet\SeoBundle\Module\OnPage\OnPageModule;
use Lbonnet\SeoBundle\Module\Technical\TechnicalModule;
use Lbonnet\SeoBundle\Storage\JsonFileReportStorage;
use Lbonnet\SeoBundle\Storage\ReportStorageInterface;
use Lbonnet\SeoBundle\Url\UrlPattern;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SeoBundle extends AbstractBundle
{
    public const DEFAULT_USER_AGENT = 'Mozilla/5.0 (compatible; SeoBundle/1.0; +https://github.com/lbonnet-gda/seo-bundle)';

    public function configure(DefinitionConfigurator $definition): void
    {
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $definition->rootNode();
        $children = $rootNode->children();

        $children->scalarNode('base_url')
            ->defaultNull()
            ->info('Default base URL to audit when none is passed to the command or the message.')
            ->end();

        $children->enumNode('fail_on')
            ->values([Severity::Error->value, Severity::Warning->value, Severity::Notice->value])
            ->defaultValue(Severity::Error->value)
            ->info(
                'Lowest severity that makes the console command exit with a failure code. Every issue is always reported; this only decides what breaks a build.'
            )
            ->end();

        $children->arrayNode('disabled_checks')
            ->info(
                'Checks to leave out of the report entirely, by issue type (e.g. "image_missing_alt"). A disabled check sends no request.'
            )
            ->scalarPrototype()
            ->validate()
            ->ifTrue(static fn(mixed $value): bool => !is_string($value) || IssueType::tryFrom($value) === null)
            ->thenInvalid('Unknown check %s. Expected one of the "Lbonnet\SeoBundle\Model\IssueType" values.')
            ->end()
            ->end();

        self::configureCrawl($children->arrayNode('crawl')->addDefaultsIfNotSet()->children());
        self::configureLinks($children->arrayNode('links')->canBeDisabled()->children());
        self::configureOnPage($children->arrayNode('on_page')->canBeDisabled()->children());
        self::configureTechnical($children->arrayNode('technical')->canBeDisabled()->children());
        self::configureStorage($children->arrayNode('storage')->addDefaultsIfNotSet()->children());
    }

    /**
     * @param array{
     *     base_url: string|null,
     *     fail_on: string,
     *     disabled_checks: list<string>,
     *     crawl: array{
     *         max_depth: int,
     *         max_pages: int,
     *         timeout: int,
     *         user_agent: string,
     *         exclude_patterns: list<string>,
     *         request_delay_ms: int,
     *         respect_robots_txt: bool,
     *         allow_private_network: bool,
     *     },
     *     links: array{enabled: bool, check_external: bool},
     *     on_page: array{enabled: bool, max_title_length: int, max_description_length: int},
     *     technical: array{
     *         enabled: bool,
     *         max_redirect_hops: int,
     *         resolve_external_targets: bool,
     *         max_external_target_checks: int,
     *         url_variants_sample_size: int,
     *         max_sitemap_files: int,
     *     },
     *     storage: array{dir: string|null, max_reports: int},
     * } $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');
        $builder->registerForAutoconfiguration(ModuleInterface::class)->addTag('seo.module');

        $parameters = $container->parameters()
            ->set('seo.base_url', $config['base_url'])
            ->set('seo.fail_on', $config['fail_on'])
            ->set('seo.disabled_checks', $config['disabled_checks']);

        foreach (['crawl', 'links', 'on_page', 'technical', 'storage'] as $section) {
            foreach ($config[$section] as $key => $value) {
                $parameters->set(sprintf('seo.%s.%s', $section, $key), $value);
            }
        }

        $privateNetworkGuardId = 'seo.http_client.private_network_guard';

        if ($config['crawl']['allow_private_network']) {
            $builder->setAlias($privateNetworkGuardId, HttpClientInterface::class);
        } else {
            $builder->register($privateNetworkGuardId, NoPrivateNetworkHttpClient::class)
                ->setArguments([new Reference(HttpClientInterface::class)]);
        }

        $builder->register('seo.http_client', ThrottledHttpClient::class)
            ->setArguments([new Reference($privateNetworkGuardId), $config['crawl']['request_delay_ms']])
            ->addTag('kernel.reset', ['method' => 'reset']);

        if (!$config['links']['enabled']) {
            $builder->removeDefinition(LinksModule::class);
        }

        if (!$config['on_page']['enabled']) {
            $builder->removeDefinition(OnPageModule::class);
        }

        if (!$config['technical']['enabled']) {
            $builder->removeDefinition(TechnicalModule::class);
        }

        if ($config['storage']['dir'] === null || $config['storage']['dir'] === '') {
            $builder->removeDefinition(JsonFileReportStorage::class);
            $builder->removeAlias(ReportStorageInterface::class);
        }
    }

    private static function configureCrawl(NodeBuilder $crawl): void
    {
        $crawl->integerNode('max_depth')
            ->defaultValue(3)
            ->min(0)
            ->info('Maximum crawl depth from the starting URL (0 = the starting page only).')
            ->end();

        $crawl->integerNode('max_pages')
            ->defaultValue(500)
            ->min(0)
            ->info(
                'Maximum number of pages read per audit; the crawl stops there and the report is marked as truncated. Set to 0 for no limit.'
            )
            ->end();

        $crawl->integerNode('timeout')
            ->defaultValue(10)
            ->min(1)
            ->info('Per-request timeout in seconds.')
            ->end();

        $crawl->scalarNode('user_agent')
            ->defaultValue(self::DEFAULT_USER_AGENT)
            ->info(
                'User-Agent header sent with every request. Identify your crawler honestly; do not spoof a browser UA to bypass bot protection.'
            )
            ->end();

        $crawl->arrayNode('exclude_patterns')
            ->info('Regular expression patterns for URLs to skip, delimiters included (e.g. "#/admin#").')
            ->scalarPrototype()
            ->validate()
            ->ifTrue(static fn(mixed $value): bool => !is_string($value) || !UrlPattern::isValid($value))
            ->thenInvalid('%s is not a valid regular expression. Did you forget its delimiters, as in "#/admin#"?')
            ->end()
            ->end();

        $crawl->integerNode('request_delay_ms')
            ->defaultValue(200)
            ->min(0)
            ->info(
                'Minimum delay, in milliseconds, between consecutive requests to the same host. The audited host is exempt (unless its robots.txt sets a Crawl-delay), so this only slows down requests to other hosts. Set to 0 to disable throttling entirely.'
            )
            ->end();

        $crawl->booleanNode('respect_robots_txt')
            ->defaultTrue()
            ->info(
                'Fetch and honor the audited site\'s robots.txt: matching Disallow rules stop the crawler from following further internal pages under that path, and like Google, a robots.txt answering a server error (5xx, 429 or no response) blocks every page but the starting URL. The technical checks audit robots.txt for Googlebot either way.'
            )
            ->end();

        $crawl->booleanNode('allow_private_network')
            ->defaultFalse()
            ->info(
                'Allow requests to URLs resolving to private/loopback/link-local IP ranges (e.g. 127.0.0.1, 10.0.0.0/8, cloud metadata endpoints). The crawler follows links and redirects found on the pages it visits, so leaving this disabled (default) prevents SSRF if it ever crawls untrusted or third-party content. Enable only to intentionally audit an internal network.'
            )
            ->end();
    }

    private static function configureLinks(NodeBuilder $links): void
    {
        $links->booleanNode('check_external')
            ->defaultTrue()
            ->info('Check the status of links pointing to other sites, once per URL, after the crawl.')
            ->end();
    }

    private static function configureOnPage(NodeBuilder $onPage): void
    {
        $onPage->integerNode('max_title_length')
            ->defaultValue(60)
            ->min(1)
            ->info('Title length, in characters, above which "title_too_long" is reported.')
            ->end();

        $onPage->integerNode('max_description_length')
            ->defaultValue(155)
            ->min(1)
            ->info('Meta description length, in characters, above which "description_too_long" is reported.')
            ->end();
    }

    private static function configureTechnical(NodeBuilder $technical): void
    {
        $technical->integerNode('max_redirect_hops')
            ->defaultValue(1)
            ->min(0)
            ->info(
                'How many redirects a URL may go through before the chain is reported as too long. The default of 1 means "one redirect is fine, a chain is not".'
            )
            ->end();

        $technical->booleanNode('resolve_external_targets')
            ->defaultTrue()
            ->info(
                'Request canonical, hreflang and sitemap targets that the crawl did not already visit, and the robots.txt of their host when it was not crawled. Disable to keep the technical checks strictly within the pages that were crawled.'
            )
            ->end();

        $technical->integerNode('max_external_target_checks')
            ->defaultValue(200)
            ->min(0)
            ->info(
                'Maximum number of such extra requests per audit, robots.txt files included (0 = unlimited). Targets beyond that budget are simply not reported on.'
            )
            ->end();

        $technical->integerNode('url_variants_sample_size')
            ->defaultValue(10)
            ->min(0)
            ->info(
                'How many crawled pages, shallowest first, to request again with their trailing slash toggled and their letter case changed, to catch duplicate URLs. The http://, www/apex and index file versions of the home page are always checked. Set to 0 to skip the per-page checks.'
            )
            ->end();

        $technical->integerNode('max_sitemap_files')
            ->defaultValue(10)
            ->min(0)
            ->info(
                'Maximum number of sitemap files, indexes included, fetched per audit (0 = unlimited). Past it, the remaining sitemaps are not read and pages missing from the sitemaps are not reported.'
            )
            ->end();
    }

    private static function configureStorage(NodeBuilder $storage): void
    {
        $storage->scalarNode('dir')
            ->defaultValue('%kernel.project_dir%/var/seo')
            ->info('Directory where audit reports in JSON are stored. Set to empty or null to disable.')
            ->end();

        $storage->integerNode('max_reports')
            ->defaultValue(30)
            ->min(0)
            ->info(
                'Maximum number of stored reports to keep per audited URL; the oldest are deleted past that. Set to 0 to keep every report forever.'
            )
            ->end();
    }
}
