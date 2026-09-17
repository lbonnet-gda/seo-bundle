<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Command;

use Lbonnet\SeoBundle\Audit\SeoAuditorInterface;
use Lbonnet\SeoBundle\Model\Module;
use Lbonnet\SeoBundle\Model\SeoReport;
use Lbonnet\SeoBundle\Model\Severity;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Terminal;

#[AsCommand(
    name: 'seo:check',
    description: 'Crawls a website once and audits its links, on-page content and technical SEO signals.',
)]
final class CheckSeoCommand extends Command
{
    use LockableTrait;

    public function __construct(
        private readonly SeoAuditorInterface $auditor,
        private readonly ?string $defaultBaseUrl = null,
        private readonly string $defaultFailOn = 'error',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('url', InputArgument::OPTIONAL, 'The starting URL to crawl (defaults to seo.base_url)')
            ->addOption('max-depth', 'd', InputOption::VALUE_REQUIRED, 'Override the maximum crawl depth')
            ->addOption(
                'max-pages',
                null,
                InputOption::VALUE_REQUIRED,
                'Override the maximum number of pages read (0 = no limit)'
            )
            ->addOption(
                'exclude',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Additional regex patterns for URLs to exclude'
            )
            ->addOption(
                'only',
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated modules to run, among those enabled: links, on_page, technical'
            )
            ->addOption('no-external', null, InputOption::VALUE_NONE, 'Do not check external links')
            ->addOption(
                'fail-on',
                null,
                InputOption::VALUE_REQUIRED,
                'Lowest severity that makes the command fail: error, warning or notice'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->lock()) {
            $io->warning('The "seo:check" command is already running in another process. Skipping.');

            return Command::SUCCESS;
        }

        try {
            /** @var string|null $startUrl */
            $startUrl = $input->getArgument('url') ?? $this->defaultBaseUrl;

            if ($startUrl === null || trim($startUrl) === '') {
                $io->error('No URL provided. Pass an URL as argument or configure "seo.base_url".');

                return Command::INVALID;
            }

            /** @var string|null $failOnOption */
            $failOnOption = $input->getOption('fail-on');
            $failOn = Severity::tryFrom($failOnOption ?? $this->defaultFailOn);

            if ($failOn === null) {
                $io->error(sprintf('Invalid --fail-on value "%s". Expected error, warning or notice.', $failOnOption));

                return Command::INVALID;
            }

            /** @var string|null $onlyOption */
            $onlyOption = $input->getOption('only');
            $modules = null;

            if ($onlyOption !== null) {
                $modules = [];

                foreach (array_filter(array_map('trim', explode(',', $onlyOption))) as $name) {
                    $module = Module::tryFrom($name);

                    if ($module === null) {
                        $io->error(
                            sprintf('Invalid --only module "%s". Expected links, on_page or technical.', $name)
                        );

                        return Command::INVALID;
                    }

                    $modules[] = $module;
                }
            }

            /** @var string|null $maxDepthOption */
            $maxDepthOption = $input->getOption('max-depth');
            /** @var string|null $maxPagesOption */
            $maxPagesOption = $input->getOption('max-pages');
            /** @var list<string> $excludePatterns */
            $excludePatterns = (array)$input->getOption('exclude');

            $io->title('SEO Audit');
            $io->text(sprintf('Starting crawl on: <info>%s</info>', $startUrl));
            $io->newLine();

            $progressBar = null;

            if (!$io->isVerbose()) {
                ProgressBar::setPlaceholderFormatterDefinition(
                    'truncated_url',
                    static fn(ProgressBar $bar): string => self::truncate((string)$bar->getMessage())
                );

                $progressBar = $io->createProgressBar();
                $progressBar->setFormat(' %current% pages read [%elapsed%] <fg=cyan>%truncated_url%</>');
                $progressBar->setMessage('Starting...');
                $progressBar->start();
            }

            $progressCallback = static function (string $currentUrl, int $pagesRead) use ($io, $progressBar): void {
                if ($io->isVerbose()) {
                    $io->text(sprintf('(%d) %s', $pagesRead, $currentUrl));
                } elseif ($progressBar !== null) {
                    $progressBar->setMessage($currentUrl);
                    $progressBar->advance();
                }
            };

            $report = $this->auditor->audit(
                startUrl: $startUrl,
                maxDepth: $maxDepthOption !== null ? (int)$maxDepthOption : null,
                maxPages: $maxPagesOption !== null ? (int)$maxPagesOption : null,
                excludePatterns: $excludePatterns,
                modules: $modules,
                checkExternal: $input->getOption('no-external') ? false : null,
                progressCallback: $progressCallback,
            );

            if ($progressBar !== null) {
                $progressBar->finish();
                $io->newLine(2);
            } else {
                $io->newLine();
            }

            self::renderCrawlWarnings($io, $report);

            if (!$report->hasIssues()) {
                $io->success(
                    sprintf(
                        'All clear! Read %d page(s) and checked %d URL(s) in %.2fs with 0 issues.',
                        $report->pagesRead,
                        $report->urlsChecked,
                        $report->totalDuration
                    )
                );

                return Command::SUCCESS;
            }

            self::renderIssuesReport($io, $report);

            if (!$report->hasIssues($failOn)) {
                $io->success(
                    sprintf(
                        'No issue of severity "%s" or above. Read %d page(s) in %.2fs.',
                        $failOn->value,
                        $report->pagesRead,
                        $report->totalDuration
                    )
                );

                return Command::SUCCESS;
            }

            $io->error(
                sprintf(
                    'Found %d issue(s) of severity "%s" or above across %d page(s) (Duration: %.2fs).',
                    $report->getIssuesCount($failOn),
                    $failOn->value,
                    $report->pagesRead,
                    $report->totalDuration
                )
            );

            return Command::FAILURE;
        } finally {
            $this->release();
        }
    }

