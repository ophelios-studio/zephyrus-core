<?php

declare(strict_types=1);

namespace Tests\Unit\Localization;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Localization\LocaleTag;

final class LocaleTagTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function tags(): array
    {
        return [
            'language only'            => ['en', true],
            'three letter language'    => ['fil', true],
            'region with hyphen'       => ['fr-CA', true],
            'region with underscore'   => ['fr_CA', true],
            'script and region'        => ['zh-Hant-TW', true],
            'eight character subtag'   => ['en-abcdefgh', true],
            'nine character subtag'    => ['en-abcdefghi', false],
            'one letter language'      => ['e', false],
            'four letter language'     => ['engl', false],
            'one character subtag'     => ['en-a', false],
            'path traversal'           => ['../../etc', false],
            'file extension'           => ['en.json', false],
            'trailing newline'         => ["fr\n", false],
            'nul byte'                 => ["fr\0", false],
            'empty'                    => ['', false],
            'wildcard'                 => ['*', false],
        ];
    }

    #[DataProvider('tags')]
    public function testATagIsWellFormedOnlyWhenItMatchesTheCatalogShape(string $tag, bool $expected): void
    {
        self::assertSame($expected, LocaleTag::isWellFormed($tag));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function canonicalTags(): array
    {
        return [
            'underscores and lowercase'  => ['zh_hant_tw', 'zh-Hant-TW'],
            'uppercase'                  => ['ZH-HANT-TW', 'zh-Hant-TW'],
            'lowercase region'           => ['fr-ca', 'fr-CA'],
            'numeric region'             => ['es-419', 'es-419'],
            'script only'                => ['sr-latn', 'sr-Latn'],
            'variant'                    => ['de-ch-1996', 'de-CH-1996'],
            'empty subtag'               => ['en--US', 'en-US'],
            'empty'                      => ['', ''],
        ];
    }

    #[DataProvider('canonicalTags')]
    public function testATagIsCasedAsBcp47Does(string $tag, string $expected): void
    {
        self::assertSame($expected, LocaleTag::canonical($tag));
    }
}
