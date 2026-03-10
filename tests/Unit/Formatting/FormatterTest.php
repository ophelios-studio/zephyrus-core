<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Formatting;

use DateTime;
use PHPUnit\Framework\TestCase;
use Zephyrus\Formatting\Formatter;
use Zephyrus\Formatting\FormatterException;

final class FormatterTest extends TestCase
{
    private Formatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new Formatter('en_US');
    }

    // ─── Locale ───────────────────────────────────────────────────────

    public function testGetLocaleReturnsConfiguredLocale(): void
    {
        self::assertSame('en_US', $this->formatter->getLocale());

        $fr = new Formatter('fr_FR');
        self::assertSame('fr_FR', $fr->getLocale());
    }

    // ─── Default Currency ────────────────────────────────────────────

    public function testGetDefaultCurrencyReturnsNullByDefault(): void
    {
        self::assertNull($this->formatter->getDefaultCurrency());
    }

    public function testGetDefaultCurrencyReturnsConfiguredCurrency(): void
    {
        $formatter = new Formatter('en', 'CAD');
        self::assertSame('CAD', $formatter->getDefaultCurrency());
    }

    // ─── Money ────────────────────────────────────────────────────────

    public function testMoneyFormatsWithCurrencySymbol(): void
    {
        $result = $this->formatter->money(19.99, 'USD');
        self::assertStringContainsString('19.99', $result);
        self::assertStringContainsString('$', $result);
    }

    public function testMoneyFormatsWithExplicitCurrency(): void
    {
        $result = $this->formatter->money(1234.56, 'EUR');
        self::assertStringContainsString('1,234.56', $result);
    }

    public function testMoneyFormatsNegativeAmount(): void
    {
        $result = $this->formatter->money(-50.00, 'USD');
        self::assertStringContainsString('50.00', $result);
    }

    public function testMoneyFormatsZero(): void
    {
        $result = $this->formatter->money(0.00, 'USD');
        self::assertStringContainsString('0.00', $result);
    }

    public function testMoneyUsesDefaultCurrencyWhenNoExplicitCurrency(): void
    {
        $formatter = new Formatter('en', 'CAD');
        $result = $formatter->money(19.99);
        self::assertStringContainsString('19.99', $result);
        self::assertStringContainsString('CA$', $result);
    }

    public function testMoneyExplicitCurrencyOverridesDefault(): void
    {
        $formatter = new Formatter('en', 'CAD');
        $result = $formatter->money(19.99, 'EUR');
        self::assertStringContainsString('19.99', $result);
        // Should use EUR, not CAD.
        self::assertStringNotContainsString('CA$', $result);
    }

    public function testMoneyFallsBackToLocaleCurrencyWhenNoDefault(): void
    {
        // en_US locale has USD as its native currency.
        $formatter = new Formatter('en_US');
        $result = $formatter->money(19.99);
        self::assertStringContainsString('$', $result);
        self::assertStringContainsString('19.99', $result);
    }

    // ─── Decimal ──────────────────────────────────────────────────────

    public function testDecimalFormatsWithGrouping(): void
    {
        self::assertSame('1,234.50', $this->formatter->decimal(1234.5));
    }

    public function testDecimalFormatsWithCustomPrecision(): void
    {
        self::assertSame('3.142', $this->formatter->decimal(3.14159, 3));
    }

    public function testDecimalFormatsZeroPrecision(): void
    {
        // ICU uses banker's rounding (round half to even): 1234.5 => 1,234.
        self::assertSame('1,234', $this->formatter->decimal(1234.5, 0));
        // 1234.6 rounds up normally.
        self::assertSame('1,235', $this->formatter->decimal(1234.6, 0));
    }

    public function testDecimalFormatsNegative(): void
    {
        $result = $this->formatter->decimal(-1234.56);
        self::assertStringContainsString('1,234.56', $result);
    }

    // ─── Percent ──────────────────────────────────────────────────────

    public function testPercentFormatsAsPercentage(): void
    {
        $result = $this->formatter->percent(0.85);
        self::assertStringContainsString('85', $result);
        self::assertStringContainsString('%', $result);
    }

    public function testPercentFormatsWithPrecision(): void
    {
        $result = $this->formatter->percent(0.8567, 2);
        self::assertStringContainsString('85.67', $result);
    }

    public function testPercentFormatsZero(): void
    {
        $result = $this->formatter->percent(0.0);
        self::assertStringContainsString('0', $result);
        self::assertStringContainsString('%', $result);
    }

    // ─── Ordinal ──────────────────────────────────────────────────────

    public function testOrdinalFormatsCorrectly(): void
    {
        self::assertSame('1st', $this->formatter->ordinal(1));
        self::assertSame('2nd', $this->formatter->ordinal(2));
        self::assertSame('3rd', $this->formatter->ordinal(3));
        self::assertSame('4th', $this->formatter->ordinal(4));
        self::assertSame('11th', $this->formatter->ordinal(11));
        self::assertSame('21st', $this->formatter->ordinal(21));
        self::assertSame('42nd', $this->formatter->ordinal(42));
        self::assertSame('100th', $this->formatter->ordinal(100));
    }

    // ─── SpellOut ─────────────────────────────────────────────────────

    public function testSpellOutFormatsNumber(): void
    {
        self::assertSame('forty-two', $this->formatter->spellOut(42));
        self::assertSame('one hundred', $this->formatter->spellOut(100));
        self::assertSame('zero', $this->formatter->spellOut(0));
    }

    public function testSpellOutFormatsFloat(): void
    {
        $result = $this->formatter->spellOut(3.14);
        self::assertStringContainsString('three', $result);
    }

    // ─── Date/Time ────────────────────────────────────────────────────

    public function testDateFormatsDateTimeObject(): void
    {
        $date = new DateTime('2026-03-10');
        $result = $this->formatter->date($date);

        self::assertStringContainsString('Mar', $result);
        self::assertStringContainsString('10', $result);
        self::assertStringContainsString('2026', $result);
    }

    public function testDateFormatsTimestamp(): void
    {
        $timestamp = mktime(0, 0, 0, 3, 10, 2026);
        $result = $this->formatter->date($timestamp);

        self::assertStringContainsString('Mar', $result);
        self::assertStringContainsString('10', $result);
    }

    public function testDateFormatsString(): void
    {
        $result = $this->formatter->date('2026-03-10');

        self::assertStringContainsString('Mar', $result);
        self::assertStringContainsString('10', $result);
    }

    public function testDateWithShortPattern(): void
    {
        $date = new DateTime('2026-03-10');
        $result = $this->formatter->date($date, 'short');

        // Short format: 3/10/26 or similar.
        self::assertStringContainsString('3', $result);
        self::assertStringContainsString('10', $result);
    }

    public function testDateWithCustomPattern(): void
    {
        $date = new DateTime('2026-03-10');
        $result = $this->formatter->date($date, 'yyyy-MM-dd');

        self::assertSame('2026-03-10', $result);
    }

    public function testTimeFormatsCorrectly(): void
    {
        $time = new DateTime('2026-03-10 14:30:00');
        $result = $this->formatter->time($time);

        // Short time: "2:30 PM" or similar.
        self::assertStringContainsString('2', $result);
        self::assertStringContainsString('30', $result);
    }

    public function testDatetimeFormatsCorrectly(): void
    {
        $dt = new DateTime('2026-03-10 14:30:00');
        $result = $this->formatter->datetime($dt);

        self::assertStringContainsString('Mar', $result);
        self::assertStringContainsString('10', $result);
        self::assertStringContainsString('2', $result);
    }

    public function testDateThrowsForInvalidString(): void
    {
        $this->expectException(FormatterException::class);
        $this->expectExceptionMessage('Unable to parse date string');
        $this->formatter->date('not-a-date-at-all-xyz');
    }

    public function testDateThrowsForUnsupportedType(): void
    {
        $this->expectException(FormatterException::class);
        $this->expectExceptionMessage('Unsupported date type');
        $this->formatter->date([]);
    }

    // ─── Relative Time ────────────────────────────────────────────────

    public function testRelativeTimeSecondsAgo(): void
    {
        $result = $this->formatter->timeago(time() - 30);
        self::assertStringContainsString('second', $result);
        self::assertStringContainsString('ago', $result);
    }

    public function testRelativeTimeMinutesAgo(): void
    {
        $result = $this->formatter->timeago(time() - 300);
        self::assertStringContainsString('minute', $result);
        self::assertStringContainsString('ago', $result);
    }

    public function testRelativeTimeHoursAgo(): void
    {
        $result = $this->formatter->timeago(time() - 7200);
        self::assertStringContainsString('hour', $result);
        self::assertStringContainsString('ago', $result);
    }

    public function testRelativeTimeDaysAgo(): void
    {
        $result = $this->formatter->timeago(time() - 172800);
        self::assertStringContainsString('day', $result);
        self::assertStringContainsString('ago', $result);
    }

    public function testRelativeTimeFuture(): void
    {
        $result = $this->formatter->timeago(time() + 7200);
        self::assertStringContainsString('in', $result);
        self::assertStringContainsString('hour', $result);
    }

    public function testRelativeTimeJustNow(): void
    {
        $result = $this->formatter->timeago(time());
        self::assertSame('just now', $result);
    }

    public function testRelativeTimeAcceptsDateTime(): void
    {
        $past = new DateTime('-2 hours');
        $result = $this->formatter->timeago($past);
        self::assertStringContainsString('hour', $result);
    }

    public function testRelativeTimeMonthsAgo(): void
    {
        $result = $this->formatter->timeago(time() - (60 * 86400));
        self::assertStringContainsString('month', $result);
        self::assertStringContainsString('ago', $result);
    }

    public function testRelativeTimeYearsAgo(): void
    {
        $result = $this->formatter->timeago(time() - (400 * 86400));
        self::assertStringContainsString('year', $result);
        self::assertStringContainsString('ago', $result);
    }

    // ─── Duration ─────────────────────────────────────────────────────

    public function testDurationFormatsHoursMinutesSeconds(): void
    {
        self::assertSame('2h 10m 30s', $this->formatter->duration(7830));
    }

    public function testDurationFormatsMinutesSeconds(): void
    {
        self::assertSame('5m 30s', $this->formatter->duration(330));
    }

    public function testDurationFormatsSecondsOnly(): void
    {
        self::assertSame('45s', $this->formatter->duration(45));
    }

    public function testDurationFormatsZero(): void
    {
        self::assertSame('0s', $this->formatter->duration(0));
    }

    public function testDurationFormatsWithZeroMinutes(): void
    {
        self::assertSame('1h 0m 5s', $this->formatter->duration(3605));
    }

    public function testDurationFormatsNegative(): void
    {
        self::assertSame('-1h 0m 0s', $this->formatter->duration(-3600));
    }

    // ─── Filesize ─────────────────────────────────────────────────────

    public function testFilesizeFormatsBytes(): void
    {
        self::assertSame('500 B', $this->formatter->filesize(500));
    }

    public function testFilesizeFormatsKilobytes(): void
    {
        self::assertSame('1.5 KB', $this->formatter->filesize(1536));
    }

    public function testFilesizeFormatsMegabytes(): void
    {
        self::assertSame('1.5 MB', $this->formatter->filesize(1_572_864));
    }

    public function testFilesizeFormatsGigabytes(): void
    {
        self::assertSame('2.0 GB', $this->formatter->filesize(2_147_483_648));
    }

    public function testFilesizeWithCustomPrecision(): void
    {
        self::assertSame('1.46 MB', $this->formatter->filesize(1_536_000, 2));
    }

    public function testFilesizeFormatsZero(): void
    {
        self::assertSame('0 B', $this->formatter->filesize(0));
    }

    // ─── List ─────────────────────────────────────────────────────────

    public function testListConjunctionThreeItems(): void
    {
        self::assertSame('a, b, and c', $this->formatter->list(['a', 'b', 'c']));
    }

    public function testListDisjunctionThreeItems(): void
    {
        self::assertSame('a, b, or c', $this->formatter->list(['a', 'b', 'c'], 'disjunction'));
    }

    public function testListTwoItems(): void
    {
        self::assertSame('a and b', $this->formatter->list(['a', 'b']));
    }

    public function testListTwoItemsDisjunction(): void
    {
        self::assertSame('a or b', $this->formatter->list(['a', 'b'], 'disjunction'));
    }

    public function testListSingleItem(): void
    {
        self::assertSame('only', $this->formatter->list(['only']));
    }

    public function testListEmpty(): void
    {
        self::assertSame('', $this->formatter->list([]));
    }

    public function testListManyItems(): void
    {
        self::assertSame('a, b, c, d, and e', $this->formatter->list(['a', 'b', 'c', 'd', 'e']));
    }

    // ─── Truncate ─────────────────────────────────────────────────────

    public function testTruncateShortString(): void
    {
        self::assertSame('Hello', $this->formatter->truncate('Hello', 10));
    }

    public function testTruncateLongString(): void
    {
        self::assertSame('Hello...', $this->formatter->truncate('Hello, World!', 8));
    }

    public function testTruncateWithCustomSuffix(): void
    {
        self::assertSame('Hello--', $this->formatter->truncate('Hello, World!', 7, '--'));
    }

    public function testTruncateExactLength(): void
    {
        self::assertSame('Hello', $this->formatter->truncate('Hello', 5));
    }

    public function testTruncateUnicode(): void
    {
        $result = $this->formatter->truncate('こんにちは世界', 5, '…');
        self::assertSame(5, mb_strlen($result));
        self::assertStringEndsWith('…', $result);
    }

    // ─── Custom Formatters ────────────────────────────────────────────

    public function testRegisterAndUseCustomFormatter(): void
    {
        $this->formatter->register('phone', function (string $number): string {
            return preg_replace('/(\d{3})(\d{3})(\d{4})/', '($1) $2-$3', $number);
        });

        self::assertSame('(555) 123-4567', $this->formatter->format('phone', '5551234567'));
    }

    public function testFormatThrowsForUnknownFormatter(): void
    {
        $this->expectException(FormatterException::class);
        $this->expectExceptionMessage('Unknown custom formatter');
        $this->formatter->format('nonexistent', 'value');
    }

    // ─── French Locale ────────────────────────────────────────────────

    // ─── Default Date/Time Patterns ─────────────────────────────────

    public function testDefaultDatePatternIsUsedWhenNoPatternGiven(): void
    {
        $formatter = new Formatter('en_US', defaultDatePattern: 'yyyy-MM-dd');
        $result = $formatter->date(new DateTime('2026-03-10'));
        self::assertSame('2026-03-10', $result);
    }

    public function testDefaultTimePatternIsUsedWhenNoPatternGiven(): void
    {
        $formatter = new Formatter('en_US', defaultTimePattern: 'HH:mm');
        $result = $formatter->time(new DateTime('2026-03-10 14:30:00'));
        self::assertSame('14:30', $result);
    }

    public function testDefaultDatetimePatternIsUsedWhenNoPatternGiven(): void
    {
        $formatter = new Formatter('en_US', defaultDatetimePattern: 'yyyy-MM-dd HH:mm');
        $result = $formatter->datetime(new DateTime('2026-03-10 14:30:00'));
        self::assertSame('2026-03-10 14:30', $result);
    }

    public function testExplicitPatternOverridesDefaultDatePattern(): void
    {
        $formatter = new Formatter('en_US', defaultDatePattern: 'yyyy-MM-dd');
        $result = $formatter->date(new DateTime('2026-03-10'), 'full');
        self::assertStringContainsString('Tuesday', $result);
        self::assertStringContainsString('March', $result);
    }

    public function testExplicitPatternOverridesDefaultTimePattern(): void
    {
        $formatter = new Formatter('en_US', defaultTimePattern: 'HH:mm');
        $result = $formatter->time(new DateTime('2026-03-10 14:30:00'), 'medium');
        // Medium time includes seconds.
        self::assertStringContainsString('30', $result);
    }

    public function testGetDefaultDatePatternReturnsConfiguredValue(): void
    {
        $formatter = new Formatter('en_US', defaultDatePattern: 'dd/MM/yyyy');
        self::assertSame('dd/MM/yyyy', $formatter->getDefaultDatePattern());
    }

    public function testGetDefaultTimePatternReturnsConfiguredValue(): void
    {
        $formatter = new Formatter('en_US', defaultTimePattern: 'HH:mm:ss');
        self::assertSame('HH:mm:ss', $formatter->getDefaultTimePattern());
    }

    public function testGetDefaultDatetimePatternReturnsConfiguredValue(): void
    {
        $formatter = new Formatter('en_US', defaultDatetimePattern: 'long');
        self::assertSame('long', $formatter->getDefaultDatetimePattern());
    }

    public function testDefaultPatternsHaveSensibleDefaults(): void
    {
        $formatter = new Formatter('en_US');
        self::assertSame('medium', $formatter->getDefaultDatePattern());
        self::assertSame('short', $formatter->getDefaultTimePattern());
        self::assertSame('medium', $formatter->getDefaultDatetimePattern());
    }

    // ─── French Locale ────────────────────────────────────────────────

    public function testFrenchLocaleDecimal(): void
    {
        $fr = new Formatter('fr_FR');
        $result = $fr->decimal(1234.56);

        // French uses space as grouping separator and comma as decimal.
        // Accept non-breaking space (U+00A0 or U+202F) or regular space.
        $normalized = preg_replace('/[\x{00A0}\x{202F}]/u', ' ', $result);
        self::assertSame('1 234,56', $normalized);
    }

    public function testFrenchLocaleOrdinal(): void
    {
        $fr = new Formatter('fr_FR');
        $result = $fr->ordinal(1);
        // French ordinal: "1ᵉʳ" or "1er" depending on ICU version.
        self::assertStringStartsWith('1', $result);
    }
}
