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
 *   $fmt->relativeTime(time() - 3600);  // "1 hour ago"
 *   $fmt->filesize(1536000);     // "1.5 MB"
 *   $fmt->duration(7830);        // "2h 10m 30s"
 *   $fmt->list(['a', 'b', 'c']); // "a, b, and c"
 */
final class Formatter
{
    private string $locale;

    /** @var array<string, callable> */
    private array $customFormatters = [];

    public function __construct(string $locale = 'en_US')
    {
        $this->locale = $locale;
    }

    /**
     * Get the active locale.
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    // ─── Numeric ──────────────────────────────────────────────────────

    /**
     * Format a monetary amount using the locale's currency symbol.
     *
     * @param float       $amount   The monetary value.
     * @param string|null $currency ISO 4217 currency code (e.g. 'USD', 'EUR').
     *                              If null, uses the locale default.
     */
    public function money(float $amount, ?string $currency = null): string
    {
        $fmt = new NumberFormatter($this->locale, NumberFormatter::CURRENCY);
        if ($currency !== null) {
            $result = $fmt->formatCurrency($amount, $currency);
        } else {
            // Derive default currency from locale.
            $defaultCurrency = $fmt->getTextAttribute(NumberFormatter::CURRENCY_CODE);
            $result = $fmt->formatCurrency($amount, $defaultCurrency ?: 'USD');
        }

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
        $fmt = new NumberFormatter($this->locale, NumberFormatter::DECIMAL);
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
        $fmt = new NumberFormatter($this->locale, NumberFormatter::PERCENT);
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

        return $result;
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
     * @param mixed  $date    A DateTimeInterface, Unix timestamp (int), or date string.
     * @param string $pattern Preset: 'short', 'medium', 'long', 'full',
     *                        or a custom ICU pattern (e.g. 'yyyy-MM-dd').
     */
    public function date(mixed $date, string $pattern = 'medium'): string
    {
        return $this->formatDateTime($date, $pattern, dateOnly: true);
    }

    /**
     * Format a time.
     *
     * @param mixed  $time    A DateTimeInterface, Unix timestamp (int), or time string.
     * @param string $pattern Preset: 'short', 'medium', 'long', 'full',
     *                        or a custom ICU pattern.
     */
    public function time(mixed $time, string $pattern = 'short'): string
    {
        return $this->formatDateTime($time, $pattern, timeOnly: true);
    }

    /**
     * Format a date and time.
     *
     * @param mixed  $datetime A DateTimeInterface, Unix timestamp (int), or datetime string.
     * @param string $pattern  Preset name or custom ICU pattern.
     */
    public function datetime(mixed $datetime, string $pattern = 'medium'): string
    {
        return $this->formatDateTime($datetime, $pattern);
    }

    /**
     * Format a relative time difference (e.g. "2 hours ago", "in 3 days").
     *
     * @param mixed $datetime A DateTimeInterface, Unix timestamp (int), or datetime string.
     */
    public function relativeTime(mixed $datetime): string
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
     */
    public function truncate(string $value, int $length, string $suffix = '...'): string
    {
        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length - mb_strlen($suffix)) . $suffix;
    }

    // ─── Custom Formatters ────────────────────────────────────────────

    /**
     * Register a custom named formatter.
     *
     * @param string   $name      Formatter name (e.g. 'phone').
     * @param callable $formatter A callable that receives mixed args and returns string.
     */
    public function register(string $name, callable $formatter): void
    {
        $this->customFormatters[$name] = $formatter;
    }

    /**
     * Apply a named custom formatter.
     *
     * @throws FormatterException if the formatter is not registered.
     */
    public function format(string $name, mixed ...$args): string
    {
        if (!isset($this->customFormatters[$name])) {
            throw FormatterException::unknownFormatter($name);
        }

        return (string) ($this->customFormatters[$name])(...$args);
    }

    // ─── Internal Helpers ─────────────────────────────────────────────

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
