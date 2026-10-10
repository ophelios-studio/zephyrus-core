<?php

declare(strict_types=1);

namespace Zephyrus\Exceptions;

/**
 * Shows a value inside an exception message, escaped so that no control or format character reaches a log raw.
 *
 * @internal
 */
final class MessageValue
{
    private const int JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_THROW_ON_ERROR;

    /** Control and format characters (bidi included), and the line and paragraph separators. */
    private const string CONTROL_PATTERN = '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u';

    /** JSON's escapes for a quote and a backslash, mapped back to the characters. */
    private const array JSON_QUOTE_AND_BACKSLASH = ['\\"' => '"', '\\\\' => '\\'];

    /** Every byte outside printable ASCII, for addcslashes(). */
    private const string NON_PRINTABLE_BYTES = "\0..\37\177..\377";

    /** A UTF-8 character is at most 4 bytes, so a character boundary lies within 3 bytes of any cut. */
    private const int MAX_BOUNDARY_WALK = 3;

    /**
     * The value as a double-quoted JSON string, also a YAML double-quoted scalar unless it holds a format
     * character above U+FFFF (escaped as a surrogate pair, which Symfony Yaml does not combine).
     *
     * Quotes, backslashes, control and format characters are escaped; other characters stay readable and
     * invalid UTF-8 becomes U+FFFD. A value over $maxBytes is cut on a character boundary and followed by
     * its length: `"abc..." (300 bytes)`, or `"...xyz" (300 bytes)` with $keepEnd.
     */
    public static function quote(string $value, int $maxBytes = 64, bool $keepEnd = false): string
    {
        $length = strlen($value);
        if ($length <= $maxBytes) {
            return '"' . self::escape($value) . '"';
        }

        return $keepEnd
            ? sprintf('"...%s" (%d bytes)', self::escape(self::lastBytes($value, $maxBytes)), $length)
            : sprintf('"%s..." (%d bytes)', self::escape(self::firstBytes($value, $maxBytes)), $length);
    }

    /**
     * A string through quote(), an int or float as var_export() writes it, true, false, null, or the type name.
     */
    public static function describe(mixed $value): string
    {
        return match (true) {
            is_string($value) => self::quote($value),
            is_int($value), is_float($value) => var_export($value, true),
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            default => get_debug_type($value),
        };
    }

    /**
     * Each value through describe(), separated by commas.
     *
     * @param iterable<mixed> $values
     */
    public static function quoteList(iterable $values): string
    {
        $shown = [];
        foreach ($values as $value) {
            $shown[] = self::describe($value);
        }

        return implode(', ', $shown);
    }

    /**
     * The text with its control and format characters escaped as quote() escapes them and invalid UTF-8 as
     * U+FFFD, quotes and backslashes left as they are: for embedding a message that already quotes its values.
     */
    public static function escapeControls(string $text): string
    {
        return strtr(self::escape($text), self::JSON_QUOTE_AND_BACKSLASH);
    }

    private static function escape(string $value): string
    {
        return self::escapeControlCharacters(substr(json_encode($value, self::JSON_FLAGS), 1, -1));
    }

    private static function escapeControlCharacters(string $text): string
    {
        return preg_replace_callback(
            self::CONTROL_PATTERN,
            static fn (array $match): string => self::unicodeEscape(mb_ord($match[0], 'UTF-8')),
            $text,
        ) ?? addcslashes($text, self::NON_PRINTABLE_BYTES); // Fails closed if PCRE refuses the text.
    }

    /** A \uXXXX escape, or a UTF-16 surrogate pair of them above U+FFFF, as JSON writes it. */
    private static function unicodeEscape(int $codePoint): string
    {
        if ($codePoint <= 0xFFFF) {
            return sprintf('\u%04x', $codePoint);
        }

        $offset = $codePoint - 0x10000;

        return sprintf('\u%04x\u%04x', 0xD800 | ($offset >> 10), 0xDC00 | ($offset & 0x3FF));
    }

    private static function firstBytes(string $value, int $maxBytes): string
    {
        $end = $maxBytes;
        $limit = $end - self::MAX_BOUNDARY_WALK;
        while ($end > $limit && self::isContinuationByte($value, $end)) {
            --$end;
        }

        return substr($value, 0, max(0, $end));
    }

    private static function lastBytes(string $value, int $maxBytes): string
    {
        $start = strlen($value) - $maxBytes;
        $limit = $start + self::MAX_BOUNDARY_WALK;
        while ($start < $limit && self::isContinuationByte($value, $start)) {
            ++$start;
        }

        return substr($value, $start);
    }

    private static function isContinuationByte(string $value, int $offset): bool
    {
        return $offset >= 0 && (ord($value[$offset] ?? "\0") & 0xC0) === 0x80;
    }
}
