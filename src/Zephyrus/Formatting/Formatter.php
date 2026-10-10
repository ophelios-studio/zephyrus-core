<?php

declare(strict_types=1);

namespace Zephyrus\Formatting;

use DateTimeInterface;
use IntlDateFormatter;
use Locale;
use NumberFormatter;

/**
 * Formatting built on ext-intl: numbers, money, dates, ordinals and spelled-out numbers.
 *
 * money(), decimal(), percent(), ordinal(), spellOut(), date(), time() and datetime() use the locale given to
 * the constructor. timeago(), duration(), filesize() and list() are written in French for a French locale
 * (language fr) and in English for any other language.
 *
 * Usage:
 *
 *   $fmt = new Formatter('en_US');
 *   $fmt->money(19.99);          // "$19.99"
 *   $fmt->decimal(1234.5);       // "1,234.50"
 *   $fmt->percent(0.85);         // "85%"
 *   $fmt->ordinal(3);            // "3rd"
 *   $fmt->spellOut(42);          // "forty-two"
 *   $fmt->date(new DateTime());  // "Mar 10, 2026"
 *   $fmt->timeago(time() - 3600); // "1 hour ago"
 *   $fmt->filesize(1536000);     // "1.5 MB"
 *   $fmt->duration(7830);        // "2h 10m 30s"
 *   $fmt->list(['a', 'b', 'c']); // "a, b, and c"
 */
final class Formatter
{
    private string $locale;
    private bool $french;
    private string $plainNumberLocale;
    private ?string $defaultCurrency;
    private string $defaultDatePattern;
    private string $defaultTimePattern;
    private string $defaultDatetimePattern;
    private ?string $groupingSeparator;

    /** @var array<string, callable> */
    private array $customFormatters = [];

    /** @var array<int, NumberFormatter> One ungrouped formatter per precision, created on first use. */
    private array $plainNumberFormatters = [];

    /** @var list<string> */
    public const BUILT_IN_FORMATTERS = [
        'money', 'decimal', 'percent', 'ordinal', 'spellOut', 'date', 'time', 'datetime',
        'timeago', 'duration', 'filesize', 'list', 'truncate',
    ];

    private const NBSP = "\u{a0}";

    private const MAX_PRECISION = 20;

    /** @var array<string, array{string, string}> Singular and plural French labels per timeago() unit. */
    private const FRENCH_TIME_UNITS = [
        'second' => ['seconde', 'secondes'],
        'minute' => ['minute', 'minutes'],
        'hour' => ['heure', 'heures'],
        'day' => ['jour', 'jours'],
        'month' => ['mois', 'mois'],
        'year' => ['an', 'ans'],
    ];

    /** @var list<string> */
    private const ENGLISH_FILESIZE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

    /** @var list<string> */
    private const FRENCH_FILESIZE_UNITS = ['o', 'ko', 'Mo', 'Go', 'To', 'Po'];

    /**
     * @param string      $locale                 ICU locale identifier (e.g. 'en', 'en_US', 'fr_CA').
     * @param string|null $defaultCurrency         ISO 4217 code, three ASCII letters, used by money() when none is given.
     *                                             Null or '' uses the locale's currency.
     * @param string      $defaultDatePattern      Default for date(): ICU preset ('short', 'medium', 'long', 'full') or ICU pattern.
     * @param string      $defaultTimePattern      Default for time(), same syntax.
     * @param string      $defaultDatetimePattern  Default for datetime(), same syntax.
     * @param string|null $groupingSeparator       Thousands separator for money(), decimal(), percent() and ordinal().
     *                                             Null keeps the locale's ICU default, '' disables grouping. Otherwise
     *                                             one of `,` `.` `'` U+2019, a space, U+00A0, U+202F or U+2009, and not
     *                                             the locale's decimal or monetary decimal sign.
     *                                             In a right-to-left locale, or inside right-to-left text, only `,` `.`
     *                                             U+00A0 and U+202F keep the groups in order; a space, U+2009, `'` or
     *                                             U+2019 reverses them.
     * @throws FormatterException if $locale contains a NUL byte, or the default currency or the grouping separator
     *         is not accepted.
     */
    public function __construct(
        string $locale = 'en_US',
        ?string $defaultCurrency = null,
        string $defaultDatePattern = 'medium',
        string $defaultTimePattern = 'short',
        string $defaultDatetimePattern = 'medium',
        ?string $groupingSeparator = null,
    ) {
        if (str_contains($locale, "\0")) {
            throw FormatterException::invalidLocale($locale);
        }
        $this->locale = $locale;
        $this->french = Locale::getPrimaryLanguage($locale) === 'fr';
        $this->plainNumberLocale = $this->french ? $locale : 'en';
        $this->defaultCurrency = self::currencyCode($defaultCurrency);
        $this->defaultDatePattern = $defaultDatePattern;
        $this->defaultTimePattern = $defaultTimePattern;
        $this->defaultDatetimePattern = $defaultDatetimePattern;
        if ($groupingSeparator !== null && $groupingSeparator !== '') {
            $this->assertValidGroupingSeparator($groupingSeparator);
        }
        $this->groupingSeparator = $groupingSeparator;
    }

