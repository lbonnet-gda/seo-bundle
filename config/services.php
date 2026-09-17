<?php

declare(strict_types=1);

use Lbonnet\SeoBundle\Command\CheckSeoCommand;
use Lbonnet\SeoBundle\MessageHandler\CheckSeoMessageHandler;
use Lbonnet\SeoBundle\Robots\RobotsTxtChecker;
use Lbonnet\SeoBundle\Robots\RobotsTxtCheckerInterface;
use Lbonnet\SeoBundle\Robots\RobotsTxtProviderInterface;
use Lbonnet\SeoBundle\Storage\JsonFileReportStorage;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

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
        ]);

    $services->set(RobotsTxtChecker::class)
        ->arg('$httpClient', service('seo.http_client'))
        ->arg('$userAgent', param('seo.crawl.user_agent'))
        ->arg('$enabled', param('seo.crawl.respect_robots_txt'));

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
