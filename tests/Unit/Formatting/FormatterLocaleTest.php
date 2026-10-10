<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Formatting;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Formatting\Formatter;
use Zephyrus\Formatting\FormatterException;

final class FormatterLocaleTest extends TestCase
{
    private const NBSP = "\u{a0}";

    /**
     * @return list<array{string}>
     */
    public static function frenchLocales(): array
    {
        return [['fr'], ['fr_CA']];
    }

    /**
     * @return list<array{string}>
     */
    public static function englishFallbackLocales(): array
    {
        return [['en_US'], ['de_DE']];
    }

    #[DataProvider('frenchLocales')]
    public function testTimeagoIsWrittenInFrench(string $locale): void
    {
        $fmt = new Formatter($locale);

        self::assertSame('il y a 3 minutes', $fmt->timeago(time() - 180));
        self::assertSame('il y a 1 minute', $fmt->timeago(time() - 60));
        self::assertSame('il y a 30 secondes', $fmt->timeago(time() - 30));
        self::assertSame('il y a 2 heures', $fmt->timeago(time() - 7200));
        self::assertSame('il y a 1 jour', $fmt->timeago(time() - 86400));
        self::assertSame('il y a 3 jours', $fmt->timeago(time() - 3 * 86400));
        self::assertSame('il y a 2 mois', $fmt->timeago(time() - 60 * 86400));
        self::assertSame('il y a 1 an', $fmt->timeago(time() - 400 * 86400));
        self::assertSame('il y a 2 ans', $fmt->timeago(time() - 800 * 86400));
        self::assertSame('dans 1 minute', $fmt->timeago(time() + 60));
        self::assertSame('dans 3 jours', $fmt->timeago(time() + 3 * 86400));
        self::assertSame("à l'instant", $fmt->timeago(time()));
    }

    #[DataProvider('englishFallbackLocales')]
    public function testTimeagoIsWrittenInEnglishOutsideFrench(string $locale): void
    {
        $fmt = new Formatter($locale);

        self::assertSame('3 minutes ago', $fmt->timeago(time() - 180));
        self::assertSame('1 minute ago', $fmt->timeago(time() - 60));
        self::assertSame('in 3 days', $fmt->timeago(time() + 3 * 86400));
    }

    #[DataProvider('frenchLocales')]
    public function testDurationIsWrittenInFrench(string $locale): void
    {
        $fmt = new Formatter($locale);
        $nbsp = self::NBSP;

        self::assertSame("2{$nbsp}h 10{$nbsp}min 30{$nbsp}s", $fmt->duration(7830));
        self::assertSame("5{$nbsp}min 30{$nbsp}s", $fmt->duration(330));
        self::assertSame("45{$nbsp}s", $fmt->duration(45));
        self::assertSame("0{$nbsp}s", $fmt->duration(0));
        self::assertSame("1{$nbsp}h 0{$nbsp}min 5{$nbsp}s", $fmt->duration(3605));
        self::assertSame("-1{$nbsp}h 0{$nbsp}min 0{$nbsp}s", $fmt->duration(-3600));
    }

    #[DataProvider('englishFallbackLocales')]
    public function testDurationIsWrittenInEnglishOutsideFrench(string $locale): void
    {
        $fmt = new Formatter($locale);

        self::assertSame('2h 10m 30s', $fmt->duration(7830));
        self::assertSame('45s', $fmt->duration(45));
    }

    #[DataProvider('frenchLocales')]
    public function testFilesizeIsWrittenInFrenchWithTheLocaleDecimalMark(string $locale): void
    {
        $fmt = new Formatter($locale);
        $nbsp = self::NBSP;

        self::assertSame("500{$nbsp}o", $fmt->filesize(500));
        self::assertSame("0{$nbsp}o", $fmt->filesize(0));
        self::assertSame("1,5{$nbsp}ko", $fmt->filesize(1536));
        self::assertSame("1,5{$nbsp}Mo", $fmt->filesize(1_572_864));
        self::assertSame("2,0{$nbsp}Go", $fmt->filesize(2_147_483_648));
        self::assertSame("1,46{$nbsp}Mo", $fmt->filesize(1_536_000, 2));
    }

    #[DataProvider('englishFallbackLocales')]
    public function testFilesizeKeepsDotDecimalAndEnglishUnitsOutsideFrench(string $locale): void
    {
        $fmt = new Formatter($locale);

        self::assertSame('500 B', $fmt->filesize(500));
        self::assertSame('1.5 KB', $fmt->filesize(1536));
        self::assertSame('1.5 MB', $fmt->filesize(1_572_864));
    }

    #[DataProvider('frenchLocales')]
    public function testFilesizeNeverGroupsThousandsInAnyLocale(string $locale): void
    {
        $nbsp = self::NBSP;

        self::assertSame("1023,9{$nbsp}ko", (new Formatter($locale))->filesize(1_048_474));
        self::assertSame('1023.9 KB', (new Formatter('en_US'))->filesize(1_048_474));
    }

    #[DataProvider('frenchLocales')]
    public function testListIsJoinedInFrenchWithoutASerialComma(string $locale): void
    {
        $fmt = new Formatter($locale);

        self::assertSame('a et b', $fmt->list(['a', 'b']));
        self::assertSame('a, b et c', $fmt->list(['a', 'b', 'c']));
        self::assertSame('a ou b', $fmt->list(['a', 'b'], 'disjunction'));
        self::assertSame('a, b ou c', $fmt->list(['a', 'b', 'c'], 'disjunction'));
        self::assertSame('only', $fmt->list(['only']));
    }

    #[DataProvider('englishFallbackLocales')]
    public function testListIsJoinedInEnglishOutsideFrench(string $locale): void
    {
        $fmt = new Formatter($locale);

        self::assertSame('a and b', $fmt->list(['a', 'b']));
        self::assertSame('a, b, and c', $fmt->list(['a', 'b', 'c']));
        self::assertSame('a, b, or c', $fmt->list(['a', 'b', 'c'], 'disjunction'));
    }

    #[DataProvider('regionlessLocales')]
    public function testMoneyWithoutCurrencyThrowsForALocaleWithoutANativeCurrency(string $locale): void
    {
        $this->expectException(FormatterException::class);
        $this->expectExceptionMessageMatches('/Pass a currency to money\(\) or set a default currency/');

        (new Formatter($locale))->money(19.99);
    }

    #[DataProvider('regionlessLocales')]
    public function testMoneyWithEmptyCurrencyThrowsForALocaleWithoutANativeCurrency(string $locale): void
    {
        $this->expectException(FormatterException::class);

        (new Formatter($locale))->money(19.99, '');
    }

    #[DataProvider('regionlessLocales')]
    public function testMoneyUsesAnExplicitOrDefaultCurrencyForALocaleWithoutANativeCurrency(string $locale): void
    {
        self::assertStringContainsString('€', (new Formatter($locale))->money(19.99, 'EUR'));
        self::assertStringContainsString('€', (new Formatter($locale, 'EUR'))->money(19.99));
    }

    /**
     * @return list<array{string}>
     */
    public static function regionlessLocales(): array
    {
        return [['fr'], ['en']];
    }
}
