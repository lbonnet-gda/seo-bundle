<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests;

use Lbonnet\SeoBundle\Audit\SeoAuditor;
use Lbonnet\SeoBundle\Audit\SeoAuditorInterface;
use Lbonnet\SeoBundle\Command\CheckSeoCommand;
use Lbonnet\SeoBundle\Crawl\SiteCrawler;
use Lbonnet\SeoBundle\EventListener\StoreReportListener;
use Lbonnet\SeoBundle\Http\ThrottledHttpClient;
use Lbonnet\SeoBundle\MessageHandler\CheckSeoMessageHandler;
use Lbonnet\SeoBundle\Robots\RobotsTxtChecker;
use Lbonnet\SeoBundle\Robots\RobotsTxtCheckerInterface;
use Lbonnet\SeoBundle\Robots\RobotsTxtProviderInterface;
use Lbonnet\SeoBundle\SeoBundle;
use Lbonnet\SeoBundle\Storage\JsonFileReportStorage;
use Lbonnet\SeoBundle\Storage\ReportStorageInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SeoBundleTest extends TestCase
{
    public function testDefaultConfigurationAndParameters(): void
    {
        $container = $this->load([]);

        $expected = [
            'seo.base_url' => null,
            'seo.fail_on' => 'error',
            'seo.disabled_checks' => [],
            'seo.crawl.max_depth' => 3,
            'seo.crawl.max_pages' => 500,
            'seo.crawl.timeout' => 10,
            'seo.crawl.user_agent' => SeoBundle::DEFAULT_USER_AGENT,
            'seo.crawl.exclude_patterns' => [],
            'seo.crawl.request_delay_ms' => 200,
            'seo.crawl.respect_robots_txt' => true,
            'seo.crawl.allow_private_network' => false,
            'seo.links.enabled' => true,
            'seo.links.check_external' => true,
            'seo.on_page.enabled' => true,
            'seo.on_page.max_title_length' => 60,
            'seo.on_page.max_description_length' => 155,
            'seo.technical.enabled' => true,
            'seo.technical.max_redirect_hops' => 1,
            'seo.technical.resolve_external_targets' => true,
            'seo.technical.max_external_target_checks' => 200,
            'seo.technical.url_variants_sample_size' => 10,
            'seo.technical.max_sitemap_files' => 10,
            'seo.storage.dir' => '%kernel.project_dir%/var/seo',
            'seo.storage.max_reports' => 30,
        ];

        foreach ($expected as $name => $value) {
            $this->assertSame($value, $container->getParameter($name), $name);
        }

        $this->assertSame(
            NoPrivateNetworkHttpClient::class,
            $container->getDefinition('seo.http_client.private_network_guard')->getClass()
        );

        $httpClient = $container->getDefinition('seo.http_client');
        $this->assertSame(ThrottledHttpClient::class, $httpClient->getClass());
        $this->assertSame('seo.http_client.private_network_guard', (string)$httpClient->getArgument(0));
        $this->assertSame(200, $httpClient->getArgument(1));
    }

    public function testCustomConfiguration(): void
    {
        $container = $this->load([
            'seo' => [
                'base_url' => 'https://example.com',
                'fail_on' => 'warning',
                'disabled_checks' => ['image_missing_alt'],
                'crawl' => [
                    'max_depth' => 5,
                    'max_pages' => 0,
                    'exclude_patterns' => ['#/admin#'],
                    'request_delay_ms' => 250,
                    'allow_private_network' => true,
                ],
                'links' => false,
                'on_page' => ['max_title_length' => 70],
                'technical' => ['enabled' => false, 'max_sitemap_files' => 3],
            ],
        ]);

        $this->assertSame('https://example.com', $container->getParameter('seo.base_url'));
        $this->assertSame('warning', $container->getParameter('seo.fail_on'));
        $this->assertSame(['image_missing_alt'], $container->getParameter('seo.disabled_checks'));
        $this->assertSame(5, $container->getParameter('seo.crawl.max_depth'));
        $this->assertSame(0, $container->getParameter('seo.crawl.max_pages'));
        $this->assertSame(['#/admin#'], $container->getParameter('seo.crawl.exclude_patterns'));
        $this->assertFalse($container->getParameter('seo.links.enabled'));
        $this->assertTrue($container->getParameter('seo.on_page.enabled'));
        $this->assertSame(70, $container->getParameter('seo.on_page.max_title_length'));
        $this->assertFalse($container->getParameter('seo.technical.enabled'));
        $this->assertSame(3, $container->getParameter('seo.technical.max_sitemap_files'));

        $this->assertSame(
            HttpClientInterface::class,
            (string)$container->getAlias('seo.http_client.private_network_guard')
        );
        $this->assertSame(250, $container->getDefinition('seo.http_client')->getArgument(1));
    }

    public function testRegistersTheServices(): void
    {
        $container = $this->load([]);

        foreach (
            [
                SeoAuditor::class,
                SiteCrawler::class,
                CheckSeoCommand::class,
                CheckSeoMessageHandler::class,
                StoreReportListener::class,
                JsonFileReportStorage::class,
                RobotsTxtChecker::class,
            ] as $serviceId
        ) {
            $this->assertTrue($container->hasDefinition($serviceId), $serviceId.' should be registered');
        }

        foreach (
            [
                ReportStorageInterface::class,
                RobotsTxtCheckerInterface::class,
                RobotsTxtProviderInterface::class,
            ] as $alias
        ) {
            $this->assertTrue($container->hasAlias($alias), $alias.' should be resolvable');
        }

        $this->assertFalse(
            $container->hasAlias(HttpClientInterface::class),
            'The bundle must not replace the application\'s own HTTP client'
        );
    }

    public function testTheContainerCompilesWithTheAuditorWiredToTheThrottledClient(): void
    {
        $container = $this->load([]);
        $container->register(HttpClientInterface::class)->setSynthetic(true)->setPublic(true);
        $container->getAlias(SeoAuditorInterface::class)->setPublic(true);
        $container->getDefinition(CheckSeoCommand::class)->setPublic(true);

        $container->compile();

        $auditor = $container->findDefinition(SeoAuditorInterface::class);
        $this->assertSame(SeoAuditor::class, $auditor->getClass());

        $httpClient = $auditor->getArgument(1);
        $httpClient = $httpClient instanceof Definition ? $httpClient : $container->findDefinition((string)$httpClient);
        $this->assertSame(ThrottledHttpClient::class, $httpClient->getClass());
    }

    public function testAnUnknownDisabledCheckIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->load(['seo' => ['disabled_checks' => ['not_a_real_check']]]);
    }

    public function testAnUnknownFailOnValueIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->load(['seo' => ['fail_on' => 'critical']]);
    }

    public function testEmptyStorageDirDisablesReportStorage(): void
    {
        $container = $this->load(['seo' => ['storage' => ['dir' => '']]]);

        $this->assertFalse($container->hasDefinition(JsonFileReportStorage::class));
        $this->assertFalse($container->hasAlias(ReportStorageInterface::class));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $tempDir = sys_get_temp_dir();

        $container = new ContainerBuilder(new ParameterBag([
            'kernel.debug' => false,
            'kernel.project_dir' => $tempDir,
            'kernel.build_dir' => $tempDir,
            'kernel.cache_dir' => $tempDir,
            'kernel.charset' => 'UTF-8',
            'kernel.environment' => 'test',
        ]));

        $extension = (new SeoBundle())->getContainerExtension();

        $this->assertNotNull($extension);

        $extension->load($config, $container);

        return $container;
    }
}
