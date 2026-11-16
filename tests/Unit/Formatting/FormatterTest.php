<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Formatting;

use DateTime;
use NumberFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Zephyrus\Formatting\Formatter;
use Zephyrus\Formatting\FormatterException;
use Zephyrus\Formatting\FormatterInput;

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

    public function testMoneyTreatsAnEmptyDefaultCurrencyAsNoDefault(): void
    {
        $formatter = new Formatter('fr_CA', '');

        self::assertSame((new Formatter('fr_CA'))->money(19.99), $formatter->money(19.99));
    }

    public function testMoneyTreatsAnEmptyExplicitCurrencyAsNoCurrency(): void
    {
        $formatter = new Formatter('fr_CA');

        self::assertSame($formatter->money(19.99), $formatter->money(19.99, ''));
    }

    public function testMoneyWithAnEmptyCurrencyUsesTheDefaultCurrencyRatherThanTheLocaleOne(): void
    {
        $formatter = new Formatter('fr_CA', 'EUR');

        $result = $formatter->money(19.99, '');

        self::assertSame($formatter->money(19.99, 'EUR'), $result);
        self::assertStringContainsString('€', $result);
    }

    public function testAnEmptyDefaultCurrencyIsStoredAsNoCurrency(): void
    {
        self::assertNull((new Formatter('fr_CA', ''))->getDefaultCurrency());
    }

    public function testMoneyAcceptsALowercaseCurrencyCode(): void
    {
        self::assertSame($this->formatter->money(19.99, 'CAD'), $this->formatter->money(19.99, 'cad'));
    }

    #[DataProviderExternal(FormatterInputTest::class, 'invalidCurrencyCodes')]
    public function testMoneyRefusesACurrencyThatIsNotThreeAsciiLetters(string $currency): void
    {
        try {
            $this->formatter->money(1234.5, $currency);
            self::fail('Expected a FormatterException.');
        } catch (FormatterException $exception) {
            self::assertStringContainsString('three ASCII letters, for example CAD', $exception->getMessage());
            self::assertStringNotContainsString($currency, $exception->getMessage());
        }
    }

    #[DataProviderExternal(FormatterInputTest::class, 'invalidCurrencyCodes')]
    public function testConstructorRefusesADefaultCurrencyThatIsNotThreeAsciiLetters(string $currency): void
    {
        $this->expectException(FormatterException::class);
        $this->expectExceptionMessage('three ASCII letters, for example CAD');

        new Formatter('en_US', $currency);
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
        self::assertMatchesRegularExpression('/^(just now|1 second ago)$/', $result);
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

    /**
     * @return array<string, array{int, string}>
     */
    public static function timeagoRoundingCases(): array
    {
        return [
            'minutes rounded down' => [620, '10 minutes ago'],
            'minutes rounded up' => [633, '11 minutes ago'],
            'hours rounded down' => [37_790, '10 hours ago'],
            'hours rounded up' => [37_803, '11 hours ago'],
            'days rounded down' => [907_190, '10 days ago'],
            'days rounded up' => [907_203, '11 days ago'],
            'months rounded down' => [16_847_994, '6 months ago'],
            'months rounded up' => [16_848_003, '7 months ago'],
            'years rounded down' => [173_447_995, '5 years ago'],
            'years rounded up' => [173_448_003, '6 years ago'],
            'exactly one hour' => [3600, '1 hour ago'],
            'exactly thirty days' => [2_592_000, '1 month ago'],
            'exactly one year' => [31_536_000, '1 year ago'],
        ];
    }

    #[DataProvider('timeagoRoundingCases')]
    public function testTimeagoRoundsEachUnitToTheNearestWholeNumber(int $secondsAgo, string $expected): void
    {
        self::assertSame($expected, $this->formatter->timeago(time() - $secondsAgo));
    }

    // ─── Duration ─────────────────────────────────────────────────────

    public function testDurationJustUnderAnHourStaysInMinutes(): void
    {
        self::assertSame('59m 59s', $this->formatter->duration(3599));
        self::assertSame('50m 0s', $this->formatter->duration(3000));
    }

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

    public function testFilesizeSwitchesUnitAtExactlyOneKibibyte(): void
    {
        self::assertSame('1023 B', $this->formatter->filesize(1023));
        self::assertSame('1.0 KB', $this->formatter->filesize(1024));
    }

    public function testFilesizeStopsAtTheLargestUnit(): void
    {
        self::assertSame('4096.0 PB', $this->formatter->filesize(2 ** 62));
    }

    public function testFilesizeKeepsTheSignOfANegativeCount(): void
    {
        self::assertSame('-1.5 KB', $this->formatter->filesize(-1536));
        self::assertSame('-500 B', $this->formatter->filesize(-500));
    }

    // ─── Precision bounds ─────────────────────────────────────────────

    /**
     * @return list<array{int}>
     */
    public static function precisionsOutsideZeroToTwenty(): array
    {
        return [[-1], [21], [4_294_967_296]];
    }

    #[DataProvider('precisionsOutsideZeroToTwenty')]
    public function testDecimalRefusesAPrecisionOutsideZeroToTwenty(int $precision): void
    {
        $this->assertPrecisionIsRefused('decimal', $precision, fn (): string => $this->formatter->decimal(1.5, $precision));
    }

    #[DataProvider('precisionsOutsideZeroToTwenty')]
    public function testPercentRefusesAPrecisionOutsideZeroToTwenty(int $precision): void
    {
        $this->assertPrecisionIsRefused('percent', $precision, fn (): string => $this->formatter->percent(0.5, $precision));
    }

    #[DataProvider('precisionsOutsideZeroToTwenty')]
    public function testFilesizeRefusesAPrecisionOutsideZeroToTwenty(int $precision): void
    {
        $this->assertPrecisionIsRefused('filesize', $precision, fn (): string => $this->formatter->filesize(1536, $precision));
    }

    public function testPrecisionOfZeroAndTwentyAreAccepted(): void
    {
        $zeros = str_repeat('0', 20);

        self::assertSame('2', $this->formatter->decimal(2.0, 0));
        self::assertSame("1.{$zeros}", $this->formatter->decimal(1.0, 20));
        self::assertSame("1.{$zeros}%", $this->formatter->percent(0.01, 20));
        self::assertSame("1.{$zeros} KB", $this->formatter->filesize(1024, 20));
    }

    private function assertPrecisionIsRefused(string $method, int $precision, callable $call): void
    {
        try {
            $call();
            self::fail('Expected a FormatterException.');
        } catch (FormatterException $exception) {
            self::assertSame(
                sprintf('%s() precision must be between 0 and 20, got %d.', $method, $precision),
                $exception->getMessage(),
            );
        }
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

    public function testTruncateNeverReturnsMoreThanTheRequestedLength(): void
    {
        $value = '12345678901234567890';

        // A length shorter than the suffix leaves no room for both; a negative
        // mb_substr() length would otherwise trim from the END of the value and
        // hand back a string LONGER than the one passed in.
        self::assertSame('', $this->formatter->truncate($value, 0));
        self::assertSame('..', $this->formatter->truncate($value, 2));
        self::assertSame('...', $this->formatter->truncate($value, 3));
        self::assertSame('12...', $this->formatter->truncate($value, 5));

        foreach (range(0, 25) as $length) {
            self::assertLessThanOrEqual(
                max($length, 0),
                mb_strlen($this->formatter->truncate($value, $length)),
                'truncate() must never exceed the requested length: ' . $length,
            );
        }
    }

    public function testTruncateCountsCharactersNotBytes(): void
    {
        self::assertSame('éé', $this->formatter->truncate('éé', 2));
        self::assertSame('…', $this->formatter->truncate('abcdef', 1, '…'));
        self::assertSame('éé…', $this->formatter->truncate('ééééé', 3, '…'));
    }

    public function testTruncateClampsANegativeLength(): void
    {
        self::assertSame('', $this->formatter->truncate('12345678901234567890', -5));
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
        $this->expectExceptionMessage('Unknown formatter: nonexistent');
        $this->formatter->format('nonexistent', 'value');
    }

    public function testFormatRejectsPublicMethodsThatAreNotFormatters(): void
    {
        $this->expectException(FormatterException::class);
        $this->expectExceptionMessage('money, decimal');
        $this->formatter->format('__construct', 'de_DE');
    }

    public function testFormatRejectsGetterReachedByName(): void
    {
        $this->expectException(FormatterException::class);
        $this->formatter->format('getLocale');
    }

    public function testFormatRejectsEmptyName(): void
    {
        $this->expectException(FormatterException::class);
        $this->formatter->format('');
    }

    public function testFormatRunsBuiltInFormatterByName(): void
    {
        self::assertSame('$19.99', $this->formatter->format('money', 19.99, 'USD'));
    }

    public function testFormatMatchesBuiltInFormatterNameCaseInsensitively(): void
    {
        self::assertSame('$19.99', $this->formatter->format('MONEY', 19.99, 'USD'));
        self::assertSame('forty-two', $this->formatter->format('spellout', 42));
    }

    public function testBuiltInFormatterListIsPublic(): void
    {
        self::assertContains('money', Formatter::BUILT_IN_FORMATTERS);
    }

    public function testCustomBuiltInOverrideRunsForAnyCaseOfItsName(): void
    {
        $this->formatter->register('date', static fn (): string => 'custom date');
        $this->formatter->register('money', static fn (): string => 'custom money');

        self::assertSame('custom date', $this->formatter->format('Date', '2026-10-09'));
        self::assertSame('custom money', $this->formatter->format('MONEY', 5));
        self::assertSame('custom money', $this->formatter->format('money', 5));
    }

    public function testBuiltInOverrideRegisteredInOtherCaseAppliesToCanonicalName(): void
    {
        $this->formatter->register('Money', static fn (): string => 'custom money');
        $this->formatter->register('spellout', static fn (): string => 'custom spellout');

        self::assertSame('custom money', $this->formatter->format('money', 5));
        self::assertSame('custom money', $this->formatter->format('MONEY', 5));
        self::assertSame('custom spellout', $this->formatter->format('spellOut', 5));
        self::assertTrue($this->formatter->hasCustomFormatter('money'));
        self::assertSame(['money', 'spellOut'], $this->formatter->getCustomFormatterNames());
    }

    public function testGetCustomFormatterNamesReturnsStringsForNumericNames(): void
    {
        $this->formatter->register('123', static fn (): string => 'numeric');
        $this->formatter->register('slug', static fn (): string => 'slug');

        self::assertSame(['123', 'slug'], $this->formatter->getCustomFormatterNames());
    }

    public function testFormatKeepsCustomFormatterNameCaseSensitive(): void
    {
        $this->formatter->register('phone', static fn (): string => 'custom');

        $this->expectException(FormatterException::class);
        $this->formatter->format('PHONE');
    }

    public function testHasCustomFormatterReturnsFalseWhenNotRegistered(): void
    {
        self::assertFalse($this->formatter->hasCustomFormatter('phone'));
    }

    public function testHasCustomFormatterReturnsTrueWhenRegistered(): void
    {
        $this->formatter->register('phone', fn (string $n) => $n);
        self::assertTrue($this->formatter->hasCustomFormatter('phone'));
    }

    public function testHasCustomFormatterMatchesBuiltInOverrideIgnoringCase(): void
    {
        $this->formatter->register('money', static fn (): string => 'custom money');

        self::assertTrue($this->formatter->hasCustomFormatter('money'));
        self::assertTrue($this->formatter->hasCustomFormatter('MONEY'));
        self::assertTrue($this->formatter->hasCustomFormatter('Money'));
    }

    public function testHasCustomFormatterIgnoresCaseOfBuiltInWithoutOverride(): void
    {
        self::assertFalse($this->formatter->hasCustomFormatter('MONEY'));
    }

    public function testHasCustomFormatterKeepsCustomNameCaseSensitive(): void
    {
        $this->formatter->register('phone', static fn (): string => 'custom');

        self::assertFalse($this->formatter->hasCustomFormatter('Phone'));
    }

    public function testGetCustomFormatterNamesReturnsEmptyByDefault(): void
    {
        self::assertSame([], $this->formatter->getCustomFormatterNames());
    }

    public function testUnparsableDateErrorDescribesTheInputWithoutEchoingIt(): void
    {
        $input = 'zzz-SECRETX-zzz';

        try {
            $this->formatter->date($input);
            self::fail('An unparsable date string was formatted.');
        } catch (FormatterException $exception) {
            self::assertStringNotContainsString('SECRETX', $exception->getMessage());
            self::assertStringContainsString('15 bytes', $exception->getMessage());
        }
    }

    public function testHasAcceptsEveryBuiltInNameIgnoringCase(): void
    {
        foreach (Formatter::BUILT_IN_FORMATTERS as $builtIn) {
            self::assertTrue($this->formatter->has($builtIn), $builtIn);
            self::assertTrue($this->formatter->has(strtoupper($builtIn)), $builtIn);
        }
    }

    public function testHasAcceptsCustomNamesExactly(): void
    {
        $this->formatter->register('phone', fn (string $n) => $n);

        self::assertTrue($this->formatter->has('phone'));
        self::assertFalse($this->formatter->has('Phone'));
    }

    public function testHasAcceptsACustomOverrideOfABuiltInName(): void
    {
        $this->formatter->register('MONEY', fn () => 'override');

        self::assertTrue($this->formatter->has('money'));
        self::assertTrue($this->formatter->has('Money'));
    }

    public function testHasRejectsUnknownNamesAndNearMisses(): void
    {
        foreach (['', 'phone', ' money', "money\0", 'money ', 'moneys'] as $name) {
            self::assertFalse($this->formatter->has($name), var_export($name, true));
        }
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function dispatchableNames(): iterable
    {
        yield 'custom' => ['phone', true];
        yield 'custom name is case-sensitive' => ['Phone', false];
        yield 'built-in' => ['decimal', true];
        yield 'built-in, other case' => ['DECIMAL', true];
        yield 'unknown' => ['nope', false];
        yield 'empty' => ['', false];
        yield 'accessor is not a formatter' => ['getLocale', false];
    }

    #[DataProvider('dispatchableNames')]
    public function testHasAgreesWithFormatDispatch(string $name, bool $expected): void
    {
        $this->formatter->register('phone', fn () => 'ok');

        self::assertSame($expected, $this->formatter->has($name));

        if (!$expected) {
            $this->expectException(FormatterException::class);
        }

        self::assertIsString($this->formatter->format($name, 1.5));
    }

    public function testGetCustomFormatterNamesReturnsRegisteredNames(): void
    {
        $this->formatter->register('phone', fn (string $n) => $n);
        $this->formatter->register('slug', fn (string $s) => $s);
        self::assertSame(['phone', 'slug'], $this->formatter->getCustomFormatterNames());
    }

    public function testFrenchCanadianGroupingKeepsTheIcuDefault(): void
    {
        $formatter = new Formatter('fr_CA', 'CAD');

        self::assertSame("1\u{00A0}234\u{00A0}567,89", $formatter->decimal(1234567.89));
        self::assertStringContainsString("1\u{00A0}234\u{00A0}567", $formatter->money(1234567.89));
        self::assertStringContainsString("123\u{00A0}456\u{00A0}789", $formatter->percent(1234567.89));
    }

    public function testGroupingSeparatorOptionAppliesToEveryGroupedOutput(): void
    {
        $narrow = "\u{202F}";
        $formatter = new Formatter('fr_CA', 'CAD', groupingSeparator: $narrow);

        self::assertSame("1{$narrow}234{$narrow}567,89", $formatter->decimal(1234567.89));
        self::assertStringContainsString("1{$narrow}234{$narrow}567", $formatter->money(1234567.89));
        self::assertStringContainsString("123{$narrow}456{$narrow}789", $formatter->percent(1234567.89));
        self::assertSame("1{$narrow}234e", $formatter->ordinal(1234));
    }

    public function testOrdinalGroupingReplacementKeepsPunctuationAroundTheNumber(): void
    {
        $formatter = new Formatter('de_DE', groupingSeparator: "\u{202F}");

        self::assertSame("1\u{202F}234\u{202F}567.", $formatter->ordinal(1234567));
        self::assertSame('1,234th', (new Formatter('en_US'))->ordinal(1234));
    }

    public function testEmptyGroupingSeparatorDisablesGrouping(): void
    {
        $formatter = new Formatter('en_US', 'USD', groupingSeparator: '');

        self::assertSame('1234567.89', $formatter->decimal(1234567.89));
        self::assertSame('$1234567.89', $formatter->money(1234567.89));
        self::assertSame('123456789%', $formatter->percent(1234567.89));
        self::assertSame('1234th', $formatter->ordinal(1234));
    }

    public function testGroupingSeparatorOptionIsRefusedWhenItEqualsTheDecimalSeparator(): void
    {
        $this->expectException(FormatterException::class);
        new Formatter('fr_CA', groupingSeparator: ',');
    }

    public function testGroupingSeparatorRefusalForAReservedSignNamesTheLocale(): void
    {
        try {
            new Formatter('fr_CA', groupingSeparator: ',');
            self::fail('Expected a FormatterException.');
        } catch (FormatterException $exception) {
            self::assertStringContainsString('fr_CA', $exception->getMessage());
            self::assertStringContainsString('decimal', $exception->getMessage());
        }
    }

    public function testGroupingSeparatorOptionIsRefusedWhenLong(): void
    {
        $this->expectException(FormatterException::class);
        new Formatter('en_US', groupingSeparator: 'ABCDE');
    }

    public function testGroupingSeparatorOptionIsRefusedWhenItEqualsADecimalOrMonetarySignOfTheLocale(): void
    {
        $symbols = new NumberFormatter('en_BE', NumberFormatter::DECIMAL);
        self::assertSame(',', $symbols->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL));
        self::assertSame('.', $symbols->getSymbol(NumberFormatter::MONETARY_SEPARATOR_SYMBOL));

        foreach (['.', ','] as $separator) {
            try {
                new Formatter('en_BE', groupingSeparator: $separator);
                self::fail('expected a refusal');
            } catch (FormatterException $exception) {
                self::assertSame(
                    'Invalid grouping separator: it is the decimal or monetary decimal sign of locale en_BE.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function testGroupingSeparatorOptionIsRefusedWhenItIsInvalidUtf8(): void
    {
        $this->expectException(FormatterException::class);
        new Formatter('en_US', groupingSeparator: "\xC3\x28");
    }

    public function testConstructorRefusesALocaleWithANulByte(): void
    {
        try {
            new Formatter("en\0US");
            self::fail('A locale holding a NUL byte must be refused.');
        } catch (FormatterException $exception) {
            self::assertSame('Invalid locale: "en\u0000US"', $exception->getMessage());
        }
    }

    #[DataProviderExternal(FormatterInputTest::class, 'refusedGroupingSeparators')]
    public function testGroupingSeparatorOptionRefusesAnythingOutsideTheList(string $separator): void
    {
        $this->expectException(FormatterException::class);
        new Formatter('en_US', groupingSeparator: $separator);
    }

    public function testGroupingSeparatorRefusalGivesTheSharedReasonWithoutEchoingTheValue(): void
    {
        $separator = "\u{05F3}";

        try {
            new Formatter('en_US', groupingSeparator: $separator);
            self::fail('Expected a FormatterException.');
        } catch (FormatterException $exception) {
            self::assertStringContainsString((string) FormatterInput::groupingSeparatorRefusal($separator), $exception->getMessage());
            self::assertStringNotContainsString($separator, $exception->getMessage());
        }
    }

    #[DataProviderExternal(FormatterInputTest::class, 'acceptedGroupingSeparators')]
    public function testGroupingSeparatorOptionAcceptsTheListedSeparators(string $separator): void
    {
        $locale = $separator === '.' ? 'fr_CA' : 'en_US';
        $formatter = new Formatter($locale, groupingSeparator: $separator);

        self::assertStringStartsWith("1{$separator}234", $formatter->decimal(1234.5, 1));
    }

    public function testGroupingSeparatorOptionAcceptsACommaWhereTheLocaleDoesNotReserveIt(): void
    {
        self::assertSame('1,234.5', (new Formatter('en_US', groupingSeparator: ','))->decimal(1234.5, 1));
    }

    public function testGroupingSeparatorOptionAcceptsNoBreakSpaces(): void
    {
        $formatter = new Formatter('en_US', groupingSeparator: "\u{00A0}");
        self::assertSame("1\u{00A0}234.5", $formatter->decimal(1234.5, 1));

        $formatter = new Formatter('en_US', groupingSeparator: "\u{202F}");
        self::assertSame("1\u{202F}234.5", $formatter->decimal(1234.5, 1));
    }

    public function testGroupingSeparatorOptionAppliesToOrdinals(): void
    {
        $formatter = new Formatter('en_US', groupingSeparator: "\u{2019}");

        self::assertSame("1\u{2019}234th", $formatter->ordinal(1234));
    }

    public function testGroupingSeparatorOptionRefusesLookalikesOfTheDecimalSign(): void
    {
        foreach ([['en_US', "\u{2024}"], ['fr_CA', "\u{201A}"]] as [$locale, $separator]) {
            try {
                new Formatter($locale, groupingSeparator: $separator);
                self::fail('Expected a FormatterException.');
            } catch (FormatterException $exception) {
                self::assertStringContainsString('must be one of', $exception->getMessage());
            }
        }
    }

    public function testEnglishCanadianGroupingIsUnchanged(): void
    {
        $formatter = new Formatter('en_CA');

        self::assertSame('1,234,567.89', $formatter->decimal(1234567.89));
    }

    public function testGroupingSeparatorOptionAppliesToEveryLocale(): void
    {
        $thin = "\u{2009}";

        $english = new Formatter('en_US', 'USD', groupingSeparator: $thin);
        self::assertSame("1{$thin}234{$thin}567.89", $english->decimal(1234567.89));
        self::assertStringContainsString("1{$thin}234{$thin}567", $english->money(1234567.89));
        self::assertStringContainsString("123{$thin}456{$thin}789", $english->percent(1234567.89));

        $french = new Formatter('fr_CA', 'CAD', groupingSeparator: '.');
        self::assertSame('1.234.567,89', $french->decimal(1234567.89));
        self::assertStringContainsString('1.234.567', $french->money(1234567.89));
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

    public function testExplicitPatternOverridesTheDefaultTimeAndDatetimePatterns(): void
    {
        $formatter = new Formatter('en_US', defaultTimePattern: 'HH:mm', defaultDatetimePattern: 'yyyy-MM-dd');
        $moment = new DateTime('2026-03-10 14:30:45');

        self::assertSame('14:30:45', $formatter->time($moment, 'HH:mm:ss'));
        self::assertSame('2026-03-10 14:30', $formatter->datetime($moment, 'yyyy-MM-dd HH:mm'));
    }

    public function testDateShowsNoTimeAndTimeShowsNoDateForAPreset(): void
    {
        $moment = new DateTime('2026-03-10 14:30:45');

        self::assertStringNotContainsString(':', $this->formatter->date($moment, 'short'));
        self::assertStringNotContainsString(':', $this->formatter->date($moment));
        self::assertStringNotContainsString('/', $this->formatter->time($moment, 'short'));
        self::assertStringNotContainsString('Mar', $this->formatter->time($moment, 'medium'));
    }

    public function testDatetimeShowsBothTheDateAndTheTimeForAPreset(): void
    {
        $moment = new DateTime('2026-03-10 14:30:45');

        $short = $this->formatter->datetime($moment, 'short');
        self::assertStringContainsString('/', $short);
        self::assertStringContainsString(':30', $short);

        $default = $this->formatter->datetime($moment);
        self::assertStringContainsString('Mar', $default);
        self::assertStringContainsString(':30:45', $default);
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

    // ─── Refused 'none' date pattern ────────────────────────────────

    /**
     * @return iterable<string, array{string}>
     */
    public static function formatMethodsTakingAPattern(): iterable
    {
        yield 'date' => ['date'];
        yield 'time' => ['time'];
        yield 'datetime' => ['datetime'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedNoneSpellings(): iterable
    {
        yield 'none' => ['none'];
        yield 'upper case' => ['NONE'];
        yield 'mixed case' => ['None'];
        yield 'leading space' => [' none'];
        yield 'trailing space' => ['None '];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedDefaultParameters(): iterable
    {
        yield 'defaultDatePattern' => ['defaultDatePattern'];
        yield 'defaultTimePattern' => ['defaultTimePattern'];
        yield 'defaultDatetimePattern' => ['defaultDatetimePattern'];
    }

    private static function refusal(string $pattern, string $reason): string
    {
        return sprintf(
            'Invalid date pattern "%s": %s. Use short, medium, long, full or an ICU pattern.',
            $pattern,
            $reason,
        );
    }

    #[DataProvider('refusedNoneSpellings')]
    public function testNonePatternIsRefusedInEverySpellingAtTheCall(string $pattern): void
    {
        $formatter = new Formatter('fr_CA');
        $date = new DateTime('2026-03-10 14:30:45');

        foreach (['date', 'time', 'datetime'] as $method) {
            try {
                $formatter->$method($date, $pattern);
                self::fail('expected a refusal');
            } catch (FormatterException $exception) {
                self::assertSame(self::refusal($pattern, 'it formats nothing'), $exception->getMessage());
            }
        }
    }

    #[DataProvider('refusedNoneSpellings')]
    public function testNonePatternIsRefusedInEverySpellingAsAConstructorDefault(string $pattern): void
    {
        foreach (['defaultDatePattern', 'defaultTimePattern', 'defaultDatetimePattern'] as $parameter) {
            try {
                new Formatter('en_US', ...[$parameter => $pattern]);
                self::fail('expected a refusal');
            } catch (FormatterException $exception) {
                self::assertSame(self::refusal($pattern, 'it formats nothing'), $exception->getMessage());
            }
        }
    }

    #[DataProvider('refusedDefaultParameters')]
    public function testABlankConstructorDefaultIsRefused(string $parameter): void
    {
        foreach (['', '  '] as $blank) {
            try {
                new Formatter('en_US', ...[$parameter => $blank]);
                self::fail('expected a refusal');
            } catch (FormatterException $exception) {
                self::assertSame(self::refusal($blank, 'it is empty'), $exception->getMessage());
            }
        }
    }

    #[DataProvider('formatMethodsTakingAPattern')]
    public function testPresetNamesAreMatchedWithoutCaseOrSurroundingSpaces(string $method): void
    {
        $formatter = new Formatter('en_US');
        $date = new DateTime('2026-03-10 14:30:45');

        self::assertSame($formatter->$method($date, 'short'), $formatter->$method($date, 'Short'));
        self::assertSame($formatter->$method($date, 'long'), $formatter->$method($date, ' LONG '));
    }

    #[DataProvider('formatMethodsTakingAPattern')]
    public function testABlankPatternMeansTheConstructorDefault(string $method): void
    {
        $formatter = new Formatter('en_US');
        $date = new DateTime('2026-03-10 14:30:45');

        self::assertSame($formatter->$method($date), $formatter->$method($date, ''));
        self::assertSame($formatter->$method($date), $formatter->$method($date, '  '));
    }

    public function testAnIcuPatternThatIsNotAPresetIsNotTrimmed(): void
    {
        self::assertSame(' 2026 ', (new Formatter('en_US'))->date(new DateTime('2026-03-10'), ' yyyy '));
    }
}
