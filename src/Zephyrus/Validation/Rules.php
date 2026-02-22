<?php

declare(strict_types=1);

namespace Zephyrus\Validation;

final class Rules
{
    public static function required(string $message = 'This field is required.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => $v !== null && $v !== '' && $v !== [],
            $message,
        );
    }

    public static function minLength(int $min, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && mb_strlen($v) >= $min,
            $message ?: "Must be at least {$min} characters.",
        );
    }

    public static function maxLength(int $max, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && mb_strlen($v) <= $max,
            $message ?: "Must be at most {$max} characters.",
        );
    }

    public static function email(string $message = 'Must be a valid email address.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL) !== false,
            $message,
        );
    }

    public static function integer(string $message = 'Must be an integer.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => filter_var($v, FILTER_VALIDATE_INT) !== false,
            $message,
        );
    }

    public static function numeric(string $message = 'Must be numeric.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_numeric($v),
            $message,
        );
    }

    public static function min(int|float $min, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_numeric($v) && (float) $v >= $min,
            $message ?: "Must be at least {$min}.",
        );
    }

    public static function max(int|float $max, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_numeric($v) && (float) $v <= $max,
            $message ?: "Must be at most {$max}.",
        );
    }

    public static function between(int|float $min, int|float $max, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_numeric($v) && (float) $v >= $min && (float) $v <= $max,
            $message ?: "Must be between {$min} and {$max}.",
        );
    }

    public static function regex(string $pattern, string $message): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match($pattern, $v) === 1,
            $message,
        );
    }

    /**
     * @param array<int|string, mixed> $allowed
     */
    public static function in(array $allowed, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => in_array($v, $allowed, strict: true),
            $message ?: 'Must be one of: ' . implode(', ', array_map('strval', $allowed)) . '.',
        );
    }

    public static function url(string $message = 'Must be a valid URL.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && filter_var($v, FILTER_VALIDATE_URL) !== false,
            $message,
        );
    }

    public static function notBlank(string $message = 'Must not be blank.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && trim($v) !== '',
            $message,
        );
    }

    /**
     * Accepts: true, false, 1, 0, '1', '0', 'true', 'false' (case-insensitive).
     */
    public static function boolean(string $message = 'Must be a boolean value.'): Rule
    {
        return Rule::of(
            function (mixed $v): bool {
                if (is_bool($v) || $v === 1 || $v === 0) {
                    return true;
                }
                if (!is_string($v) && !is_int($v)) {
                    return false;
                }
                return in_array(strtolower((string) $v), ['1', '0', 'true', 'false'], strict: true);
            },
            $message,
        );
    }

    /**
     * Validates any standard UUID format (8-4-4-4-12 hex, case-insensitive).
     */
    public static function uuid(string $message = 'Must be a valid UUID.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                $v,
            ) === 1,
            $message,
        );
    }

    /**
     * Validates a date string against the given format (default Y-m-d).
     * Uses DateTime::createFromFormat with strict overflow checking.
     */
    public static function date(string $format = 'Y-m-d', string $message = ''): Rule
    {
        return Rule::of(
            function (mixed $v) use ($format): bool {
                if (!is_string($v)) {
                    return false;
                }
                $dt = \DateTime::createFromFormat($format, $v);
                return $dt !== false && $dt->format($format) === $v;
            },
            $message ?: "Must be a valid date in {$format} format.",
        );
    }

    /**
     * Validates that an array has at least $min items.
     */
    public static function countMin(int $min, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_array($v) && count($v) >= $min,
            $message ?: "Must have at least {$min} item(s).",
        );
    }

    /**
     * Validates that an array has at most $max items.
     */
    public static function countMax(int $max, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_array($v) && count($v) <= $max,
            $message ?: "Must have at most {$max} item(s).",
        );
    }

    /**
     * Validates a valid IPv4 or IPv6 address.
     */
    public static function ip(string $message = 'Must be a valid IP address.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && filter_var($v, FILTER_VALIDATE_IP) !== false,
            $message,
        );
    }

    private function __construct() {}
}