    private static function renderCrawlWarnings(SymfonyStyle $io, SeoReport $report): void
    {
        if ($report->blockedByRobotsTxt) {
            $io->warning(
                'The site\'s robots.txt answers a server error (5xx, 429 or no response at all): like Google, '
                .'the crawl did not go past the start page. Set "seo.crawl.respect_robots_txt" to false to audit '
                .'the site anyway.'
            );
        }

        if ($report->truncated) {
            $io->warning(
                sprintf(
                    'Stopped after %d page(s): the max_pages limit was reached, so the site was only partially '
                    .'audited. Raise it with --max-pages or "seo.crawl.max_pages".',
                    $report->pagesRead
                )
            );
        }
    }

    private static function renderIssuesReport(SymfonyStyle $io, SeoReport $report): void
    {
        $counts = $report->getIssuesCountBySeverity();

        $io->section(
            sprintf(
                'Issues Found (%d): %d error(s), %d warning(s), %d notice(s)',
                $report->getIssuesCount(),
                $counts[Severity::Error->value],
                $counts[Severity::Warning->value],
                $counts[Severity::Notice->value],
            )
        );

        $table = $io->createTable();
        $table->setHeaders(['Severity', 'Page', 'Issue', 'Message']);
        $table->setStyle('box');

        $severityWidth = 8;
        $issueWidth = 24;
        $borderOverhead = 18;
        $available = max(45, (new Terminal())->getWidth() - $severityWidth - $issueWidth - $borderOverhead);

        $pageWidth = (int)round($available * 0.4);
        $messageWidth = (int)round($available * 0.6);

        $table->setColumnMaxWidth(1, $pageWidth);
        $table->setColumnMaxWidth(2, $issueWidth);
        $table->setColumnMaxWidth(3, $messageWidth);

        foreach ($report->pages as $page) {
            foreach ($page->issues as $issue) {
                $table->addRow([
                    self::formatSeverity($issue->severity()),
                    sprintf('<href=%s>%s</>', $page->url, self::truncate($page->url, $pageWidth)),
                    sprintf('<fg=yellow>%s</>', $issue->type->value),
                    self::truncate($issue->message, $messageWidth),
                ]);
            }
        }

        $table->render();
        $io->newLine();
    }

    private static function formatSeverity(Severity $severity): string
    {
        return match ($severity) {
            Severity::Error => '<fg=red>error</>',
            Severity::Warning => '<fg=yellow>warning</>',
            Severity::Notice => '<fg=default>notice</>',
        };
    }

    private static function truncate(string $text, int $maxLength = 60): string
    {
        return mb_strlen($text, 'UTF-8') > $maxLength
            ? mb_substr($text, 0, $maxLength - 3, 'UTF-8').'...'
            : $text;
    }
}
