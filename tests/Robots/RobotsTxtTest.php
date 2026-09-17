<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Robots;

use Lbonnet\SeoBundle\Robots\RobotsTxt;
use Lbonnet\SeoBundle\Robots\RobotsTxtStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class RobotsTxtTest extends TestCase
{
    public function testTheMostSpecificGroupAppliesWithoutTheWildcardGroup(): void
    {
        $robotsTxt = self::found("User-agent: *\nDisallow: /private\n\nUser-agent: Googlebot\nDisallow: /drafts\n");

        $this->assertTrue($robotsTxt->isAllowed('https://example.com/private', 'Googlebot'));
        $this->assertFalse($robotsTxt->isAllowed('https://example.com/drafts', 'Googlebot'));
        $this->assertFalse($robotsTxt->isAllowed('https://example.com/private', 'OtherBot'));
    }

    public function testAHyphenatedGroupIsMoreSpecificThanItsBaseToken(): void
    {
        $robotsTxt = self::found(
            "User-agent: googlebot-news\nDisallow: /news\n\nUser-agent: googlebot\nDisallow: /web\n",
        );

        $this->assertFalse($robotsTxt->isAllowed('https://example.com/news', 'Googlebot-News'));
        $this->assertTrue($robotsTxt->isAllowed('https://example.com/web', 'Googlebot-News'));
        $this->assertTrue($robotsTxt->isAllowed('https://example.com/news', 'Googlebot'));
        // No "googlebot-image" group: Googlebot-Image follows the "googlebot" one.
        $this->assertFalse($robotsTxt->isAllowed('https://example.com/web', 'Googlebot-Image'));
    }

    public function testGroupsNamingTheSameProductTokenAreMerged(): void
    {
        $robotsTxt = self::found("User-agent: Googlebot/2.1\nDisallow: /a\n\nUser-agent: googlebot*\nDisallow: /b\n");

        $this->assertFalse($robotsTxt->isAllowed('https://example.com/a', 'Googlebot'));
        $this->assertFalse($robotsTxt->isAllowed('https://example.com/b', 'Googlebot'));
    }

    public function testAProductTokenMustMatchAsAWholeWord(): void
    {
        $robotsTxt = self::found("User-agent: google\nDisallow: /\n");

        $this->assertTrue($robotsTxt->isAllowed('https://example.com/', 'Googlebot'));
    }

    public function testBetweenRulesOfTheSameLengthTheLeastRestrictiveWins(): void
    {
        foreach (["Disallow: /folder\nAllow: /folder\n", "Allow: /folder\nDisallow: /folder\n"] as $rules) {
            $robotsTxt = self::found("User-agent: *\n".$rules);

            $this->assertTrue($robotsTxt->isAllowed('https://example.com/folder/page', 'Googlebot'), $rules);
        }
    }

    public function testALongerRuleStillBeatsAShorterAllow(): void
    {
        $robotsTxt = self::found("User-agent: *\nAllow: /p\nDisallow: /private\n");

        $this->assertFalse($robotsTxt->isAllowed('https://example.com/private', 'Googlebot'));
        $this->assertTrue($robotsTxt->isAllowed('https://example.com/page', 'Googlebot'));
    }

    public function testCollectsTheSitemapsDeclaredAnywhereInTheFile(): void
    {
        $robotsTxt = self::found(
            "Sitemap: https://example.com/sitemap.xml\n"
            ."User-agent: *\nDisallow: /admin\n"
            ."sitemap:https://cdn.example.com/news.xml # news\n"
            ."Disallow: /tmp\n"
            ."Sitemap: https://example.com/sitemap.xml\n"
            ."Sitemap:\n",
        );

        $this->assertSame(
            ['https://example.com/sitemap.xml', 'https://cdn.example.com/news.xml'],
            $robotsTxt->sitemaps(),
        );
        $this->assertFalse($robotsTxt->isAllowed('https://example.com/tmp', 'Googlebot'));
    }

    public function testAMissingOrUnreachableRobotsTxtHoldsNoRules(): void
    {
        $notFound = RobotsTxt::notFound('https://example.com/robots.txt', Response::HTTP_NOT_FOUND);
        $serverError = RobotsTxt::serverError('https://example.com/robots.txt', null);

        $this->assertSame(RobotsTxtStatus::NotFound, $notFound->status);
        $this->assertSame(Response::HTTP_NOT_FOUND, $notFound->statusCode);
        $this->assertSame(RobotsTxtStatus::ServerError, $serverError->status);
        $this->assertNull($serverError->statusCode);
        $this->assertTrue($notFound->isAllowed('https://example.com/anything', 'Googlebot'));
        $this->assertTrue($serverError->isAllowed('https://example.com/anything', 'Googlebot'));
        $this->assertNull($serverError->crawlDelay('Googlebot'));
        $this->assertSame([], $serverError->sitemaps());
    }

    private static function found(string $content): RobotsTxt
    {
        return RobotsTxt::parse('https://example.com/robots.txt', $content, Response::HTTP_OK);
    }
}
