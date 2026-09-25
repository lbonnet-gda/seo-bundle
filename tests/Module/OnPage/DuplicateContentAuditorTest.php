<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Module\OnPage;

use Lbonnet\SeoBundle\Crawl\CrawledPage;
use Lbonnet\SeoBundle\Model\Issue;
use Lbonnet\SeoBundle\Model\IssueType;
use Lbonnet\SeoBundle\Model\PageResponse;
use Lbonnet\SeoBundle\Model\PageSignals;
use Lbonnet\SeoBundle\Module\OnPage\DuplicateContentAuditor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class DuplicateContentAuditorTest extends TestCase
{
    public function testFlagsPagesSharingTheSameTitle(): void
    {
        $issues = (new DuplicateContentAuditor())->audit([
            self::page('https://example.com/a', title: 'Same title'),
            self::page('https://example.com/b', title: 'Same title'),
            self::page('https://example.com/c', title: 'Another title'),
        ]);

        $this->assertSame(
            [
                'https://example.com/a' => [IssueType::DuplicateTitle],
                'https://example.com/b' => [IssueType::DuplicateTitle],
            ],
            self::typesByUrl($issues),
        );
        $this->assertSame(
            ['title' => 'Same title', 'pages' => 2],
            $issues['https://example.com/a'][0]->context,
        );
    }

    public function testFlagsPagesSharingTheSameDescription(): void
    {
        $issues = (new DuplicateContentAuditor())->audit([
            self::page('https://example.com/a', description: 'Same description.'),
            self::page('https://example.com/b', description: 'Same description.'),
        ]);

        $this->assertSame(
            [
                'https://example.com/a' => [IssueType::DuplicateDescription],
                'https://example.com/b' => [IssueType::DuplicateDescription],
            ],
            self::typesByUrl($issues),
        );
    }

    public function testPagesSearchEnginesIgnoreAreNotDuplicates(): void
    {
        $issues = (new DuplicateContentAuditor())->audit([
            self::page('https://example.com/blog', title: 'Same title'),
            self::page('https://example.com/blog?p=2', title: 'Same title', metaRobots: ['noindex, follow']),
            self::page('https://example.com/blog?p=3', title: 'Same title', headerRobots: ['noindex']),
            self::page('https://example.com/blog?sort=asc', title: 'Same title', canonical: 'https://example.com/blog'),
        ]);

        $this->assertSame([], $issues);
    }

    public function testPagesWithoutATitleOrDescriptionAreNotDuplicates(): void
    {
        $issues = (new DuplicateContentAuditor())->audit([
            self::page('https://example.com/a'),
            self::page('https://example.com/b'),
            self::page('https://example.com/c', title: 'Unique title', description: 'Unique description.'),
        ]);

        $this->assertSame([], $issues);
    }

    /**
     * @param list<string> $metaRobots
     * @param list<string> $headerRobots
     */
    private static function page(
        string $url,
        ?string $title = null,
        ?string $description = null,
        array $metaRobots = [],
        array $headerRobots = [],
        ?string $canonical = null,
    ): CrawledPage {
        return new CrawledPage(
            url: $url,
            depth: 0,
            response: new PageResponse(
                $url,
                Response::HTTP_OK,
                $headerRobots !== [] ? ['x-robots-tag' => $headerRobots] : [],
            ),
            signals: new PageSignals(
                canonicalHrefs: $canonical !== null ? [$canonical] : [],
                metaRobots: $metaRobots,
                title: $title,
                metaDescription: $description,
            ),
        );
    }

    /**
     * @param array<string, list<Issue>> $issues
     *
     * @return array<string, list<IssueType>>
     */
    private static function typesByUrl(array $issues): array
    {
        return array_map(
            static fn(array $pageIssues): array => array_map(
                static fn(Issue $issue): IssueType => $issue->type,
                $pageIssues,
            ),
            $issues,
        );
    }
}
