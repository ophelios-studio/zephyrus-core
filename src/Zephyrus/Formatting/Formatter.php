<?php

declare(strict_types=1);

namespace Zephyrus\Formatting;

use DateTimeInterface;
use IntlDateFormatter;
use NumberFormatter;

/**
 * Formatting built on ext-intl: numbers, money, dates, ordinals and spelled-out numbers.
 *
 * money(), decimal(), percent(), ordinal(), spellOut(), date(), time() and datetime() use the locale given to
 * the constructor. timeago(), duration(), filesize() and list() output English whatever the locale.
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
    private ?string $defaultCurrency;
    private string $defaultDatePattern;
    private string $defaultTimePattern;
    private string $defaultDatetimePattern;
    private ?string $groupingSeparator;

    /** @var array<string, callable> */
    private array $customFormatters = [];

    /** @var list<string> */
    public const BUILT_IN_FORMATTERS = [
        'money', 'decimal', 'percent', 'ordinal', 'spellOut', 'date', 'time', 'datetime',
        'timeago', 'duration', 'filesize', 'list', 'truncate',
    ];

    /**
     * @param string      $locale                 ICU locale identifier (e.g. 'en', 'en_US', 'fr_CA').
     * @param string|null $defaultCurrency         ISO 4217 code used by money() when none is given. Null uses the locale's currency.
     * @param string      $defaultDatePattern      Default for date(): ICU preset ('short', 'medium', 'long', 'full') or ICU pattern.
     * @param string      $defaultTimePattern      Default for time(), same syntax.
     * @param string      $defaultDatetimePattern  Default for datetime(), same syntax.
     * @param string|null $groupingSeparator       Thousands separator for money(), decimal(), percent() and ordinal().
     *                                             Null keeps the locale's ICU default, '' disables grouping. Otherwise
     *                                             at most 4 bytes, valid UTF-8, no digit or control character, not the
     *                                             locale's decimal, monetary decimal or minus sign.
     * @throws FormatterException if the grouping separator is not accepted.
     */
    public function __construct(
        string $locale = 'en_US',
        ?string $defaultCurrency = null,
        string $defaultDatePattern = 'medium',
        string $defaultTimePattern = 'short',
        string $defaultDatetimePattern = 'medium',
        ?string $groupingSeparator = null,
    ) {
        $this->locale = $locale;
        $this->defaultCurrency = $defaultCurrency;
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
     * The currency is the explicit $currency, else the default currency, else the locale's native currency. A locale
     * without a region (en, fr) has no native currency and prints ¤; set $defaultCurrency.
     *
     * @param float       $amount   The monetary value.
     * @param string|null $currency ISO 4217 code (e.g. 'USD', 'EUR').
     *
     * @throws FormatterException When ICU cannot format the amount.
     */
    public function money(float $amount, ?string $currency = null): string
    {
        $fmt = $this->groupedNumberFormatter(NumberFormatter::CURRENCY);
        $resolvedCurrency = $currency
            ?? $this->defaultCurrency
            ?? $fmt->getTextAttribute(NumberFormatter::CURRENCY_CODE) ?: 'USD';

        $result = $fmt->formatCurrency($amount, $resolvedCurrency);

        if ($result === false) {
            throw FormatterException::formattingFailed('money', $fmt->getErrorMessage());
        }

        return $result;
    }

    /**
     * Formats a number with grouping separators and exactly $precision fraction digits.
     *
     * @throws FormatterException When ICU cannot format the value.
     */
    public function decimal(float $value, int $precision = 2): string
    {
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
     * @throws FormatterException When ICU cannot format the value.
     */
    public function percent(float $value, int $precision = 0): string
    {
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
     * Formats the distance to now in English ("2 hours ago", "in 3 days", "just now").
     *
     * @param mixed $datetime A DateTimeInterface, Unix timestamp (int), or datetime string.
     *
     * @throws FormatterException When the value is not a supported datetime.
     */
    public function timeago(mixed $datetime): string
    {
        $timestamp = $this->toTimestamp($datetime);
        $now = time();
        $diff = $now - $timestamp;
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

        $plural = $value !== 1 ? 's' : '';

        if ($isFuture) {
            return sprintf('in %d %s%s', $value, $unit, $plural);
        }

        if ($value === 0) {
            return 'just now';
        }

        return sprintf('%d %s%s ago', $value, $unit, $plural);
    }

    /**
     * Formats a duration in seconds in English ("2h 10m 30s", "45s", "1h 0m 5s"). Negative values get a leading "-".
     */
    public function duration(int $seconds): string
    {
        $absSeconds = abs($seconds);
        $hours = (int) floor($absSeconds / 3600);
        $minutes = (int) floor(($absSeconds % 3600) / 60);
        $secs = $absSeconds % 60;

        $parts = [];
        if ($hours > 0) {
            $parts[] = $hours . 'h';
            $parts[] = $minutes . 'm';
            $parts[] = $secs . 's';
        } elseif ($minutes > 0) {
            $parts[] = $minutes . 'm';
            $parts[] = $secs . 's';
        } else {
            $parts[] = $secs . 's';
        }

        $result = implode(' ', $parts);
        return $seconds < 0 ? '-' . $result : $result;
    }

    /**
     * Formats a byte count in 1024-based English units ("1.5 MB", "320 KB"). Bytes are shown without decimals.
     */
    public function filesize(int $bytes, int $precision = 1): string
    {
        $absBytes = abs($bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $index = 0;
        $value = (float) $absBytes;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        $formatted = $index === 0
            ? sprintf('%d', (int) $value)
            : sprintf('%.' . $precision . 'f', $value);

        $prefix = $bytes < 0 ? '-' : '';
        return $prefix . $formatted . ' ' . $units[$index];
    }

    /**
     * Joins items with English "and" or "or" ("a, b, and c"), whatever the locale.
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

        if (count($items) === 2) {
            $joiner = $type === 'disjunction' ? ' or ' : ' and ';
            return implode($joiner, $items);
        }

        $last = array_pop($items);
        $joiner = $type === 'disjunction' ? ', or ' : ', and ';
        return implode(', ', $items) . $joiner . $last;
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
     * Rejects a separator over 4 bytes, not valid UTF-8, containing a digit or control character, or equal to the
     * locale's decimal, monetary decimal or minus sign (see reservedSeparators()).
     *
     * @throws FormatterException
     */
    private function assertValidGroupingSeparator(string $separator): void
    {
        if (strlen($separator) > 4
            || preg_match('/[\p{Nd}\p{Cc}]/u', $separator) !== 0
            || in_array($separator, $this->reservedSeparators(), true)
        ) {
            throw FormatterException::invalidGroupingSeparator();
        }
    }

    /**
     * @return list<string>
     */
    private function reservedSeparators(): array
    {
        return [
            $this->localeSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL),
            $this->localeSymbol(NumberFormatter::MONETARY_SEPARATOR_SYMBOL),
            $this->localeSymbol(NumberFormatter::MINUS_SIGN_SYMBOL),
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
