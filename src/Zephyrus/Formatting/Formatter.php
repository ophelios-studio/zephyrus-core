<?php

declare(strict_types=1);

namespace Zephyrus\Formatting;

use DateTimeInterface;
use IntlDateFormatter;
use NumberFormatter;

/**
 * Locale-aware formatting utilities built on ext-intl.
 *
 * Provides a clean API for formatting numbers, currency, dates, times,
 * durations, file sizes, lists, and more — all respecting the configured
 * locale.
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
 *   $fmt->timeago(time() - 3600);  // "1 hour ago"
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
     * @param string|null $defaultCurrency         ISO 4217 currency code used by money() when no
     *                                             explicit currency is given (e.g. 'USD', 'CAD').
     *                                             If null, the locale's native currency is used.
     * @param string      $defaultDatePattern      Default pattern for date(). ICU preset name
     *                                             ('short', 'medium', 'long', 'full') or a custom
     *                                             ICU pattern (e.g. 'yyyy-MM-dd').
     * @param string      $defaultTimePattern      Default pattern for time().
     * @param string      $defaultDatetimePattern  Default pattern for datetime().
     * @param string|null $groupingSeparator       Thousands separator for money(), decimal(), percent() and
     *                                             ordinal(). Null keeps the ICU default of the locale, '' disables
     *                                             grouping. Otherwise at most 4 bytes, no digit, and not the
     *                                             locale's decimal separator.
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
     * Get the active locale.
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Get the configured default currency, if any.
     */
    public function getDefaultCurrency(): ?string
    {
        return $this->defaultCurrency;
    }

    /**
     * Get the configured default date pattern.
     */
    public function getDefaultDatePattern(): string
    {
        return $this->defaultDatePattern;
    }

    /**
     * Get the configured default time pattern.
     */
    public function getDefaultTimePattern(): string
    {
        return $this->defaultTimePattern;
    }

    /**
     * Get the configured default datetime pattern.
     */
    public function getDefaultDatetimePattern(): string
    {
        return $this->defaultDatetimePattern;
    }

    // ─── Numeric ──────────────────────────────────────────────────────

    /**
     * Format a monetary amount.
     *
     * Resolution order for the currency code:
     *  1. The explicit $currency argument.
     *  2. The default currency set on this Formatter instance.
     *  3. The locale's native currency (e.g. 'en_US' → 'USD').
     *
     * @param float       $amount   The monetary value.
     * @param string|null $currency ISO 4217 currency code (e.g. 'USD', 'EUR').
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
     * Format a decimal number with grouping separators.
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
     * Format a value as a percentage.
     *
     * Input is a fraction (0.85 = 85%).
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
     * Format a number as an ordinal (e.g. "1st", "2nd", "3rd").
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
     * Spell out a number in words (e.g. 42 => "forty-two").
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

    // ─── Temporal ─────────────────────────────────────────────────────

    /**
     * Format a date.
     *
     * When called without an explicit $pattern, the default configured via
     * the constructor is used. Override per-call with any ICU preset
     * ('short', 'medium', 'long', 'full') or a custom ICU pattern
     * (e.g. 'yyyy-MM-dd', 'EEEE d MMMM yyyy').
     *
     * @param mixed       $date    A DateTimeInterface, Unix timestamp (int), or date string.
     * @param string|null $pattern Preset or ICU pattern. Null uses the configured default.
     */
    public function date(mixed $date, ?string $pattern = null): string
    {
        return $this->formatDateTime($date, $pattern ?? $this->defaultDatePattern, dateOnly: true);
    }

    /**
     * Format a time.
     *
     * @param mixed       $time    A DateTimeInterface, Unix timestamp (int), or time string.
     * @param string|null $pattern Preset or ICU pattern. Null uses the configured default.
     */
    public function time(mixed $time, ?string $pattern = null): string
    {
        return $this->formatDateTime($time, $pattern ?? $this->defaultTimePattern, timeOnly: true);
    }

    /**
     * Format a date and time.
     *
     * @param mixed       $datetime A DateTimeInterface, Unix timestamp (int), or datetime string.
     * @param string|null $pattern  Preset or ICU pattern. Null uses the configured default.
     */
    public function datetime(mixed $datetime, ?string $pattern = null): string
    {
        return $this->formatDateTime($datetime, $pattern ?? $this->defaultDatetimePattern);
    }

    /**
     * Format a relative time difference (e.g. "2 hours ago", "in 3 days").
     *
     * @param mixed $datetime A DateTimeInterface, Unix timestamp (int), or datetime string.
     */
    public function timeago(mixed $datetime): string
    {
        $timestamp = $this->toTimestamp($datetime);
        $now = time();
        $diff = $now - $timestamp;
        $absDiff = abs($diff);
        $isFuture = $diff < 0;

        // Determine the most appropriate unit.
        [$value, $unit] = match (true) {
            $absDiff < 60 => [$absDiff, 'second'],
            $absDiff < 3600 => [(int) round($absDiff / 60), 'minute'],
            $absDiff < 86400 => [(int) round($absDiff / 3600), 'hour'],
            $absDiff < 2592000 => [(int) round($absDiff / 86400), 'day'],
            $absDiff < 31536000 => [(int) round($absDiff / 2592000), 'month'],
            default => [(int) round($absDiff / 31536000), 'year'],
        };

        // Use relative-time formatting rules.
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
     * Format a duration in seconds as a human-readable string.
     *
     * Examples: "2h 10m 30s", "45s", "1h 0m 5s".
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

    // ─── Specialized ──────────────────────────────────────────────────

    /**
     * Format a byte count as a human-readable file size.
     *
     * Examples: "1.5 MB", "320 KB", "2.1 GB".
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
     * Format a list of items using locale-aware conjunction/disjunction.
     *
     * @param string[] $items List of items.
     * @param string   $type  'conjunction' (a, b, and c) or 'disjunction' (a, b, or c).
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
     * Truncate a string to the given length, appending a suffix if truncated.
     *
     * The result never exceeds `$length` characters. When `$length` is shorter
     * than the suffix there is no room for both, so the suffix alone is cut to
     * fit; a naive `$length - mb_strlen($suffix)` would go negative and make
     * `mb_substr()` trim from the END of the value, returning a string LONGER
     * than the one that was passed in.
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

    // ─── Custom Formatters ────────────────────────────────────────────

    /**
     * Register a custom named formatter. A built-in name, in any case, overrides that built-in.
     *
     * @param string   $name      Formatter name (e.g. 'phone').
     * @param callable $formatter A callable that receives mixed args and returns string.
     */
    public function register(string $name, callable $formatter): void
    {
        $this->customFormatters[$this->builtInName($name) ?? $name] = $formatter;
    }

    /**
     * Check whether format() runs a custom formatter for this name, using the same name matching as format().
     */
    public function hasCustomFormatter(string $name): bool
    {
        return $this->resolveCustomFormatter($name) !== null;
    }

    /**
     * Get the names of all registered custom formatters.
     *
     * @return string[]
     */
    public function getCustomFormatterNames(): array
    {
        return array_keys($this->customFormatters);
    }

    /**
     * Apply a formatter by name. Built-in names (and their overrides) match ignoring case; other custom names are exact.
     *
     * @param mixed ...$args Arguments passed to the formatter.
     * @throws FormatterException if neither a custom nor a built-in formatter has this name.
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

    // ─── Internal Helpers ─────────────────────────────────────────────

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
     * @throws FormatterException if the separator is longer than 4 bytes, has a digit, or equals the decimal separator.
     */
    private function assertValidGroupingSeparator(string $separator): void
    {
        if (strlen($separator) > 4
            || preg_match('/\p{Nd}/u', $separator) !== 0
            || $separator === $this->localeSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL)
        ) {
            throw FormatterException::invalidGroupingSeparator();
        }
    }

    /**
     * ICU ordinal rules ignore the grouping symbol setting, so swap the locale's grouping character between digits.
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
     * Find the custom formatter answering to a name: the exact registration, else the
     * override of the built-in name the given name matches ignoring case.
     *
     * @return callable|null null when format() falls through to a built-in or fails.
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

    /**
     * Format a datetime value using IntlDateFormatter.
     */
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
     * Convert a mixed datetime value to a Unix timestamp.
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
                    'Unable to parse date string: %s',
                    $value,
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
