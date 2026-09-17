<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\MessageHandler;

use Lbonnet\SeoBundle\Audit\SeoAuditorInterface;
use Lbonnet\SeoBundle\Message\CheckSeoMessage;
use Lbonnet\SeoBundle\Model\Module;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CheckSeoMessageHandler
{
    public function __construct(
        private readonly SeoAuditorInterface $auditor,
        private readonly ?string $defaultBaseUrl = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(CheckSeoMessage $message): void
    {
        $startUrl = $message->startUrl ?? $this->defaultBaseUrl;

        if ($startUrl === null || trim($startUrl) === '') {
            $this->logger->error('[Seo] No base URL configured or provided in CheckSeoMessage.');

            return;
        }

        $modules = null;

        if ($message->modules !== null) {
            $modules = [];

            foreach ($message->modules as $name) {
                $module = Module::tryFrom($name);

                if ($module === null) {
                    $this->logger->error(sprintf('[Seo] Unknown module "%s" in CheckSeoMessage.', $name));

                    return;
                }

                $modules[] = $module;
            }
        }

        $this->logger->info(sprintf('[Seo] Async audit starting on: %s', $startUrl));

        $this->auditor->audit(
            startUrl: $startUrl,
            maxDepth: $message->maxDepth,
            maxPages: $message->maxPages,
            excludePatterns: $message->excludePatterns,
            modules: $modules,
            checkExternal: $message->checkExternal,
        );
    }
}
