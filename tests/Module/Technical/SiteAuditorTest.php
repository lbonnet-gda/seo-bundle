<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Module\Technical;

use Lbonnet\SeoBundle\Model\HreflangLink;
use Lbonnet\SeoBundle\Model\Issue;
use Lbonnet\SeoBundle\Model\IssueType;
use Lbonnet\SeoBundle\Model\PageResponse;
use Lbonnet\SeoBundle\Model\PageSignals;
use Lbonnet\SeoBundle\Model\RedirectChain;
use Lbonnet\SeoBundle\Model\RedirectHop;
use Lbonnet\SeoBundle\Module\Technical\Http\TargetProbeInterface;
use Lbonnet\SeoBundle\Module\Technical\Model\CrawlContext;
use Lbonnet\SeoBundle\Module\Technical\Model\PageAudit;
use Lbonnet\SeoBundle\Module\Technical\SiteAuditor;
use Lbonnet\SeoBundle\Module\Technical\SitemapAuditorInterface;
use Lbonnet\SeoBundle\Module\Technical\UrlVariantAuditorInterface;
use Lbonnet\SeoBundle\Robots\RobotsTxt;
use Lbonnet\SeoBundle\Robots\RobotsTxtProviderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class SiteAuditorTest extends TestCase
{
    public function testFlagsACanonicalPointingToARedirect(): void
    {
        $pages = [$this->page('https://example.com/a', canonical: 'https://example.com/b')];
        $context = new CrawlContext(
            responses: [
                'https://example.com/b' => new PageResponse(
                    'https://example.com/b',
                    Response::HTTP_MOVED_PERMANENTLY,
                ),
            ],
        );

        $audited = $this->auditor()->audit($pages, $context);

        $this->assertSame([IssueType::CanonicalTargetRedirects], self::types($audited[0]->issues));
    }

    public function testFlagsACanonicalPointingToAnError(): void
    {
        $pages = [$this->page('https://example.com/a', canonical: 'https://example.com/b')];
        $context = new CrawlContext(
            responses: [
                'https://example.com/b' => new PageResponse('https://example.com/b', Response::HTTP_NOT_FOUND),
            ],
        );

        $audited = $this->auditor()->audit($pages, $context);

        $this->assertSame([IssueType::CanonicalTargetNotOk], self::types($audited[0]->issues));
    }

    public function testSelfReferencingCanonicalIsFine(): void
    {
        $pages = [$this->page('https://example.com/a', canonical: 'https://example.com/a')];

        $audited = $this->auditor()->audit($pages, new CrawlContext());

        $this->assertSame([], $audited[0]->issues);
    }

    public function testRelativeCanonicalIsResolvedAgainstThePage(): void
    {
        $pages = [$this->page('https://example.com/section/a', canonical: '../b')];
        $context = new CrawlContext(
            responses: [
                'https://example.com/b' => new PageResponse('https://example.com/b', Response::HTTP_GONE),
            ],
        );

        $audited = $this->auditor()->audit($pages, $context);

        $this->assertSame([IssueType::CanonicalTargetNotOk], self::types($audited[0]->issues));
    }

    public function testProbesACanonicalTargetOutsideTheCrawl(): void
    {
        $probe = $this->createMock(TargetProbeInterface::class);
        $probe->expects($this->once())
            ->method('probe')
            ->with('https://example.com/b')
            ->willReturn(new PageResponse('https://example.com/b', Response::HTTP_NOT_FOUND));

        $pages = [$this->page('https://example.com/a', canonical: 'https://example.com/b')];

        $audited = (new SiteAuditor($probe))->audit($pages, new CrawlContext());

        $this->assertSame([IssueType::CanonicalTargetNotOk], self::types($audited[0]->issues));
    }

    public function testAnUnknownCanonicalTargetIsNotReported(): void
    {
        $pages = [$this->page('https://example.com/a', canonical: 'https://example.com/b')];

        $audited = $this->auditor()->audit($pages, new CrawlContext());

        $this->assertSame([], $audited[0]->issues);
    }

    public function testFlagsEveryPageLinkingToARedirect(): void
    {
        $pages = [
            $this->page('https://example.com/a'),
            $this->page('https://example.com/b'),
        ];
        $context = new CrawlContext(
            redirectChains: [
                'https://example.com/old' => new RedirectChain(
                    'https://example.com/old',
                    Response::HTTP_MOVED_PERMANENTLY,
                    [
                        new RedirectHop(
                            'https://example.com/old',
                            Response::HTTP_MOVED_PERMANENTLY,
                            'https://example.com/new'
                        ),
                    ],
                    'https://example.com/new',
                    Response::HTTP_OK,
                ),
            ],
            referrers: [
                'https://example.com/old' => ['https://example.com/a', 'https://example.com/b'],
            ],
        );

        $audited = $this->auditor()->audit($pages, $context);

        $this->assertSame([IssueType::InternalLinkToRedirect], self::types($audited[0]->issues));
        $this->assertSame([IssueType::InternalLinkToRedirect], self::types($audited[1]->issues));
        $this->assertStringContainsString('https://example.com/new', $audited[0]->issues[0]->message);
    }

    public function testFlagsAChainLongerThanAllowed(): void
    {
        $chain = new RedirectChain(
            'https://example.com/old',
            Response::HTTP_MOVED_PERMANENTLY,
            [
                new RedirectHop('https://example.com/old', Response::HTTP_MOVED_PERMANENTLY, 'https://example.com/mid'),
                new RedirectHop('https://example.com/mid', Response::HTTP_MOVED_PERMANENTLY, 'https://example.com/new'),
            ],
            'https://example.com/new',
            Response::HTTP_OK,
        );

        $context = new CrawlContext(
            redirectChains: ['https://example.com/old' => $chain],
            referrers: ['https://example.com/old' => ['https://example.com/a']],
        );

        $audited = $this->auditor()->audit([$this->page('https://example.com/a')], $context);

        $this->assertCount(2, $audited);
        $this->assertSame([IssueType::InternalLinkToRedirect], self::types($audited[0]->issues));
        $this->assertSame('https://example.com/old', $audited[1]->url);
        $this->assertSame([IssueType::RedirectChainTooLong], self::types($audited[1]->issues));
    }

    public function testAChainNobodyLinksToBecomesItsOwnEntry(): void
    {
        $chain = new RedirectChain(
            'https://example.com/',
            Response::HTTP_MOVED_PERMANENTLY,
            [
                new RedirectHop('https://example.com/', Response::HTTP_MOVED_PERMANENTLY, 'https://example.com/a'),
                new RedirectHop('https://example.com/a', Response::HTTP_MOVED_PERMANENTLY, 'https://example.com/'),
            ],
            'https://example.com/',
            null,
            isLoop: true,
        );

        $audited = $this->auditor()->audit([], new CrawlContext(redirectChains: ['https://example.com/' => $chain]));

        $this->assertCount(1, $audited);
        $this->assertSame('https://example.com/', $audited[0]->url);
        $this->assertSame(Response::HTTP_MOVED_PERMANENTLY, $audited[0]->statusCode);
        $this->assertSame([IssueType::RedirectLoop], self::types($audited[0]->issues));
    }

    public function testAShortChainNobodyLinksToIsNotReported(): void
    {
        $chain = new RedirectChain(
            'https://example.com/',
            Response::HTTP_MOVED_PERMANENTLY,
            [new RedirectHop('https://example.com/', Response::HTTP_MOVED_PERMANENTLY, 'https://example.com/a')],
            'https://example.com/a',
            Response::HTTP_OK,
        );

        $audited = $this->auditor()->audit([], new CrawlContext(redirectChains: ['https://example.com/' => $chain]));

        $this->assertSame([], $audited);
    }

    public function testDisabledChecksAreDroppedFromTheReport(): void
    {
        $pages = [
            $this->page('https://example.com/a', issues: [
                new Issue(IssueType::MissingHtmlLang, 'no lang'),
                new Issue(IssueType::CanonicalRelative, 'relative'),
            ]),
        ];

        $auditor = new SiteAuditor(
            $this->createMock(TargetProbeInterface::class),
            disabledChecks: [IssueType::MissingHtmlLang->value],
        );

        $audited = $auditor->audit($pages, new CrawlContext());

        $this->assertSame([IssueType::CanonicalRelative], self::types($audited[0]->issues));
    }

    public function testARedirectIssueIsReportedOnceHoweverManyPagesLinkToIt(): void
    {
        $chain = new RedirectChain(
            'https://example.com/old',
            Response::HTTP_MOVED_PERMANENTLY,
            [new RedirectHop('https://example.com/old', Response::HTTP_MOVED_PERMANENTLY, 'https://example.com/gone')],
            'https://example.com/gone',
            Response::HTTP_NOT_FOUND,
        );

        $context = new CrawlContext(
            redirectChains: ['https://example.com/old' => $chain],
            referrers: ['https://example.com/old' => ['https://example.com/a', 'https://example.com/b']],
        );

        $audited = $this->auditor()->audit(
            [$this->page('https://example.com/a'), $this->page('https://example.com/b')],
            $context,
        );

        $this->assertCount(3, $audited);
        $this->assertSame([IssueType::InternalLinkToRedirect], self::types($audited[0]->issues));
        $this->assertSame([IssueType::InternalLinkToRedirect], self::types($audited[1]->issues));
        $this->assertStringContainsString('never leads to a working page', $audited[0]->issues[0]->message);
        $this->assertSame('https://example.com/old', $audited[2]->url);
        $this->assertSame([IssueType::RedirectToError], self::types($audited[2]->issues));
    }

    public function testFlagsATemporaryRedirect(): void
    {
        $chain = new RedirectChain(
            'https://example.com/',
            Response::HTTP_FOUND,
            [new RedirectHop('https://example.com/', Response::HTTP_FOUND, 'https://example.com/fr/')],
            'https://example.com/fr/',
            Response::HTTP_OK,
        );

        $audited = $this->auditor()->audit([], new CrawlContext(redirectChains: ['https://example.com/' => $chain]));

        $this->assertSame([IssueType::TemporaryRedirect], self::types($audited[0]->issues));
        $this->assertStringContainsString('302', $audited[0]->issues[0]->message);
    }

    public function testAPermanentRedirectIsNotTemporary(): void
    {
        $chain = new RedirectChain(
            'https://example.com/',
            Response::HTTP_PERMANENTLY_REDIRECT,
            [new RedirectHop('https://example.com/', Response::HTTP_PERMANENTLY_REDIRECT, 'https://example.com/fr/')],
            'https://example.com/fr/',
            Response::HTTP_OK,
        );

        $audited = $this->auditor()->audit([], new CrawlContext(redirectChains: ['https://example.com/' => $chain]));

        $this->assertSame([], $audited);
    }

    public function testFlagsACanonicalPointingToANoindexPage(): void
    {
        $pages = [
            $this->page('https://example.com/a', canonical: 'https://example.com/b'),
            $this->page('https://example.com/b', metaRobots: ['noindex']),
        ];
        $context = new CrawlContext(
            responses: ['https://example.com/b' => new PageResponse('https://example.com/b', Response::HTTP_OK)],
        );

        $audited = $this->auditor()->audit($pages, $context);

        $this->assertSame([IssueType::CanonicalTargetNoindex], self::types($audited[0]->issues));
        $this->assertSame([], $audited[1]->issues);
    }

    public function testFlagsACanonicalTargetNoindexedByItsHeader(): void
    {
        $probe = $this->createMock(TargetProbeInterface::class);
        $probe->method('probe')->willReturn(
            new PageResponse('https://example.com/b', Response::HTTP_OK, ['x-robots-tag' => ['noindex']]),
        );

        $pages = [$this->page('https://example.com/a', canonical: 'https://example.com/b')];

        $audited = (new SiteAuditor($probe))->audit($pages, new CrawlContext());

        $this->assertSame([IssueType::CanonicalTargetNoindex], self::types($audited[0]->issues));
    }

    public function testFlagsACanonicalChain(): void
    {
        $pages = [
            $this->page('https://example.com/a', canonical: 'https://example.com/b'),
            $this->page('https://example.com/b', canonical: 'https://example.com/c'),
        ];
        $context = new CrawlContext(
            responses: ['https://example.com/b' => new PageResponse('https://example.com/b', Response::HTTP_OK)],
        );

        $audited = $this->auditor()->audit($pages, $context);

        $this->assertSame([IssueType::CanonicalChain], self::types($audited[0]->issues));
        $this->assertStringContainsString('https://example.com/c', $audited[0]->issues[0]->message);
        $this->assertSame([], $audited[1]->issues);
    }

    public function testFlagsTwoPagesDeclaringEachOtherCanonical(): void
    {
        $pages = [
            $this->page('https://example.com/a', canonical: 'https://example.com/b'),
            $this->page('https://example.com/b', canonical: 'https://example.com/a'),
        ];
        $context = new CrawlContext(
            responses: [
                'https://example.com/a' => new PageResponse('https://example.com/a', Response::HTTP_OK),
                'https://example.com/b' => new PageResponse('https://example.com/b', Response::HTTP_OK),
            ],
        );

        $audited = $this->auditor()->audit($pages, $context);

        $this->assertSame([IssueType::CanonicalChain], self::types($audited[0]->issues));
        $this->assertSame([IssueType::CanonicalChain], self::types($audited[1]->issues));
        $this->assertStringContainsString('points back', $audited[0]->issues[0]->message);
    }

    public function testAReciprocalHreflangPairHasNoIssue(): void
    {
        $pair = ['fr' => 'https://example.com/fr', 'en' => 'https://example.com/en'];
        $pages = [
            $this->page('https://example.com/fr', hreflang: $pair),
            $this->page('https://example.com/en', hreflang: $pair),
        ];

        $context = self::okResponses('https://example.com/fr', 'https://example.com/en');
        $audited = $this->auditor()->audit($pages, $context);

        $this->assertSame([], $audited[0]->issues);
        $this->assertSame([], $audited[1]->issues);
    }

    public function testFlagsAnHreflangAlternateThatDoesNotLinkBack(): void
    {
        $pages = [
            $this->page(
                'https://example.com/fr',
                hreflang: ['fr' => 'https://example.com/fr', 'en' => 'https://example.com/en'],
            ),
            $this->page('https://example.com/en', hreflang: ['en' => 'https://example.com/en']),
        ];

        $context = self::okResponses('https://example.com/fr', 'https://example.com/en');
        $audited = $this->auditor()->audit($pages, $context);

        $this->assertSame([IssueType::HreflangNotReciprocal], self::types($audited[0]->issues));
        $this->assertSame([], $audited[1]->issues);
    }

    public function testFlagsAnHreflangAlternateInError(): void
    {
        $pages = [$this->page('https://example.com/fr', hreflang: ['en' => 'https://example.com/en'])];
        $context = new CrawlContext(
            responses: [
                'https://example.com/en' => new PageResponse('https://example.com/en', Response::HTTP_NOT_FOUND),
            ],
        );

        $audited = $this->auditor()->audit($pages, $context);

        $this->assertSame([IssueType::HreflangTargetNotOk], self::types($audited[0]->issues));
    }

    public function testFlagsARedirectingHreflangAlternate(): void
    {
        $pages = [$this->page('https://example.com/fr', hreflang: ['en' => 'https://example.com/en'])];
        $context = new CrawlContext(
            responses: [
                'https://example.com/en' => new PageResponse(
                    'https://example.com/en',
                    Response::HTTP_MOVED_PERMANENTLY,
                ),
            ],
        );

        $audited = $this->auditor()->audit($pages, $context);

        $this->assertSame([IssueType::HreflangTargetRedirects], self::types($audited[0]->issues));
    }

    public function testFlagsANoindexHreflangAlternate(): void
    {
        $pair = ['fr' => 'https://example.com/fr', 'en' => 'https://example.com/en'];
        $pages = [
            $this->page('https://example.com/fr', hreflang: $pair),
            $this->page('https://example.com/en', metaRobots: ['noindex'], hreflang: $pair),
        ];

        $context = self::okResponses('https://example.com/fr', 'https://example.com/en');
        $audited = $this->auditor()->audit($pages, $context);

        $this->assertSame([IssueType::HreflangTargetNoindex], self::types($audited[0]->issues));
    }

    public function testDoesNotCheckReciprocityOfAnAlternateOutsideTheCrawl(): void
    {
        $probe = $this->createMock(TargetProbeInterface::class);
        $probe->expects($this->once())
            ->method('probe')
            ->with('https://example.de/')
            ->willReturn(new PageResponse('https://example.de/', Response::HTTP_OK));

        $pages = [$this->page('https://example.com/fr', hreflang: ['de' => 'https://example.de/'])];

        $audited = (new SiteAuditor($probe))->audit($pages, new CrawlContext());

        $this->assertSame([], $audited[0]->issues);
    }

    public function testDoesNotAuditHreflangTargetsOfACanonicalizedVariant(): void
    {
        $cluster = ['en' => 'https://example.com/en/presse', 'fr' => 'https://example.com/fr/presse'];
        $pages = [
            $this->page(
                'https://example.com/en/presse',
                canonical: 'https://example.com/en/presse',
                hreflang: $cluster,
            ),
            $this->page(
                'https://example.com/fr/presse',
                canonical: 'https://example.com/fr/presse',
                hreflang: $cluster,
            ),
            $this->page(
                'https://example.com/en/presse?categoryId=5',
                canonical: 'https://example.com/en/presse',
                hreflang: $cluster,
            ),
        ];
        $context = self::okResponses(
            'https://example.com/en/presse',
            'https://example.com/fr/presse',
            'https://example.com/en/presse?categoryId=5',
        );

        $audited = $this->auditor()->audit($pages, $context);

        $this->assertSame([[], [], []], array_map(static fn(PageAudit $page): array => $page->issues, $audited));
    }

    public function testFlagsAnHreflangAlternateThatIsNotCanonical(): void
    {
        $pages = [
            $this->page(
                'https://example.com/fr/presse',
                hreflang: ['fr' => 'https://example.com/fr/presse', 'en' => 'https://example.com/en/presse?utm=x'],
            ),
            $this->page(
                'https://example.com/en/presse?utm=x',
                canonical: 'https://example.com/en/presse',
                hreflang: ['en' => 'https://example.com/en/presse', 'fr' => 'https://example.com/fr/presse'],
            ),
        ];
        $context = self::okResponses('https://example.com/fr/presse', 'https://example.com/en/presse?utm=x');

        $audited = $this->auditor()->audit($pages, $context);

        $this->assertSame([IssueType::HreflangTargetNotCanonical], self::types($audited[0]->issues));
        $this->assertStringContainsString('"https://example.com/en/presse"', $audited[0]->issues[0]->message);
        $this->assertSame([], $audited[1]->issues);
    }

    public function testANonCanonicalAlternateIsNotAlsoCheckedForNoindexOrReciprocity(): void
    {
        $pages = [
            $this->page(
                'https://example.com/fr/presse',
                hreflang: ['fr' => 'https://example.com/fr/presse', 'en' => 'https://example.com/en/presse?utm=x'],
            ),
            $this->page(
                'https://example.com/en/presse?utm=x',
                canonical: 'https://example.com/en/presse',
                metaRobots: ['noindex'],
            ),
        ];
        $context = self::okResponses('https://example.com/fr/presse', 'https://example.com/en/presse?utm=x');

        $audited = $this->auditor()->audit($pages, $context);

        $this->assertSame([IssueType::HreflangTargetNotCanonical], self::types($audited[0]->issues));
    }

    public function testReportsARobotsTxtServerErrorOnceForTheWholeSite(): void
    {
        $auditor = $this->auditorWithRobotsTxt([
            'example.com' => RobotsTxt::serverError(
                'https://example.com/robots.txt',
                Response::HTTP_SERVICE_UNAVAILABLE,
            ),
        ]);

        $audited = $auditor->audit(
            [$this->page('https://example.com/a'), $this->page('https://example.com/b')],
            new CrawlContext(),
        );

        $this->assertCount(3, $audited);
        $this->assertSame([], $audited[0]->issues);
        $this->assertSame([], $audited[1]->issues);
        $this->assertSame('https://example.com/robots.txt', $audited[2]->url);
        $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $audited[2]->statusCode);
        $this->assertSame([IssueType::RobotsTxtServerError], self::types($audited[2]->issues));
    }

    public function testReportsARobotsTxtThatCannotBeFetchedAtAll(): void
    {
        $auditor = $this->auditorWithRobotsTxt([
            'example.com' => RobotsTxt::serverError('https://example.com/robots.txt', null),
        ]);

        $audited = $auditor->audit([$this->page('https://example.com/a')], new CrawlContext());

        $this->assertSame([IssueType::RobotsTxtServerError], self::types($audited[1]->issues));
        $this->assertStringContainsString('could not be fetched', $audited[1]->issues[0]->message);
    }

    public function testFlagsARobotsTxtBlockingGooglebotFromTheSiteRoot(): void
    {
        foreach (["User-agent: *\nDisallow: /\n", "User-agent: Googlebot\nDisallow: /\n"] as $content) {
            $auditor = $this->auditorWithRobotsTxt(['example.com' => self::robotsTxt('example.com', $content)]);

            $audited = $auditor->audit([$this->page('https://example.com/a')], new CrawlContext());

            $this->assertCount(2, $audited, $content);
            $this->assertSame([IssueType::RobotsTxtDisallowAll], self::types($audited[1]->issues), $content);
        }
    }

    public function testARobotsTxtBlockingAnotherCrawlerOrMissingIsFine(): void
    {
        foreach (
            [
                self::robotsTxt('example.com', "User-agent: OtherBot\nDisallow: /\n"),
                RobotsTxt::notFound('https://example.com/robots.txt', Response::HTTP_NOT_FOUND),
            ] as $robotsTxt
        ) {
            $auditor = $this->auditorWithRobotsTxt(['example.com' => $robotsTxt]);

            $audited = $auditor->audit([$this->page('https://example.com/a')], new CrawlContext());

            $this->assertCount(1, $audited);
            $this->assertSame([], $audited[0]->issues);
        }
    }

    public function testFlagsACanonicalTargetBlockedForGooglebotWithoutRequestingIt(): void
    {
        $probe = $this->createMock(TargetProbeInterface::class);
        $probe->expects($this->never())->method('probe');

        $auditor = $this->auditorWithRobotsTxt(
            ['example.com' => self::robotsTxt('example.com', "User-agent: *\nDisallow: /private\n")],
            $probe,
        );

        $audited = $auditor->audit(
            [$this->page('https://example.com/a', canonical: 'https://example.com/private/b')],
            new CrawlContext(),
        );

        $this->assertCount(1, $audited);
        $this->assertSame([IssueType::RobotsTxtBlocksCanonicalTarget], self::types($audited[0]->issues));
    }

    public function testFlagsAnHreflangAlternateBlockedForGooglebot(): void
    {
        $probe = $this->createMock(TargetProbeInterface::class);
        $probe->expects($this->never())->method('probe');

        $auditor = $this->auditorWithRobotsTxt(
            ['example.com' => self::robotsTxt('example.com', "User-agent: *\nDisallow: /private\n")],
            $probe,
        );

        $audited = $auditor->audit(
            [
                $this->page(
                    'https://example.com/fr',
                    hreflang: ['fr' => 'https://example.com/fr', 'en' => 'https://example.com/private/en'],
                ),
            ],
            new CrawlContext(),
        );

        $this->assertSame([IssueType::RobotsTxtBlocksHreflangAlternate], self::types($audited[0]->issues));
    }

    public function testACanonicalToTheHttpVersionOfACrawledPageIsStillChecked(): void
    {
        $probe = $this->createMock(TargetProbeInterface::class);
        $probe->expects($this->once())
            ->method('probe')
            ->with('http://example.com/page')
            ->willReturn(new PageResponse('http://example.com/page', Response::HTTP_MOVED_PERMANENTLY));

        $auditor = new SiteAuditor($probe);

        $audited = $auditor->audit(
            [$this->page('https://example.com/page', canonical: 'http://example.com/page')],
            self::okResponses('https://example.com/page'),
        );

        $this->assertSame([IssueType::CanonicalTargetRedirects], self::types($audited[0]->issues));
    }

    public function testReadsTheRobotsTxtOfATargetOnAnotherHostThroughTheProbe(): void
    {
        $probe = $this->createMock(TargetProbeInterface::class);
        $probe->method('robotsTxt')->willReturnCallback(
            static fn(string $url): ?RobotsTxt => parse_url($url, PHP_URL_HOST) === 'other.com'
                ? self::robotsTxt('other.com', "User-agent: *\nDisallow: /private\n")
                : null,
        );
        $probe->expects($this->never())->method('probe');

        $auditor = $this->auditorWithRobotsTxt(
            ['example.com' => self::robotsTxt('example.com', "User-agent: *\nAllow: /\n")],
            $probe,
        );

        $audited = $auditor->audit(
            [$this->page('https://example.com/a', canonical: 'https://other.com/private/b')],
            new CrawlContext(),
        );

        $this->assertSame([IssueType::RobotsTxtBlocksCanonicalTarget], self::types($audited[0]->issues));
    }

    public function testATargetOnAnotherHostIsNotCheckedAgainstRobotsTxtWhenTheProbeDeclines(): void
    {
        $provider = $this->createMock(RobotsTxtProviderInterface::class);
        $provider->method('robotsTxt')->willReturnCallback(
            function (string $url): RobotsTxt {
                $this->assertSame('example.com', parse_url($url, PHP_URL_HOST), 'only a crawled host is fetched');

                return self::robotsTxt('example.com', "User-agent: *\nAllow: /\n");
            },
        );

        $probe = $this->createMock(TargetProbeInterface::class);
        $probe->method('robotsTxt')->willReturn(null);
        $probe->expects($this->once())->method('probe')->willReturn(
            new PageResponse(url: 'https://other.com/private/b', statusCode: Response::HTTP_OK),
        );

        $auditor = new SiteAuditor($probe, robotsTxtProvider: $provider);

        $audited = $auditor->audit(
            [$this->page('https://example.com/a', canonical: 'https://other.com/private/b')],
            new CrawlContext(),
        );

        $this->assertSame([], $audited[0]->issues);
    }

    public function testMergesSitemapIssuesIntoTheCrawledPageTheyConcern(): void
    {
        $sitemapAuditor = $this->createMock(SitemapAuditorInterface::class);
        $sitemapAuditor->method('audit')->willReturn([
            new PageAudit(
                url: 'https://example.com/sitemap.xml',
                statusCode: Response::HTTP_OK,
                issues: [new Issue(IssueType::SitemapUrlNotOk, 'gone')],
            ),
            new PageAudit(
                url: 'https://example.com/a',
                statusCode: Response::HTTP_OK,
                issues: [new Issue(IssueType::PageMissingFromSitemap, 'missing')],
            ),
        ]);

        $auditor = new SiteAuditor(
            $this->createMock(TargetProbeInterface::class),
            sitemapAuditor: $sitemapAuditor,
        );

        $audited = $auditor->audit([$this->page('https://example.com/a')], new CrawlContext());

        $this->assertCount(2, $audited);
        $this->assertSame([IssueType::PageMissingFromSitemap], self::types($audited[0]->issues));
        $this->assertSame('https://example.com/sitemap.xml', $audited[1]->url);
        $this->assertSame([IssueType::SitemapUrlNotOk], self::types($audited[1]->issues));
    }

    public function testMergesUrlVariantIssuesIntoTheCrawledPageTheyConcern(): void
    {
        $variantAuditor = $this->createMock(UrlVariantAuditorInterface::class);
        $variantAuditor->method('audit')->willReturn([
            new PageAudit(
                url: 'https://example.com/a/',
                statusCode: Response::HTTP_OK,
                issues: [new Issue(IssueType::TrailingSlashDuplicate, 'slash')],
            ),
            new PageAudit(
                url: 'http://example.com/',
                statusCode: Response::HTTP_OK,
                issues: [new Issue(IssueType::HttpNotRedirectedToHttps, 'http')],
            ),
            new PageAudit(
                url: 'https://example.com/index.php',
                statusCode: Response::HTTP_OK,
                issues: [new Issue(IssueType::IndexFileDuplicate, 'index')],
            ),
        ]);

        $auditor = new SiteAuditor(
            $this->createMock(TargetProbeInterface::class),
            disabledChecks: [IssueType::IndexFileDuplicate->value],
            urlVariantAuditor: $variantAuditor,
        );

        $audited = $auditor->audit(
            [$this->page('https://example.com/'), $this->page('https://example.com/a/')],
            new CrawlContext(),
        );

        $this->assertCount(3, $audited);
        $this->assertSame([], $audited[0]->issues);
        $this->assertSame([IssueType::TrailingSlashDuplicate], self::types($audited[1]->issues));
        $this->assertSame('http://example.com/', $audited[2]->url);
        $this->assertSame([IssueType::HttpNotRedirectedToHttps], self::types($audited[2]->issues));
    }

    /**
     * @param array<string, RobotsTxt> $robotsTxtByHost
     */
    private function auditorWithRobotsTxt(array $robotsTxtByHost, ?TargetProbeInterface $probe = null): SiteAuditor
    {
        $provider = $this->createMock(RobotsTxtProviderInterface::class);
        $provider->method('robotsTxt')->willReturnCallback(
            static fn(string $url): ?RobotsTxt => $robotsTxtByHost[(string)parse_url($url, PHP_URL_HOST)] ?? null,
        );

        return new SiteAuditor(
            $probe ?? $this->createMock(TargetProbeInterface::class),
            robotsTxtProvider: $provider,
        );
    }

    private static function robotsTxt(string $host, string $content): RobotsTxt
    {
        return RobotsTxt::parse("https://$host/robots.txt", $content, Response::HTTP_OK);
    }

    private static function okResponses(string ...$urls): CrawlContext
    {
        $responses = [];

        foreach ($urls as $url) {
            $responses[$url] = new PageResponse($url, Response::HTTP_OK);
        }

        return new CrawlContext(responses: $responses);
    }

    private function auditor(): SiteAuditor
    {
        return new SiteAuditor($this->createMock(TargetProbeInterface::class));
    }

    /**
     * @param list<Issue> $issues
     * @param list<string> $metaRobots
     * @param array<string, string> $hreflang hreflang value => href
     */
    private function page(
        string $url,
        ?string $canonical = null,
        array $issues = [],
        array $metaRobots = [],
        array $hreflang = [],
    ): PageAudit {
        return new PageAudit(
            url: $url,
            statusCode: Response::HTTP_OK,
            issues: $issues,
            signals: new PageSignals(
                canonicalHrefs: $canonical !== null ? [$canonical] : [],
                metaRobots: $metaRobots,
                hreflangLinks: array_map(
                    static fn(string $value, string $href): HreflangLink => new HreflangLink($value, $href),
                    array_keys($hreflang),
                    array_values($hreflang),
                ),
            ),
        );
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
