<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\MessageHandler;

use Lbonnet\SeoBundle\Audit\SeoAuditorInterface;
use Lbonnet\SeoBundle\Message\CheckSeoMessage;
use Lbonnet\SeoBundle\MessageHandler\CheckSeoMessageHandler;
use Lbonnet\SeoBundle\Model\Module;
use Lbonnet\SeoBundle\Model\SeoReport;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class CheckSeoMessageHandlerTest extends TestCase
{
    public function testItAuditsTheUrlFromTheMessage(): void
    {
        $auditor = $this->createMock(SeoAuditorInterface::class);
        $auditor->expects($this->once())
            ->method('audit')
            ->with('https://example.com/blog', 2, 50, ['#/preview#'], [Module::Links, Module::Technical], false)
            ->willReturn(new SeoReport('https://example.com/blog'));

        $handler = new CheckSeoMessageHandler($auditor, defaultBaseUrl: 'https://example.com');

        $handler(
            new CheckSeoMessage(
                startUrl: 'https://example.com/blog',
                maxDepth: 2,
                excludePatterns: ['#/preview#'],
                maxPages: 50,
                modules: ['links', 'technical'],
                checkExternal: false,
            )
        );
    }

    public function testItFallsBackToTheConfiguredBaseUrl(): void
    {
        $auditor = $this->createMock(SeoAuditorInterface::class);
        $auditor->expects($this->once())
            ->method('audit')
            ->with('https://example.com', null, null, [], null, null)
            ->willReturn(new SeoReport('https://example.com'));

        (new CheckSeoMessageHandler($auditor, defaultBaseUrl: 'https://example.com'))(new CheckSeoMessage());
    }

    public function testItDoesNothingWithoutAnyUrl(): void
    {
        $auditor = $this->createMock(SeoAuditorInterface::class);
        $auditor->expects($this->never())->method('audit');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        (new CheckSeoMessageHandler($auditor, logger: $logger))(new CheckSeoMessage());
    }

    public function testItRejectsAnUnknownModule(): void
    {
        $auditor = $this->createMock(SeoAuditorInterface::class);
        $auditor->expects($this->never())->method('audit');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('"links_checker"'));

        (new CheckSeoMessageHandler($auditor, 'https://example.com', $logger))(
            new CheckSeoMessage(modules: ['links_checker'])
        );
    }
}
