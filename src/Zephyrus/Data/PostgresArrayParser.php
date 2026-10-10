<?php

declare(strict_types=1);

namespace Zephyrus\Data;

/**
 * Decodes the text form of a PostgreSQL array, as pdo_pgsql returns an array column.
 *
 * @internal
 */
final class PostgresArrayParser
{
    /**
     * Bytes PostgreSQL treats as insignificant around array elements.
     */
    private const WHITESPACE = " \t\n\r\v\f";

    /**
     * Parse a literal into nested PHP arrays of strings and nulls.
     *
     * Quoted elements may contain commas, braces and escaped quotes; only an unquoted NULL is null.
     * A malformed literal is returned unchanged. Byte-wise scanning is UTF-8 safe: delimiters are ASCII.
     *
     * @return list<mixed>|string
     */
    public static function parse(string $literal): array|string
    {
        $pos = 0;

        // A lower bound other than 1 is printed as a prefix such as [0:1]={a,b}, not as an element.
        if (str_starts_with($literal, '[')) {
            $equals = strpos($literal, '=');

            if ($equals === false) {
                return $literal;
            }

            $pos = $equals + 1;
        }

        $array = self::readArray($literal, $pos);

        if ($array === false || $pos !== strlen($literal)) {
            return $literal;
        }

        return $array;
    }

    /**
     * Read one {...} group at $pos, leaving $pos past its closing brace. Returns false on a syntax error.
     *
     * @return list<mixed>|false
     */
    private static function readArray(string $literal, int &$pos): array|false
    {
        $length = strlen($literal);

        if ($pos >= $length || $literal[$pos] !== '{') {
            return false;
        }

        $pos++;
        $pos += strspn($literal, self::WHITESPACE, $pos);

        if ($pos < $length && $literal[$pos] === '}') {
            $pos++;

            return [];
        }

        $items = [];

        while (true) {
            $pos += strspn($literal, self::WHITESPACE, $pos);

            if ($pos >= $length) {
                return false;
            }

            if ($literal[$pos] === '{') {
                $item = self::readArray($literal, $pos);
            } elseif ($literal[$pos] === '"') {
                $item = self::readQuotedElement($literal, $pos);
            } else {
                $item = self::readUnquotedElement($literal, $pos);
            }

            if ($item === false) {
                return false;
            }

            $items[] = $item;
            $pos += strspn($literal, self::WHITESPACE, $pos);

            if ($pos >= $length) {
                return false;
            }

            if ($literal[$pos] === '}') {
                $pos++;

                return $items;
            }

            if ($literal[$pos] !== ',') {
                return false;
            }

            $pos++;
        }
    }

    /**
     * Read a double-quoted element, where a backslash escapes the next byte. The result is always a string.
     *
     * @return string|false
     */
    private static function readQuotedElement(string $literal, int &$pos): string|false
    {
        $length = strlen($literal);
        $value = '';
        $pos++;

        while (true) {
            $run = strcspn($literal, '"\\', $pos);
            $value .= substr($literal, $pos, $run);
            $pos += $run;

            if ($pos >= $length) {
                return false;
            }

            if ($literal[$pos] === '"') {
                $pos++;

                return $value;
            }

            if ($pos + 1 >= $length) {
                return false;
            }

            $value .= $literal[$pos + 1];
            $pos += 2;
        }
    }

    /**
     * Read an unquoted element up to the next ',' or '}'. Unescaped surrounding whitespace is dropped.
     *
     * @return string|null|false null for an unquoted NULL, false on a syntax error
     */
    private static function readUnquotedElement(string $literal, int &$pos): string|null|false
    {
        $length = strlen($literal);
        $value = '';
        $significant = 0;
        $escaped = false;

        while ($pos < $length) {
            $char = $literal[$pos];

            if ($char === ',' || $char === '}') {
                break;
            }

            if ($char === '{' || $char === '"') {
                return false;
            }

            if ($char === '\\') {
                if ($pos + 1 >= $length) {
                    return false;
                }

                $escaped = true;
                $value .= $literal[$pos + 1];
                $significant = strlen($value);
                $pos += 2;

                continue;
            }

            $value .= $char;
            $pos++;

            if (strpos(self::WHITESPACE, $char) === false) {
                $significant = strlen($value);
            }
        }

        $value = substr($value, 0, $significant);

        if ($value === '') {
            return false;
        }

        if (!$escaped && strcasecmp($value, 'NULL') === 0) {
            return null;
        }

        return $value;
    }
}