    /**
     * Returns the locale given to the constructor.
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Returns the default currency, or null when none was configured.
     */
    public function getDefaultCurrency(): ?string
    {
        return $this->defaultCurrency;
    }

    /**
     * Returns the default date pattern.
     */
    public function getDefaultDatePattern(): string
    {
        return $this->defaultDatePattern;
    }

    /**
     * Returns the default time pattern.
     */
    public function getDefaultTimePattern(): string
    {
        return $this->defaultTimePattern;
    }

    /**
     * Returns the default datetime pattern.
     */
    public function getDefaultDatetimePattern(): string
    {
        return $this->defaultDatetimePattern;
    }

    /**
     * Formats a monetary amount.
     *
     * The currency is the explicit $currency, else the default currency, else the locale's native currency. An empty
     * string counts as no currency. A locale without a country (en, fr, es_419) has no native currency, so pass a
     * currency or set $defaultCurrency.
     *
     * @param float       $amount   The monetary value.
     * @param string|null $currency ISO 4217 code, three ASCII letters (e.g. 'USD', 'EUR'). Null or '' uses the default
     *                              currency.
     *
     * @throws FormatterException When the currency is not three ASCII letters, when no currency applies because the
     *                            locale has no native currency, or when ICU cannot format the amount.
     */
    public function money(float $amount, ?string $currency = null): string
    {
        $fmt = $this->groupedNumberFormatter(NumberFormatter::CURRENCY);
        $resolvedCurrency = self::currencyCode($currency)
            ?? $this->defaultCurrency
            ?? $this->nativeCurrency($fmt);

        $result = $fmt->formatCurrency($amount, $resolvedCurrency);

        if ($result === false) {
            throw FormatterException::formattingFailed('money', $fmt->getErrorMessage());
        }

        return $result;
    }

