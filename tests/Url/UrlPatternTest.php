<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Url;

use Lbonnet\SeoBundle\Url\UrlPattern;
use PHPUnit\Framework\TestCase;

final class UrlPatternTest extends TestCase
{
    public function testTellsValidPatternsApartFromTypos(): void
    {
        $this->assertTrue(UrlPattern::isValid('#/admin#'));
        $this->assertTrue(UrlPattern::isValid('/\.pdf$/i'));

        $this->assertFalse(UrlPattern::isValid('/admin'));
        $this->assertFalse(UrlPattern::isValid('#/(admin#'));
        $this->assertFalse(UrlPattern::isValid(''));
    }

    public function testListsOnlyTheInvalidPatterns(): void
    {
        $this->assertSame(['/admin'], UrlPattern::invalidOnes(['#/admin#', '/admin', '#\.pdf$#']));
        $this->assertSame([], UrlPattern::invalidOnes([]));
    }

    public function testMatchesAnyOfThePatterns(): void
    {
        $this->assertTrue(UrlPattern::matchesAny('https://example.com/admin/users', ['#/blog#', '#/admin#']));
        $this->assertFalse(UrlPattern::matchesAny('https://example.com/blog', ['#/admin#']));
        $this->assertFalse(UrlPattern::matchesAny('https://example.com/', []));
    }

    public function testAnInvalidPatternMatchesNothingAndStaysQuiet(): void
    {
        $this->assertFalse(UrlPattern::matchesAny('https://example.com/admin', ['/admin']));
    }
}
