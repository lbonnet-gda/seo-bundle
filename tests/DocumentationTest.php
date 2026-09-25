<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests;

use Lbonnet\SeoBundle\Model\IssueType;
use Lbonnet\SeoBundle\SeoBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Yaml\Yaml;

/**
 * Keeps the documentation honest: its configuration example must load, and its tables must list every check.
 */
final class DocumentationTest extends TestCase
{
    public function testTheConfigurationExampleIsValid(): void
    {
        /** @var array<string, mixed> $config */
        $config = Yaml::parse($this->documentedConfiguration());

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

        // The values the example presents as the defaults must really be.
        $this->assertSame(500, $container->getParameter('seo.crawl.max_pages'));
        $this->assertSame(155, $container->getParameter('seo.on_page.max_description_length'));
        $this->assertSame(10, $container->getParameter('seo.technical.max_sitemap_files'));
        $this->assertSame(SeoBundle::DEFAULT_USER_AGENT, $container->getParameter('seo.crawl.user_agent'));
    }

    public function testEveryCheckIsDocumentedWithItsSeverity(): void
    {
        preg_match_all(
            '/^\| `([a-z0-9_]+)`\s*\| (error|warning|notice)\s*\|/m',
            $this->doc('checks'),
            $matches,
            PREG_SET_ORDER,
        );

        $documented = [];

        foreach ($matches as [, $check, $severity]) {
            $documented[$check] = $severity;
        }

        $expected = [];

        foreach (IssueType::cases() as $type) {
            $expected[$type->value] = $type->severity()->value;
        }

        ksort($documented);
        ksort($expected);

        $this->assertSame($expected, $documented);
    }

    public function testTheReadmeLinksToEveryDocumentationPage(): void
    {
        $readme = (string)file_get_contents(__DIR__.'/../README.md');

        foreach (glob(__DIR__.'/../docs/*.md') ?: [] as $page) {
            $this->assertStringContainsString(
                sprintf('(docs/%s)', basename($page)),
                $readme,
                basename($page).' should be linked from the README',
            );
        }
    }

    private function doc(string $name): string
    {
        return (string)file_get_contents(sprintf('%s/../docs/%s.md', __DIR__, $name));
    }

    private function documentedConfiguration(): string
    {
        $doc = $this->doc('configuration');
        $start = strpos($doc, "```yaml\nseo:");
        $this->assertNotFalse($start, 'docs/configuration.md must document the configuration in a yaml block.');

        $block = substr($doc, $start + strlen("```yaml\n"));

        return substr($block, 0, (int)strpos($block, '```'));
    }
}