    /**
     * Formats a number with grouping separators and exactly $precision fraction digits.
     *
     * @throws FormatterException When $precision is outside 0 to 20, or when ICU cannot format the value.
     */
    public function decimal(float $value, int $precision = 2): string
    {
        $this->assertPrecision('decimal', $precision);
        $fmt = $this->groupedNumberFormatter(NumberFormatter::DECIMAL);
        $fmt->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $precision);
        $fmt->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $precision);

        $result = $fmt->format($value);
        if ($result === false) {
            throw FormatterException::formattingFailed('decimal', $fmt->getErrorMessage());
        }

        return $result;
    }

    /**
     * Formats a fraction as a percentage (0.85 gives "85%").
     *
     * @throws FormatterException When $precision is outside 0 to 20, or when ICU cannot format the value.
     */
    public function percent(float $value, int $precision = 0): string
    {
        $this->assertPrecision('percent', $precision);
        $fmt = $this->groupedNumberFormatter(NumberFormatter::PERCENT);
        $fmt->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $precision);
        $fmt->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $precision);

        $result = $fmt->format($value);
        if ($result === false) {
            throw FormatterException::formattingFailed('percent', $fmt->getErrorMessage());
        }

        return $result;
    }

    /**
     * Formats a number as an ordinal (e.g. "1st", "2nd", "3rd").
     *
     * @throws FormatterException When ICU cannot format the value.
     */
    public function ordinal(int $value): string
    {
        $fmt = new NumberFormatter($this->locale, NumberFormatter::ORDINAL);
        $result = $fmt->format($value);
        if ($result === false) {
            throw FormatterException::formattingFailed('ordinal', $fmt->getErrorMessage());
        }

        return $this->groupingSeparator === null ? $result : $this->replaceOrdinalGrouping($result);
    }

    /**
     * Spells out a number in words (e.g. 42 gives "forty-two").
     *
     * @throws FormatterException When ICU cannot format the value.
     */
    public function spellOut(float $value): string
    {
        $fmt = new NumberFormatter($this->locale, NumberFormatter::SPELLOUT);
        $result = $fmt->format($value);
        if ($result === false) {
            throw FormatterException::formattingFailed('spellOut', $fmt->getErrorMessage());
        }

        return $result;
    }

    /**
     * Formats a date. Without $pattern, the constructor default is used.
     *
     * @param mixed       $date    A DateTimeInterface, Unix timestamp (int), or date string.
     * @param string|null $pattern Preset or ICU pattern. Null uses the configured default.
     *
     * @throws FormatterException When the value is not a supported date or ICU cannot format it.
     */
    public function date(mixed $date, ?string $pattern = null): string
    {
        return $this->formatDateTime($date, $pattern ?? $this->defaultDatePattern, dateOnly: true);
    }

    /**
     * Formats a time. Without $pattern, the constructor default is used.
     *
     * @param mixed       $time    A DateTimeInterface, Unix timestamp (int), or time string.
     * @param string|null $pattern Preset or ICU pattern. Null uses the configured default.
     *
     * @throws FormatterException When the value is not a supported time or ICU cannot format it.
     */
    public function time(mixed $time, ?string $pattern = null): string
    {
        return $this->formatDateTime($time, $pattern ?? $this->defaultTimePattern, timeOnly: true);
    }

    /**
     * Formats a date and time. Without $pattern, the constructor default is used.
     *
     * @param mixed       $datetime A DateTimeInterface, Unix timestamp (int), or datetime string.
     * @param string|null $pattern  Preset or ICU pattern. Null uses the configured default.
     *
     * @throws FormatterException When the value is not a supported datetime or ICU cannot format it.
     */
    public function datetime(mixed $datetime, ?string $pattern = null): string
    {
        return $this->formatDateTime($datetime, $pattern ?? $this->defaultDatetimePattern);
    }

    /**
     * Formats the distance to now ("2 hours ago", "in 3 days", "just now"). French for a French locale (language fr),
     * English for any other language.
     *
     * @param mixed $datetime A DateTimeInterface, Unix timestamp (int), or datetime string.
     *
     * @throws FormatterException When the value is not a supported datetime.
     */
    public function timeago(mixed $datetime): string
    {
        $timestamp = $this->toTimestamp($datetime);
        $diff = time() - $timestamp;
        $absDiff = abs($diff);
        $isFuture = $diff < 0;

        [$value, $unit] = match (true) {
            $absDiff < 60 => [$absDiff, 'second'],
            $absDiff < 3600 => [(int) round($absDiff / 60), 'minute'],
            $absDiff < 86400 => [(int) round($absDiff / 3600), 'hour'],
            $absDiff < 2592000 => [(int) round($absDiff / 86400), 'day'],
            $absDiff < 31536000 => [(int) round($absDiff / 2592000), 'month'],
            default => [(int) round($absDiff / 31536000), 'year'],
        };

        $label = $this->timeUnit($unit, $value);

        if ($this->isFrench()) {
            if ($isFuture) {
                return sprintf('dans %d %s', $value, $label);
            }

            return $value === 0 ? "à l'instant" : sprintf('il y a %d %s', $value, $label);
        }

        if ($isFuture) {
            return sprintf('in %d %s', $value, $label);
        }

        return $value === 0 ? 'just now' : sprintf('%d %s ago', $value, $label);
    }

    /**
     * Formats a duration in seconds: "2h 10m 30s" in English, "2 h 10 min 30 s" in French (language fr, U+00A0 between
     * number and unit), English for any other language. Negative values get a leading "-".
     */
    public function duration(int $seconds): string
    {
        $absSeconds = abs($seconds);
        $hours = (int) floor($absSeconds / 3600);
        $minutes = (int) floor(($absSeconds % 3600) / 60);
        $secs = $absSeconds % 60;

        [$hourUnit, $minuteUnit, $secondUnit, $separator] = $this->isFrench()
            ? ['h', 'min', 's', self::NBSP]
            : ['h', 'm', 's', ''];

        $parts = [];
        if ($hours > 0) {
            $parts[] = $hours . $separator . $hourUnit;
            $parts[] = $minutes . $separator . $minuteUnit;
            $parts[] = $secs . $separator . $secondUnit;
        } elseif ($minutes > 0) {
            $parts[] = $minutes . $separator . $minuteUnit;
            $parts[] = $secs . $separator . $secondUnit;
        } else {
            $parts[] = $secs . $separator . $secondUnit;
        }

        $result = implode(' ', $parts);
        return $seconds < 0 ? '-' . $result : $result;
    }

    /**
     * Formats a byte count in 1024-based units: "1.5 MB" in English, "1,5 Mo" in French (language fr, decimal mark
     * of the locale, U+00A0 between number and unit), English for any other language. Bytes are shown without
     * decimals. No grouping separator.
     *
     * @throws FormatterException When $precision is outside 0 to 20, or when ICU cannot format the value.
     */
    public function filesize(int $bytes, int $precision = 1): string
    {
        $this->assertPrecision('filesize', $precision);
        $isFrench = $this->isFrench();
        $units = $isFrench ? self::FRENCH_FILESIZE_UNITS : self::ENGLISH_FILESIZE_UNITS;
        $absBytes = abs($bytes);
        $index = 0;
        $value = (float) $absBytes;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        $formatted = $index === 0
            ? sprintf('%d', (int) $value)
            : $this->plainNumber($value, $precision, 'filesize');

        $separator = $isFrench ? self::NBSP : ' ';
        $prefix = $bytes < 0 ? '-' : '';
        return $prefix . $formatted . $separator . $units[$index];
    }

    /**
     * Joins items with "and" or "or": "a, b, and c" in English, "a, b et c" in French (language fr, no serial comma),
     * English for any other language.
     *
     * @param string[] $items List of items.
     * @param string   $type  'conjunction' (a, b, and c) or 'disjunction' (a, b, or c). Any other value is a conjunction.
     */
    public function list(array $items, string $type = 'conjunction'): string
    {
        if ($items === []) {
            return '';
        }

        if (count($items) === 1) {
            return (string) reset($items);
        }

        $isFrench = $this->isFrench();
        $word = $type === 'disjunction' ? ($isFrench ? 'ou' : 'or') : ($isFrench ? 'et' : 'and');

        if (count($items) === 2) {
            return implode(' ' . $word . ' ', $items);
        }

        $last = array_pop($items);
        $serialComma = $isFrench ? '' : ',';
        return implode(', ', $items) . $serialComma . ' ' . $word . ' ' . $last;
    }

    /**
     * Truncates to at most $length characters, appending $suffix (default "...") when cut.
     *
     * A $suffix longer than $length is itself cut, so the result never exceeds $length. A negative $length is 0.
     */
    public function truncate(string $value, int $length, string $suffix = '...'): string
    {
        $length = max(0, $length);

        if (mb_strlen($value) <= $length) {
            return $value;
        }

        $keep = $length - mb_strlen($suffix);

        if ($keep <= 0) {
            return mb_substr($suffix, 0, $length);
        }

        return mb_substr($value, 0, $keep) . $suffix;
    }

    /**
     * Registers a custom formatter. A name matching a built-in (ignoring case) overrides that built-in.
     *
     * @param string   $name      Formatter name (e.g. 'phone').
     * @param callable $formatter Receives the arguments given to format() and returns a string.
     */
    public function register(string $name, callable $formatter): void
    {
        $this->customFormatters[$this->builtInName($name) ?? $name] = $formatter;
    }

    /**
     * Whether format() runs a custom formatter for this name.
     */
    public function hasCustomFormatter(string $name): bool
    {
        return $this->resolveCustomFormatter($name) !== null;
    }

    /**
     * Whether format() can apply this name, as a custom or a built-in formatter.
     */
    public function has(string $name): bool
    {
        return $this->resolveCustomFormatter($name) !== null || $this->builtInName($name) !== null;
    }

    /**
     * Returns the names of all registered custom formatters.
     *
     * @return string[]
     */
    public function getCustomFormatterNames(): array
    {
        return array_keys($this->customFormatters);
    }

    /**
     * Applies the formatter called $name: a custom registration first, then a built-in.
     *
     * Built-in names match ignoring case; other custom names are exact.
     *
     * @param mixed ...$args Arguments passed to the formatter.
     *
     * @throws FormatterException For an unknown name, or when a built-in formatter fails. A custom formatter's
     *                            own exception propagates unchanged. A built-in given a wrong argument type or
     *                            count throws TypeError or ArgumentCountError.
     */
    public function format(string $name, mixed ...$args): string
    {
        $custom = $this->resolveCustomFormatter($name);
        if ($custom !== null) {
            return (string) $custom(...$args);
        }

        $builtIn = $this->builtInName($name);
        if ($builtIn !== null) {
            return $this->$builtIn(...$args);
        }

        throw FormatterException::unknownFormatter($name, self::BUILT_IN_FORMATTERS);
    }

    /**
     * NumberFormatter whose grouping separator is the configured one, if any.
     */
    private function groupedNumberFormatter(int $style): NumberFormatter
    {
        $fmt = new NumberFormatter($this->locale, $style);

        if ($this->groupingSeparator !== null) {
            $fmt->setSymbol(NumberFormatter::GROUPING_SEPARATOR_SYMBOL, $this->groupingSeparator);
            $fmt->setSymbol(NumberFormatter::MONETARY_GROUPING_SEPARATOR_SYMBOL, $this->groupingSeparator);
        }

        return $fmt;
    }

    /**
     * Rejects a separator refused by FormatterInput, or equal to the locale's decimal or monetary decimal sign.
     *
     * @throws FormatterException
     */
    private function assertValidGroupingSeparator(string $separator): void
    {
        $refusal = FormatterInput::groupingSeparatorRefusal($separator);
        if ($refusal !== null) {
            throw FormatterException::invalidGroupingSeparator($refusal);
        }

        if (in_array($separator, $this->reservedSeparators(), true)) {
            throw FormatterException::reservedGroupingSeparator($this->locale);
        }
    }

    /**
     * @throws FormatterException When the precision is outside 0 to MAX_PRECISION.
     */
    private function assertPrecision(string $method, int $precision): void
    {
        if ($precision < 0 || $precision > self::MAX_PRECISION) {
            throw FormatterException::invalidPrecision($method, $precision, self::MAX_PRECISION);
        }
    }

    /**
     * Returns null for a null or empty code, the code when it is three ASCII letters.
     *
     * @throws FormatterException if the code is not three ASCII letters.
     */
    private static function currencyCode(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        if (!FormatterInput::isCurrencyCode($code)) {
            throw FormatterException::invalidCurrencyCode();
        }

        return $code;
    }

    /**
     * @return list<string>
     */
    private function reservedSeparators(): array
    {
        return [
            $this->localeSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL),
            $this->localeSymbol(NumberFormatter::MONETARY_SEPARATOR_SYMBOL),
        ];
    }

    /**
     * ICU ordinals ignore the grouping setting, so the locale's grouping character between digits is swapped.
     */
    private function replaceOrdinalGrouping(string $ordinal): string
    {
        $localeGrouping = $this->localeSymbol(NumberFormatter::GROUPING_SEPARATOR_SYMBOL);
        if ($localeGrouping === '') {
            return $ordinal;
        }

        return (string) preg_replace_callback(
            '/(?<=\p{Nd})' . preg_quote($localeGrouping, '/') . '(?=\p{Nd})/u',
            fn (): string => (string) $this->groupingSeparator,
            $ordinal,
        );
    }

    private function localeSymbol(int $symbol): string
    {
        return (new NumberFormatter($this->locale, NumberFormatter::DECIMAL))->getSymbol($symbol);
    }

    private function isFrench(): bool
    {
        return $this->french;
    }

    private function timeUnit(string $unit, int $value): string
    {
        if (!$this->isFrench()) {
            return $value === 1 ? $unit : $unit . 's';
        }

        [$singular, $plural] = self::FRENCH_TIME_UNITS[$unit];
        return $value <= 1 ? $singular : $plural;
    }

    /**
     * Formats a number with exactly $precision fraction digits and no grouping.
     *
     * @throws FormatterException When ICU cannot format the value.
     */
    private function plainNumber(float $value, int $precision, string $type): string
    {
        $fmt = $this->plainNumberFormatters[$precision] ??= $this->newPlainNumberFormatter($precision);

        $result = $fmt->format($value);
        if ($result === false) {
            throw FormatterException::formattingFailed($type, $fmt->getErrorMessage());
        }

        return $result;
    }

    private function newPlainNumberFormatter(int $precision): NumberFormatter
    {
        $fmt = new NumberFormatter($this->plainNumberLocale, NumberFormatter::DECIMAL);
        $fmt->setAttribute(NumberFormatter::GROUPING_USED, 0);
        $fmt->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $precision);
        $fmt->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $precision);

        return $fmt;
    }

    /**
     * The locale's native currency. ICU reports XXX for a locale without one, and an empty code is refused the same way.
     *
     * @throws FormatterException When the locale has no native currency.
     */
    private function nativeCurrency(NumberFormatter $fmt): string
    {
        $code = $fmt->getTextAttribute(NumberFormatter::CURRENCY_CODE);

        if ($code === '' || $code === 'XXX') {
            throw FormatterException::currencyRequired($this->locale);
        }

        return $code;
    }

    /**
     * The exact custom registration, else the override of the built-in matching the name ignoring case.
     *
     * @return callable|null Null when format() falls through to a built-in, or fails.
     */
    private function resolveCustomFormatter(string $name): ?callable
    {
        if (isset($this->customFormatters[$name])) {
            return $this->customFormatters[$name];
        }

        $builtIn = $this->builtInName($name);

        return $builtIn === null ? null : ($this->customFormatters[$builtIn] ?? null);
    }

    /**
     * Canonical built-in name matching the given name ignoring case, or null.
     */
    private function builtInName(string $name): ?string
    {
        foreach (self::BUILT_IN_FORMATTERS as $builtIn) {
            if (strcasecmp($builtIn, $name) === 0) {
                return $builtIn;
            }
        }

        return null;
    }

    private function formatDateTime(
        mixed $value,
        string $pattern,
        bool $dateOnly = false,
        bool $timeOnly = false,
    ): string {
        $timestamp = $this->toTimestamp($value);

        $presetMap = [
            'none' => IntlDateFormatter::NONE,
            'short' => IntlDateFormatter::SHORT,
            'medium' => IntlDateFormatter::MEDIUM,
            'long' => IntlDateFormatter::LONG,
            'full' => IntlDateFormatter::FULL,
        ];

        $isPreset = isset($presetMap[$pattern]);

        if ($isPreset) {
            $dateType = $timeOnly ? IntlDateFormatter::NONE : $presetMap[$pattern];
            $timeType = $dateOnly ? IntlDateFormatter::NONE : $presetMap[$pattern];
            $fmt = new IntlDateFormatter($this->locale, $dateType, $timeType);
        } else {
            $fmt = new IntlDateFormatter(
                $this->locale,
                IntlDateFormatter::FULL,
                IntlDateFormatter::FULL,
                pattern: $pattern,
            );
        }

        $result = $fmt->format($timestamp);
        if ($result === false) {
            throw FormatterException::formattingFailed('datetime', $fmt->getErrorMessage());
        }

        return $result;
    }

    /**
     * Converts a DateTimeInterface, an int timestamp or a string to a Unix timestamp.
     *
     * Strings go through strtotime(), so relative forms such as 'tomorrow' are accepted.
     *
     * @throws FormatterException When the string cannot be parsed or the type is unsupported.
     */
    private function toTimestamp(mixed $value): int
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            $ts = strtotime($value);
            if ($ts === false) {
                throw FormatterException::formattingFailed('datetime', sprintf(
                    'Unable to parse date string of %d bytes',
                    strlen($value),
                ));
            }
            return $ts;
        }

        throw FormatterException::formattingFailed('datetime', sprintf(
            'Unsupported date type: %s',
            get_debug_type($value),
        ));
    }
}
