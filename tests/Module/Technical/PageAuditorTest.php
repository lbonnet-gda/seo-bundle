<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Module\Technical;

use Lbonnet\SeoBundle\Model\HreflangLink;
use Lbonnet\SeoBundle\Model\Issue;
use Lbonnet\SeoBundle\Model\IssueType;
use Lbonnet\SeoBundle\Model\PageResponse;
use Lbonnet\SeoBundle\Model\PageSignals;
use Lbonnet\SeoBundle\Module\Technical\PageAuditor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class PageAuditorTest extends TestCase
{
    public function testCleanPageHasNoIssue(): void
    {
        $issues = $this->audit(
            new PageSignals(canonicalHrefs: ['https://example.com/a'], htmlLang: 'fr'),
        );

        $this->assertSame([], $issues);
    }

    public function testFlagsConflictingCanonicals(): void
    {
        $issues = $this->audit(
            new PageSignals(
                canonicalHrefs: ['https://example.com/a', 'https://example.com/b'],
                htmlLang: 'fr',
            ),
        );

        $this->assertSame([IssueType::CanonicalMultiple], self::types($issues));
    }

    public function testFlagsCanonicalOutsideHead(): void
    {
        $issues = $this->audit(
            new PageSignals(bodyCanonicalHrefs: ['https://example.com/a'], htmlLang: 'fr'),
        );

        $this->assertSame([IssueType::CanonicalNotInHead], self::types($issues));
    }

    public function testFlagsRelativeCanonical(): void
    {
        $issues = $this->audit(new PageSignals(canonicalHrefs: ['/a'], htmlLang: 'fr'));

        $this->assertSame([IssueType::CanonicalRelative], self::types($issues));
        $this->assertStringContainsString('"/a"', $issues[0]->message);
    }

    public function testFlagsEmptyCanonicalHref(): void
    {
        $issues = $this->audit(new PageSignals(canonicalHrefs: [''], htmlLang: 'fr'));

        $this->assertSame([IssueType::CanonicalRelative], self::types($issues));
        $this->assertStringContainsString('empty href', $issues[0]->message);
    }

    public function testFlagsNoindexOnALinkedPage(): void
    {
        $issues = $this->audit(
            new PageSignals(canonicalHrefs: ['https://example.com/a'], metaRobots: ['noindex'], htmlLang: 'fr'),
            depth: 2,
        );

        $this->assertSame([IssueType::NoindexOnLinkedPage], self::types($issues));
    }

    public function testDoesNotFlagNoindexOnTheStartUrl(): void
    {
        $issues = $this->audit(
            new PageSignals(canonicalHrefs: ['https://example.com/a'], metaRobots: ['noindex'], htmlLang: 'fr')
        );

        $this->assertSame([], $issues);
    }

    public function testFlagsNoindexComingFromTheHeaderOnly(): void
    {
        $issues = $this->audit(
            new PageSignals(canonicalHrefs: ['https://example.com/a'], htmlLang: 'fr'),
            depth: 1,
            headers: ['x-robots-tag' => ['noindex']],
        );

        $this->assertSame([IssueType::NoindexOnLinkedPage], self::types($issues));
    }

    public function testFlagsDirectiveConflictBetweenMetaAndHeader(): void
    {
        $issues = $this->audit(
            new PageSignals(canonicalHrefs: ['https://example.com/a'], metaRobots: ['index, follow'], htmlLang: 'fr'),
            depth: 1,
            headers: ['x-robots-tag' => ['noindex']],
        );

        $this->assertSame(
            [IssueType::RobotsDirectiveConflict, IssueType::NoindexOnLinkedPage],
            self::types($issues),
        );
    }

    public function testAgreeingDirectivesAreNotAConflict(): void
    {
        $issues = $this->audit(
            new PageSignals(canonicalHrefs: ['https://example.com/a'], metaRobots: ['noindex'], htmlLang: 'fr'),
            depth: 1,
            headers: ['x-robots-tag' => ['noindex, nofollow']],
        );

        $this->assertSame([IssueType::NoindexOnLinkedPage], self::types($issues));
    }

    public function testUserAgentPrefixedHeaderDirectiveIsUnderstood(): void
    {
        $issues = $this->audit(
            new PageSignals(canonicalHrefs: ['https://example.com/a'], htmlLang: 'fr'),
            depth: 1,
            headers: ['x-robots-tag' => ['googlebot: noindex']],
        );

        $this->assertSame([IssueType::NoindexOnLinkedPage], self::types($issues));
    }

    public function testFlagsMetaRefresh(): void
    {
        $issues = $this->audit(
            new PageSignals(
                canonicalHrefs: ['https://example.com/a'],
                metaRefreshUrl: 'https://example.com/new',
                htmlLang: 'fr',
            ),
        );

        $this->assertSame([IssueType::MetaRefreshRedirect], self::types($issues));
    }

    public function testFlagsNoindexCombinedWithACanonicalElsewhere(): void
    {
        $issues = $this->audit(
            new PageSignals(canonicalHrefs: ['https://example.com/other'], metaRobots: ['noindex'], htmlLang: 'fr'),
        );

        $this->assertSame([IssueType::NoindexConflictsWithCanonical], self::types($issues));
    }

    public function testFlagsMissingHtmlLang(): void
    {
        $issues = $this->audit(new PageSignals(canonicalHrefs: ['https://example.com/a']));

        $this->assertSame([IssueType::MissingHtmlLang], self::types($issues));
    }

    public function testFlagsHreflangLinksOutsideHeadEvenWithoutAnyInside(): void
    {
        $issues = $this->audit(
            new PageSignals(
                canonicalHrefs: ['https://example.com/a'],
                htmlLang: 'fr',
                bodyHreflangLinks: [
                    new HreflangLink('fr', 'https://example.com/a'),
                    new HreflangLink('en', 'https://example.com/en/a'),
                ],
            )
        );

        $this->assertSame([IssueType::HreflangNotInHead], self::types($issues));
        $this->assertStringContainsString('2 hreflang link(s)', $issues[0]->message);
    }

    public function testFlagsAStrayHreflangLinkNextToACompleteSet(): void
    {
        $issues = $this->audit(
            new PageSignals(
                canonicalHrefs: ['https://example.com/a'],
                htmlLang: 'fr',
                hreflangLinks: [
                    new HreflangLink('fr', 'https://example.com/a'),
                    new HreflangLink('en', 'https://example.com/en/a'),
                    new HreflangLink('x-default', 'https://example.com/a'),
                ],
                bodyHreflangLinks: [new HreflangLink('de', 'https://example.com/de/a')],
            )
        );

        $this->assertSame([IssueType::HreflangNotInHead], self::types($issues));
    }

    public function testACompleteHreflangSetHasNoIssue(): void
    {
        $this->assertSame([], $this->audit(self::withHreflang([
            new HreflangLink('fr', 'https://example.com/a'),
            new HreflangLink('en', 'https://example.com/en/a'),
            new HreflangLink('x-default', 'https://example.com/a'),
        ])));
    }

    public function testFlagsAnInvalidHreflangCode(): void
    {
        $issues = $this->audit(self::withHreflang([
            new HreflangLink('fr', 'https://example.com/a'),
            new HreflangLink('en-UK', 'https://example.com/en/a'),
            new HreflangLink('x-default', 'https://example.com/a'),
        ]));

        $this->assertSame([IssueType::HreflangInvalidCode], self::types($issues));
        $this->assertStringContainsString('"GB"', $issues[0]->message);
    }

    public function testFlagsARelativeHreflangUrl(): void
    {
        $issues = $this->audit(self::withHreflang([
            new HreflangLink('fr', '/a'),
            new HreflangLink('en', 'https://example.com/en/a'),
            new HreflangLink('x-default', 'https://example.com/a'),
        ]));

        $this->assertSame([IssueType::HreflangRelativeUrl], self::types($issues));
    }

    public function testFlagsOneHreflangValueDeclaredForSeveralUrls(): void
    {
        $issues = $this->audit(self::withHreflang([
            new HreflangLink('fr', 'https://example.com/a'),
            new HreflangLink('FR', 'https://example.com/fr/a'),
            new HreflangLink('x-default', 'https://example.com/a'),
        ]));

        $this->assertSame([IssueType::HreflangConflictingUrls], self::types($issues));
    }

    public function testFlagsAnHreflangSetMissingThePageItself(): void
    {
        $issues = $this->audit(self::withHreflang([
            new HreflangLink('en', 'https://example.com/en/a'),
            new HreflangLink('x-default', 'https://example.com/en/a'),
        ]));

        $this->assertSame([IssueType::HreflangMissingSelf], self::types($issues));
    }

    public function testFlagsAMissingXDefault(): void
    {
        $issues = $this->audit(self::withHreflang([
            new HreflangLink('fr', 'https://example.com/a'),
            new HreflangLink('en', 'https://example.com/en/a'),
        ]));

        $this->assertSame([IssueType::HreflangMissingXDefault], self::types($issues));
    }

    public function testIgnoresHreflangOnACanonicalizedVariant(): void
    {
        $issues = $this->audit(
            self::withHreflang(
                [
                    new HreflangLink('en', 'https://example.com/en/list'),
                    new HreflangLink('fr', 'https://example.com/fr/list'),
                ],
                canonical: 'https://example.com/en/list',
            )
        );

        $this->assertSame([], $issues);
    }

    public function testIgnoresHreflangOutsideHeadOnACanonicalizedVariant(): void
    {
        $issues = $this->audit(
            new PageSignals(
                canonicalHrefs: ['https://example.com/en/list'],
                htmlLang: 'fr',
                bodyHreflangLinks: [new HreflangLink('en', 'https://example.com/en/list')],
            )
        );

        $this->assertSame([], $issues);
    }

    public function testFlagsHreflangOnANonCanonicalPage(): void
    {
        $issues = $this->audit(
            self::withHreflang(
                [
                    new HreflangLink('fr', 'https://example.com/a'),
                    new HreflangLink('x-default', 'https://example.com/a'),
                ],
                canonical: 'https://example.com/other',
            )
        );

        $this->assertSame([IssueType::HreflangCanonicalMismatch], self::types($issues));
    }

    /**
     * @param list<HreflangLink> $links
     */
    private static function withHreflang(array $links, string $canonical = 'https://example.com/a'): PageSignals
    {
        return new PageSignals(canonicalHrefs: [$canonical], htmlLang: 'fr', hreflangLinks: $links);
    }

    /**
     * @param array<string, list<string>> $headers
     *
     * @return list<Issue>
     */
    private function audit(PageSignals $signals, int $depth = 0, array $headers = []): array
    {
        $response = new PageResponse(
            url: 'https://example.com/a',
            statusCode: Response::HTTP_OK,
            headers: $headers,
            html: '<html></html>',
            depth: $depth,
        );

        return (new PageAuditor())->audit($response, $signals);
    }

    /**
     * @param list<Issue> $issues
     *
     * @return list<IssueType>
     */
    private static function types(array $issues): array
    {
        return array_map(static fn(Issue $issue): IssueType => $issue->type, $issues);
    }
}
