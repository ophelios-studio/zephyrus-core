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

    private function __construct() {}
}
