<?php

declare(strict_types=1);

namespace Lbonnet\SeoBundle\Tests\Module\Technical\Hreflang;

use Lbonnet\SeoBundle\Module\Technical\Hreflang\HreflangValidator;
use PHPUnit\Framework\TestCase;

final class HreflangValidatorTest extends TestCase
{
    /**
     * @dataProvider validValueProvider
     */
    public function testAcceptsValidValues(string $value): void
    {
        $this->assertNull(HreflangValidator::explain($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validValueProvider(): iterable
    {
        yield 'language' => ['fr'];
        yield 'language and region' => ['fr-FR'];
        yield 'case-insensitive' => ['FR-fr'];
        yield 'language and script' => ['zh-Hant'];
        yield 'language, script and region' => ['zh-Hans-US'];
        yield 'x-default' => ['x-default'];
        yield 'x-default in another case' => ['X-Default'];
        yield 'Ukrainian, not the United Kingdom' => ['uk'];
        yield 'Belarusian, not Belgium' => ['be'];
    }

    /**
     * @dataProvider invalidValueProvider
     */
    public function testExplainsInvalidValues(string $value, string $expectedReason): void
    {
        $reason = HreflangValidator::explain($value);

        $this->assertNotNull($reason);
        $this->assertStringContainsString($expectedReason, $reason);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidValueProvider(): iterable
    {
        yield 'UK instead of GB' => ['en-UK', '"GB"'];
        yield 'numeric UN M.49 region' => ['es-419', '"419"'];
        yield 'reserved region' => ['en-EU', 'officially assigned'];
        yield 'underscore separator' => ['fr_FR', 'hyphen'];
        yield 'region alone' => ['us', 'region cannot be used on its own'];
        yield 'deprecated language code' => ['iw', 'ISO 639-1'];
        yield 'code withdrawn from ISO 639-1' => ['bh', 'ISO 639-1'];
        yield 'unknown script' => ['zh-Qwer', 'ISO 15924'];
        yield 'too many subtags' => ['fr-FR-Paris', 'more subtags'];
        yield 'empty' => ['', 'empty'];
    }
}
