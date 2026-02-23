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

    /**
     * Validates a valid IPv4 address.
     */
    public static function ipv4(string $message = 'Must be a valid IPv4 address.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
            $message,
        );
    }

    /**
     * Validates a valid IPv6 address.
     */
    public static function ipv6(string $message = 'Must be a valid IPv6 address.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false,
            $message,
        );
    }

    /**
     * Validates a DNS hostname (labels 1-63 chars, full host <= 253 chars).
     */
    public static function hostname(string $message = 'Must be a valid hostname.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v)) {
                    return false;
                }

                $host = strtolower(trim($v));
                if ($host === '' || strlen($host) > 253 || str_starts_with($host, '.') || str_ends_with($host, '.')) {
                    return false;
                }

                $labels = explode('.', $host);
                foreach ($labels as $label) {
                    if ($label === '' || strlen($label) > 63) {
                        return false;
                    }

                    if (preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
                        return false;
                    }
                }

                return true;
            },
            $message,
        );
    }

    /**
     * Validates IPv4/IPv6 CIDR notation (e.g. 10.0.0.0/8, 2001:db8::/32).
     */
    public static function cidr(string $message = 'Must be a valid CIDR block.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v) || !str_contains($v, '/')) {
                    return false;
                }

                [$ip, $prefix] = explode('/', $v, 2);
                if ($ip === '' || $prefix === '' || !ctype_digit($prefix)) {
                    return false;
                }

                $prefixLength = (int) $prefix;

                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                    return $prefixLength >= 0 && $prefixLength <= 32;
                }

                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                    return $prefixLength >= 0 && $prefixLength <= 128;
                }

                return false;
            },
            $message,
        );
    }

    /**
     * Validates a MAC address in common formats (e.g. 00:1A:2B:3C:4D:5E).
     */
    public static function macAddress(string $message = 'Must be a valid MAC address.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && filter_var($v, FILTER_VALIDATE_MAC) !== false,
            $message,
        );
    }

    /**
     * Validates a TCP/UDP port number (1..65535).
     */
    public static function port(string $message = 'Must be a valid port number.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (is_int($v)) {
                    return $v >= 1 && $v <= 65535;
                }

                if (!is_string($v) || $v === '' || !ctype_digit($v)) {
                    return false;
                }

                $port = (int) $v;

                return $port >= 1 && $port <= 65535;
            },
            $message,
        );
    }

    /**
     * Validates either a hostname or an IP address.
     */
    public static function host(string $message = 'Must be a valid host.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => self::hostname()->test($v) || self::ip()->test($v),
            $message,
        );
    }

    /**
     * Validates an inclusive port range expressed as "start-end".
     */
    public static function portRange(string $message = 'Must be a valid port range.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v) || !str_contains($v, '-')) {
                    return false;
                }

                [$start, $end] = explode('-', $v, 2);

                if (!self::port()->test($start) || !self::port()->test($end)) {
                    return false;
                }

                return (int) $start <= (int) $end;
            },
            $message,
        );
    }

    /**
     * Validates a JSON-encoded string.
     */
    public static function json(string $message = 'Must be valid JSON.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v) || $v === '') {
                    return false;
                }

                json_decode($v);

                return json_last_error() === JSON_ERROR_NONE;
            },
            $message,
        );
    }

    /**
     * Validates a URL slug (lowercase letters, numbers, single hyphen separators).
     */
    public static function slug(string $message = 'Must be a valid slug.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $v) === 1,
            $message,
        );
    }

    /**
     * Validates a host:port endpoint.
     * Supports hostname/IPv4 as host:port and IPv6 as [ipv6]:port.
     */
    public static function hostPort(string $message = 'Must be a valid host:port endpoint.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v) || $v === '') {
                    return false;
                }

                if (str_starts_with($v, '[')) {
                    if (!preg_match('/^\[(.+)]:(\d+)$/', $v, $matches)) {
                        return false;
                    }

                    return self::ipv6()->test($matches[1]) && self::port()->test($matches[2]);
                }

                $parts = explode(':', $v);
                if (count($parts) !== 2) {
                    return false;
                }

                [$host, $port] = $parts;

                return self::host()->test($host) && self::port()->test($port);
            },
            $message,
        );
    }

    private function __construct() {}
}
