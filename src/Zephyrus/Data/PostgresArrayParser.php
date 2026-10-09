<?php

declare(strict_types=1);

namespace Zephyrus\Data;

/**
 * Decodes the text form of a PostgreSQL array, as pdo_pgsql returns an array column.
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
     * Splitting on commas is not enough: a quoted element may hold commas,
     * braces or escaped quotes, and NULL is a SQL null only when unquoted.
     * Working byte by byte is safe for UTF-8, because every delimiter is ASCII
     * and no continuation byte can equal one.
     *
     * A value that is not a well-formed literal comes back unchanged rather
     * than truncated, so the caller sees exactly what the driver sent.
     *
     * @return list<mixed>|string
     */
    public static function parse(string $literal): array|string
    {
        $pos = 0;

        // An array whose lower bound is not 1 is printed with a decoration such
        // as [0:1]={a,b}; the decoration is not one of the elements.
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
     * Read one {...} group starting at $pos and leave $pos just past its closing
     * brace. Returns false on any syntax error.
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
     * Read a double-quoted element. Inside quotes a backslash takes the next
     * byte literally, and the quoted text is always a string, so "NULL" stays
     * the string NULL.
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
     * Read an unquoted element up to the next ',' or '}'. Surrounding whitespace
     * is not part of it, but whitespace that was escaped is.
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
