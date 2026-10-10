<?php

declare(strict_types=1);

namespace Zephyrus\Validation;

use Zephyrus\Http\IpRange;
use Zephyrus\Http\Response;

final class Rules
{
    /**
     * Fails for null, '' and []; '0', 0 and false pass.
     * The presence of this rule makes FormValidator run the field's rules on empty values.
     * Whitespace-only strings pass (pair with notBlank to refuse them).
     */
    public static function required(string $message = 'This field is required.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => $v !== null && $v !== '' && $v !== [],
            $message,
            'required',
        );
    }

    /**
     * Accepts strings of at least $min characters (counted with mb_strlen); non-strings fail.
     */
    public static function minLength(int $min, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && mb_strlen($v) >= $min,
            $message ?: "Must be at least {$min} characters.",
        );
    }

    /**
     * Accepts strings of at most $max characters (counted with mb_strlen); non-strings fail.
     */
    public static function maxLength(int $max, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && mb_strlen($v) <= $max,
            $message ?: "Must be at most {$max} characters.",
        );
    }

    /**
     * Accepts strings that FILTER_VALIDATE_EMAIL accepts; non-strings fail.
     */
    public static function email(string $message = 'Must be a valid email address.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL) !== false,
            $message,
        );
    }

    /**
     * Accepts ints, integer strings ('5', '+5', padded with spaces, tabs, CR, LF or VT) and whole floats
     * below 1e14 (5.0) with the default precision ini of 14.
     * Booleans, fractional floats, '05' and '5.0' fail.
     */
    public static function integer(string $message = 'Must be an integer.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => !is_bool($v) && filter_var($v, FILTER_VALIDATE_INT) !== false,
            $message,
        );
    }

    /**
     * Accepts int, float and numeric strings, following PHP's is_numeric rules.
     */
    public static function numeric(string $message = 'Must be numeric.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_numeric($v),
            $message,
        );
    }

    /**
     * Accepts strings made of an optional minus sign and ASCII digits; non-strings fail.
     */
    public static function integerString(string $message = 'Must be an integer string.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^-?\d+$/D', $v) === 1,
            $message,
        );
    }

    /**
     * Accepts strings with an optional minus sign, digits and at most $scale decimal places ('.' separator).
     * A $scale of 0 or less accepts integer strings only.
     */
    public static function decimalString(int $scale = 2, string $message = ''): Rule
    {
        $pattern = $scale <= 0
            ? '/^-?\d+$/D'
            : '/^-?\d+(?:\.\d{1,' . $scale . '})?$/D';

        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match($pattern, $v) === 1,
            $message ?: "Must be a decimal string with up to {$scale} decimal places.",
        );
    }

    /**
     * Accepts numbers (int, float or numeric string) greater than or equal to $min.
     */
    public static function min(int|float $min, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_numeric($v) && (float) $v >= $min,
            $message ?: "Must be at least {$min}.",
        );
    }

    /**
     * Accepts numbers (int, float or numeric string) strictly greater than $min.
     */
    public static function greaterThan(int|float $min, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_numeric($v) && (float) $v > $min,
            $message ?: "Must be greater than {$min}.",
        );
    }

    /**
     * Accepts numbers (int, float or numeric string) less than or equal to $max.
     */
    public static function max(int|float $max, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_numeric($v) && (float) $v <= $max,
            $message ?: "Must be at most {$max}.",
        );
    }

    /**
     * Accepts numbers (int, float or numeric string) strictly less than $max.
     */
    public static function lessThan(int|float $max, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_numeric($v) && (float) $v < $max,
            $message ?: "Must be less than {$max}.",
        );
    }

    /**
     * Accepts numbers (int, float or numeric string) in the inclusive range $min to $max.
     */
    public static function between(int|float $min, int|float $max, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_numeric($v) && (float) $v >= $min && (float) $v <= $max,
            $message ?: "Must be between {$min} and {$max}.",
        );
    }

    /**
     * Accepts numbers (int, float or numeric string) strictly between $min and $max.
     */
    public static function betweenExclusive(int|float $min, int|float $max, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_numeric($v) && (float) $v > $min && (float) $v < $max,
            $message ?: "Must be strictly between {$min} and {$max}.",
        );
    }

    /**
     * Accepts strings matching $pattern, a complete PCRE pattern with delimiters; non-strings fail.
     * End the pattern with \z or add the D modifier, otherwise $ also matches before a final newline.
     */
    public static function regex(string $pattern, string $message): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match($pattern, $v) === 1,
            $message,
        );
    }

    /**
     * Accepts strings made only of ASCII bytes; the empty string passes.
     */
    public static function ascii(string $message = 'Must contain only ASCII characters.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^[\x00-\x7F]*$/D', $v) === 1,
            $message,
        );
    }

    /**
     * Accepts non-empty strings of ASCII letters and digits; non-strings fail.
     */
    public static function alphaNumeric(string $message = 'Must contain only letters and numbers.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^[a-zA-Z0-9]+$/D', $v) === 1,
            $message,
        );
    }

    /**
     * Accepts strings starting with $prefix (byte-wise, case-sensitive); an empty prefix always passes.
     */
    public static function startsWith(string $prefix, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && str_starts_with($v, $prefix),
            $message ?: "Must start with '{$prefix}'.",
        );
    }

    /**
     * Accepts strings ending with $suffix (byte-wise, case-sensitive); an empty suffix always passes.
     */
    public static function endsWith(string $suffix, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && str_ends_with($v, $suffix),
            $message ?: "Must end with '{$suffix}'.",
        );
    }

    /**
     * Accepts strings containing $needle (byte-wise, case-sensitive); an empty needle always passes.
     */
    public static function contains(string $needle, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && str_contains($v, $needle),
            $message ?: "Must contain '{$needle}'.",
        );
    }

    /**
     * Accepts values strictly equal (===) to one of $allowed.
     *
     * @param array<int|string, mixed> $allowed
     */
    public static function in(array $allowed, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => in_array($v, $allowed, strict: true),
            $message ?: 'Must be one of: ' . implode(', ', array_map('strval', $allowed)) . '.',
        );
    }

    /**
     * Accepts strings that FILTER_VALIDATE_URL accepts, whatever the scheme (see httpUrl).
     */
    public static function url(string $message = 'Must be a valid URL.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && filter_var($v, FILTER_VALIDATE_URL) !== false,
            $message,
        );
    }

    /**
     * Accepts an absolute URL whose scheme is http or https (case-insensitive).
     */
    public static function httpUrl(string $message = 'Must be a valid HTTP/HTTPS URL.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v)) {
                    return false;
                }

                if (filter_var($v, FILTER_VALIDATE_URL) === false) {
                    return false;
                }

                $scheme = parse_url($v, PHP_URL_SCHEME);

                return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
            },
            $message,
        );
    }

    /**
     * Accepts strings with at least one non-whitespace character; non-strings fail.
     */
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
     * Accepts any UUID shape (8-4-4-4-12 hex, either case); the version and variant are not checked.
     */
    public static function uuid(string $message = 'Must be a valid UUID.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD',
                $v,
            ) === 1,
            $message,
        );
    }

    /**
     * Accepts a string that round-trips through $format, so 2026-02-30 is refused; NUL bytes fail.
     */
    public static function date(string $format = 'Y-m-d', string $message = ''): Rule
    {
        return Rule::of(
            function (mixed $v) use ($format): bool {
                if (!is_string($v) || str_contains($v, "\0")) {
                    return false;
                }
                $dt = \DateTime::createFromFormat($format, $v);
                return $dt !== false && $dt->format($format) === $v;
            },
            $message ?: "Must be a valid date in {$format} format.",
        );
    }

    /**
     * Accepts a string that round-trips through $format; NUL bytes fail.
     */
    public static function dateTime(string $format = 'Y-m-d H:i:s', string $message = ''): Rule
    {
        return Rule::of(
            function (mixed $v) use ($format): bool {
                if (!is_string($v) || str_contains($v, "\0")) {
                    return false;
                }

                $dt = \DateTime::createFromFormat($format, $v);

                return $dt !== false && $dt->format($format) === $v;
            },
            $message ?: "Must be a valid datetime in {$format} format.",
        );
    }

    /**
     * Accepts a whole-second RFC 3339 datetime with a 'Z' or numeric offset (no fractions, upper-case 'T' and 'Z').
     */
    public static function rfc3339DateTime(string $message = 'Must be a valid RFC 3339 datetime.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v)) {
                    return false;
                }

                if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $v) !== 1) {
                    return false;
                }

                $normalized = str_ends_with($v, 'Z')
                    ? substr($v, 0, -1) . '+00:00'
                    : $v;

                $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $normalized);

                return $dt !== false && $dt->format(\DateTimeInterface::RFC3339) === $normalized;
            },
            $message,
        );
    }

    /**
     * Accepts identifiers listed by DateTimeZone::listIdentifiers(), case-sensitive; the empty string fails.
     * Backward-compatible aliases such as America/Montreal are refused.
     */
    public static function timezone(string $message = 'Must be a valid timezone identifier.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v) || $v === '') {
                    return false;
                }

                return in_array($v, \DateTimeZone::listIdentifiers(), true);
            },
            $message,
        );
    }

    /**
     * Accepts HH:MM from 00:00 to 23:59; seconds are refused.
     */
    public static function time24(string $message = 'Must be a valid 24-hour time (HH:MM).'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $v) === 1,
            $message,
        );
    }

    /**
     * Accepts a '+' followed by 2 to 15 digits, the first one non-zero.
     */
    public static function phoneE164(string $message = 'Must be a valid E.164 phone number.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^\+[1-9]\d{1,14}$/D', $v) === 1,
            $message,
        );
    }

    /**
     * Accepts #RGB or #RRGGBB in either letter case; alpha forms such as #RGBA are refused.
     */
    public static function hexColor(string $message = 'Must be a valid hex color.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/D', $v) === 1,
            $message,
        );
    }

    /**
     * Accepts six hex pairs, each separated by ':' or '-', in either letter case.
     */
    public static function macAddress(string $message = 'Must be a valid MAC address.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v)
                && preg_match('/^(?:[0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/D', $v) === 1,
            $message,
        );
    }

    /**
     * Accepts five non-empty fields separated by spaces or tabs; field values are not range-checked.
     * Any other control character fails.
     */
    public static function cronExpression(string $message = 'Must be a valid cron expression.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v) || preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $v) === 1) {
                    return false;
                }

                $parts = preg_split('/[ \t]+/', trim($v, " \t"));

                return is_array($parts)
                    && count($parts) === 5
                    && array_reduce($parts, static fn (bool $ok, string $part): bool => $ok && $part !== '', true);
            },
            $message,
        );
    }

    /**
     * Accepts 3 to 13 ASCII letters, digits, spaces or hyphens, starting and ending with a letter or digit.
     */
    public static function postalCode(string $message = 'Must be a valid postal code.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^[A-Za-z0-9][A-Za-z0-9\- ]{1,11}[A-Za-z0-9]$/D', $v) === 1,
            $message,
        );
    }

    /**
     * Accepts arrays with at least $min items; non-arrays fail.
     */
    public static function countMin(int $min, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_array($v) && count($v) >= $min,
            $message ?: "Must have at least {$min} item(s).",
        );
    }

    /**
     * Accepts arrays with at most $max items; non-arrays fail.
     */
    public static function countMax(int $max, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_array($v) && count($v) <= $max,
            $message ?: "Must have at most {$max} item(s).",
        );
    }

    /**
     * Accepts an IPv4 or IPv6 address (FILTER_VALIDATE_IP); CIDR notation is refused.
     */
    public static function ip(string $message = 'Must be a valid IP address.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && filter_var($v, FILTER_VALIDATE_IP) !== false,
            $message,
        );
    }

    /**
     * Accepts a valid IPv4 address.
     */
    public static function ipv4(string $message = 'Must be a valid IPv4 address.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
            $message,
        );
    }

    /**
     * Accepts a valid IPv6 address.
     */
    public static function ipv6(string $message = 'Must be a valid IPv6 address.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false,
            $message,
        );
    }

    /**
     * Accepts a DNS name of at most 253 characters made of labels of 1 to 63 characters (letters, digits and inner hyphens).
     * Surrounding spaces and tabs are trimmed and the name lowercased before the check; a trailing dot is refused.
     */
    public static function hostname(string $message = 'Must be a valid hostname.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v)) {
                    return false;
                }

                $host = strtolower(trim($v, " \t"));
                if ($host === '' || strlen($host) > 253 || str_starts_with($host, '.') || str_ends_with($host, '.')) {
                    return false;
                }

                $labels = explode('.', $host);
                foreach ($labels as $label) {
                    if ($label === '' || strlen($label) > 63) {
                        return false;
                    }

                    if (preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $label) !== 1) {
                        return false;
                    }
                }

                return true;
            },
            $message,
        );
    }

    /**
     * Accepts IPv4 or IPv6 CIDR notation (10.0.0.0/8, 2001:db8::/32).
     * Bits set after the prefix are refused (10.1.0.0/8), as are IPv6 prefixes shorter than /96 that embed an IPv4 address.
     */
    public static function cidr(string $message = 'Must be a valid CIDR block.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                return is_string($v) && str_contains($v, '/') && IpRange::isValid($v);
            },
            $message,
        );
    }

    /**
     * Accepts an int or a digit string from 1 to 65535; leading zeros pass, signs and spaces fail.
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
     * Accepts a hostname or an IP address (see hostname and ip).
     */
    public static function host(string $message = 'Must be a valid host.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => self::hostname()->test($v) || self::ip()->test($v),
            $message,
        );
    }

    /**
     * Accepts an IP address in a private or reserved range, including loopback and link-local.
     * This is the complement of publicIp among valid addresses.
     */
    public static function privateIp(string $message = 'Must be a valid private IP address.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v) || filter_var($v, FILTER_VALIDATE_IP) === false) {
                    return false;
                }

                return filter_var(
                    $v,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
                ) === false;
            },
            $message,
        );
    }

    /**
     * Accepts a valid IP address outside the private and reserved ranges; non-strings fail.
     */
    public static function publicIp(string $message = 'Must be a valid public IP address.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v)
                && filter_var(
                    $v,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
                ) !== false,
            $message,
        );
    }

    /**
     * Accepts an IPv4 dotted mask of contiguous leading ones (255.255.255.0, 0.0.0.0); IPv6 is refused.
     */
    public static function subnetMask(string $message = 'Must be a valid subnet mask.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v) || filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    return false;
                }

                $long = ip2long($v);
                if ($long === false) {
                    return false;
                }

                $mask = sprintf('%032b', $long);

                return preg_match('/^1*0*$/D', $mask) === 1;
            },
            $message,
        );
    }

    /**
     * Accepts 'start-end' where both ends pass port() and start is less than or equal to end.
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
     * Accepts any text json_decode parses, scalars included ('null' passes); the empty string fails.
     * At most 511 nested arrays or objects pass (json_decode's default depth of 512).
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
     * Accepts JSON object text ({...}); arrays, scalars and invalid JSON fail.
     */
    public static function jsonObject(string $message = 'Must be a valid JSON object.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v) || $v === '') {
                    return false;
                }

                $decoded = json_decode($v);

                return json_last_error() === JSON_ERROR_NONE && is_object($decoded);
            },
            $message,
        );
    }

    /**
     * Accepts JSON array text ([...]); objects, scalars and invalid JSON fail.
     */
    public static function jsonArray(string $message = 'Must be a valid JSON array.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v) || $v === '') {
                    return false;
                }

                $decoded = json_decode($v);

                return json_last_error() === JSON_ERROR_NONE && is_array($decoded);
            },
            $message,
        );
    }

    /**
     * Accepts lowercase ASCII letters and digits in runs joined by single hyphens; the empty string fails.
     */
    public static function slug(string $message = 'Must be a valid slug.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $v) === 1,
            $message,
        );
    }

    /**
     * Accepts strings unchanged by mb_strtolower (multibyte-aware); the empty string passes.
     */
    public static function lowercase(string $message = 'Must be lowercase.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && mb_strtolower($v) === $v,
            $message,
        );
    }

    /**
     * Accepts strings unchanged by mb_strtoupper (multibyte-aware); the empty string passes.
     */
    public static function uppercase(string $message = 'Must be uppercase.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && mb_strtoupper($v) === $v,
            $message,
        );
    }

    /**
     * Accepts a non-empty string without ASCII whitespace; the empty string fails.
     */
    public static function noWhitespace(string $message = 'Must not contain whitespace.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^\S+$/D', $v) === 1,
            $message,
        );
    }


    /**
     * Accepts canonical padded Base64: whitespace and non-canonical padding fail; the empty string fails.
     */
    public static function base64(string $message = 'Must be valid Base64.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v) || $v === '') {
                    return false;
                }

                $decoded = base64_decode($v, true);
                if ($decoded === false) {
                    return false;
                }

                return base64_encode($decoded) === $v;
            },
            $message,
        );
    }

    /**
     * Accepts unpadded Base64URL (the '-' and '_' alphabet) that re-encodes to the same string; the empty string fails.
     */
    public static function base64Url(string $message = 'Must be valid Base64URL.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v) || $v === '') {
                    return false;
                }

                if (preg_match('/^[A-Za-z0-9\-_]+$/D', $v) !== 1) {
                    return false;
                }

                $normalized = strtr($v, '-_', '+/');
                $padding = strlen($normalized) % 4;
                if ($padding > 0) {
                    $normalized .= str_repeat('=', 4 - $padding);
                }

                $decoded = base64_decode($normalized, true);

                if ($decoded === false) {
                    return false;
                }

                return rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') === $v;
            },
            $message,
        );
    }

    /**
     * Accepts SemVer 2.0 versions: no leading 'v'.
     * No leading zeros in major, minor or patch; pre-release identifiers are not checked for them.
     */
    public static function semver(string $message = 'Must be a valid semantic version.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match(
                '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)'
                . '(?:-((?:0|[1-9]\d*|[0-9A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|[0-9A-Za-z-][0-9A-Za-z-]*))*))?'
                . '(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/D',
                $v,
            ) === 1,
            $message,
        );
    }

    /**
     * Accepts 26 upper-case Crockford Base32 characters (no I, L, O or U).
     */
    public static function ulid(string $message = 'Must be a valid ULID.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $v) === 1,
            $message,
        );
    }

    /**
     * Accepts 64 lower-case hexadecimal characters.
     */
    public static function sha256(string $message = 'Must be a valid SHA-256 hash.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^[a-f0-9]{64}$/D', $v) === 1,
            $message,
        );
    }

    /**
     * Accepts a string with a single leading '/' (not '//'), no backslash, no space and no ASCII control character:
     * safe as a local redirect target. Dot segments are not rejected: do not use the result as a filesystem path.
     *
     * @see Response::isLocalPath()
     */
    public static function httpPath(string $message = 'Must be a valid HTTP path.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v)
                && !str_contains($v, ' ')
                && Response::isLocalPath($v),
            $message,
        );
    }

    /**
     * Accepts one segment of letters, digits, '.', '_' or '-'; '.', '..', slashes and backslashes fail.
     */
    public static function pathSegment(string $message = 'Must be a valid path segment.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v)
                && $v !== ''
                && !str_contains($v, '/')
                && !str_contains($v, "\\")
                && $v !== '.'
                && $v !== '..'
                && preg_match('/^[A-Za-z0-9._-]+$/D', $v) === 1,
            $message,
        );
    }

    /**
     * Accepts up to 255 characters of letters, digits, '.', '_' or '-', starting with a letter or digit; any '..' fails.
     */
    public static function safeFilename(string $message = 'Must be a safe filename.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v)
                && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,254}$/D', $v) === 1
                && !str_contains($v, '..'),
            $message,
        );
    }

    /**
     * Accepts 1 to 10 ASCII letters or digits, without the leading dot.
     */
    public static function fileExtension(string $message = 'Must be a valid file extension.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v) && preg_match('/^[A-Za-z0-9]{1,10}$/D', $v) === 1,
            $message,
        );
    }

    /**
     * Accepts a query string without the leading '?'; the empty string passes.
     * '#', spaces and control characters fail, and the string must parse to at least one key.
     */
    public static function queryString(string $message = 'Must be a valid query string.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v)) {
                    return false;
                }

                if ($v === '') {
                    return true;
                }

                if (str_starts_with($v, '?') || preg_match('/[#\x00-\x20\x7F]/', $v) === 1) {
                    return false;
                }

                parse_str($v, $parsed);

                return $parsed !== [];
            },
            $message,
        );
    }

    /**
     * Accepts a non-empty string of unreserved characters and %XX escapes only.
     */
    public static function percentEncoded(string $message = 'Must be a valid percent-encoded string.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v) || $v === '') {
                    return false;
                }

                return preg_match('/^(?:%[0-9A-Fa-f]{2}|[A-Za-z0-9\-._~])*$/D', $v) === 1;
            },
            $message,
        );
    }

    /**
     * Accepts an int or digit string from 100 to 599.
     */
    public static function httpStatusCode(string $message = 'Must be a valid HTTP status code.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (is_string($v) && ctype_digit($v)) {
                    $v = (int) $v;
                }

                return is_int($v) && $v >= 100 && $v <= 599;
            },
            $message,
        );
    }

    /**
     * Accepts a standard HTTP method name in any letter case.
     */
    public static function httpMethod(string $message = 'Must be a valid HTTP method.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v) || $v === '') {
                    return false;
                }

                $method = strtoupper($v);

                return in_array($method, [
                    'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', 'TRACE', 'CONNECT',
                ], true);
            },
            $message,
        );
    }

    /**
     * Accepts an HTTP version token such as HTTP/1.1 (case-insensitive): 1.0, 1.1, 2, 2.0, 3, 3.0.
     */
    public static function httpVersion(string $message = 'Must be a valid HTTP version.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v)
                && preg_match('/^HTTP\/(?:1\.0|1\.1|2(?:\.0)?|3(?:\.0)?)$/iD', $v) === 1,
            $message,
        );
    }

    /**
     * Accepts type/subtype tokens in any letter case; parameters such as '; charset=' fail.
     */
    public static function mimeType(string $message = 'Must be a valid MIME type.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^[a-z0-9!#$&^_.+-]+\/[a-z0-9!#$&^_.+-]+$/iD', $v) === 1,
            $message,
        );
    }

    /**
     * Accepts a non-empty RFC 6750 b64token (letters, digits, -._~+/) with optional trailing '=' padding.
     * The 'Bearer ' prefix fails.
     */
    public static function bearerToken(string $message = 'Must be a valid bearer token.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^[A-Za-z0-9\-._~+\/]+=*$/D', $v) === 1,
            $message,
        );
    }

    /**
     * Accepts a basic BCP-47 tag such as en or en-CA, in either letter case; the registry is not consulted.
     */
    public static function languageTag(string $message = 'Must be a valid language tag.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D', $v) === 1,
            $message,
        );
    }

    /**
     * Accepts a comma-separated list of language ranges with optional q-values and '*'; blank values fail.
     */
    public static function acceptLanguage(string $message = 'Must be a valid Accept-Language header value.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v) || trim($v, " \t") === '') {
                    return false;
                }

                $part = '[A-Za-z]{1,8}(?:-[A-Za-z0-9]{1,8})*|\*';
                $q = '(?:0(?:\.\d{1,3})?|1(?:\.0{1,3})?)';

                return preg_match('/^[ \t]*(?:' . $part . ')(?:[ \t]*;[ \t]*q=' . $q . ')?(?:[ \t]*,[ \t]*(?:' . $part . ')(?:[ \t]*;[ \t]*q=' . $q . ')?)*[ \t]*$/D', $v) === 1;
            },
            $message,
        );
    }

    /**
     * Accepts an HTTP header name token (RFC 7230 token charset).
     */
    public static function httpHeaderName(string $message = 'Must be a valid HTTP header name.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^[!#$%&\'\*+\-.\^_`\|~0-9A-Za-z]+$/D', $v) === 1,
            $message,
        );
    }

    /**
     * Accepts visible ASCII, spaces and tabs; the empty string passes.
     * CR, LF and other control bytes fail.
     */
    public static function httpHeaderValue(string $message = 'Must be a valid HTTP header value.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^[\x09\x20-\x7E]*$/D', $v) === 1,
            $message,
        );
    }

    /**
     * Accepts header.payload.signature in base64url characters; the signature may be empty (unsigned tokens).
     * The signature is not verified.
     */
    public static function jwt(string $message = 'Must be a valid JWT token format.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v)
                && preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*$/D', $v) === 1,
            $message,
        );
    }

    /**
     * Accepts host:port, with IPv6 hosts in brackets: [ipv6]:port.
     */
    public static function hostPort(string $message = 'Must be a valid host:port endpoint.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v) || $v === '') {
                    return false;
                }

                if (str_starts_with($v, '[')) {
                    if (!preg_match('/^\[(.+)]:(\d+)$/D', $v, $matches)) {
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

    /**
     * Accepts two ASCII letters, either case; the code is not checked against the ISO 3166-1 list.
     */
    public static function countryCode(string $message = 'Must be a valid ISO country code.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v) && preg_match('/^[A-Z]{2}$/D', strtoupper($v)) === 1,
            $message,
        );
    }

    /**
     * Accepts language_REGION with a lower-case language and an upper-case region (en_CA).
     */
    public static function locale(string $message = 'Must be a valid locale (e.g. en_CA).'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v) && preg_match('/^[a-z]{2}_[A-Z]{2}$/D', $v) === 1,
            $message,
        );
    }

    /**
     * Accepts UUID versions 1-5.
     */
    public static function uuidV1toV5(string $message = 'Must be a valid UUID v1-v5.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v)
                && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $v) === 1,
            $message,
        );
    }

    /**
     * Accepts UUID version 4.
     */
    public static function uuidV4(string $message = 'Must be a valid UUID v4.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v)
                && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $v) === 1,
            $message,
        );
    }

    /**
     * Accepts UUID version 6 (RFC 9562): version nibble must be 6,
     * variant nibble must be 8, 9, a, or b (RFC 4122 variant).
     */
    public static function uuidV6(string $message = 'Must be a valid UUID v6.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v)
                && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-6[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $v) === 1,
            $message,
        );
    }

    /**
     * Accepts UUID version 7 (RFC 9562): version nibble must be 7,
     * variant nibble must be 8, 9, a, or b (RFC 4122 variant).
     */
    public static function uuidV7(string $message = 'Must be a valid UUID v7.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v)
                && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $v) === 1,
            $message,
        );
    }

    /**
     * Accepts UUID version 8 (RFC 9562): version nibble must be 8,
     * variant nibble must be 8, 9, a, or b (RFC 4122 variant).
     */
    public static function uuidV8(string $message = 'Must be a valid UUID v8.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v)
                && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-8[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $v) === 1,
            $message,
        );
    }

    /**
     * Accepts three ASCII letters, either case; the code is not checked against the ISO 4217 list.
     */
    public static function currencyCode(string $message = 'Must be a valid ISO currency code.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v) && preg_match('/^[A-Z]{3}$/D', strtoupper($v)) === 1,
            $message,
        );
    }

    /**
     * Accepts IBAN shape (2 letters, 2 digits, then 10 to 30 alphanumerics), spaces ignored, either case.
     * The mod-97 checksum is not verified.
     */
    public static function iban(string $message = 'Must be a valid IBAN format.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v)
                && preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/D', strtoupper(str_replace(' ', '', $v))) === 1,
            $message,
        );
    }

    /**
     * Accepts an 8 or 11 character BIC shape, either case; the bank registry is not consulted.
     */
    public static function bic(string $message = 'Must be a valid BIC/SWIFT code.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v)
                && preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}(?:[A-Z0-9]{3})?$/D', strtoupper($v)) === 1,
            $message,
        );
    }

    /**
     * Accepts 12 to 19 digits once whitespace and hyphens are removed; no Luhn check (see cardNumberLuhn).
     */
    public static function cardNumber(string $message = 'Must be a valid card number format.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v) || $v === '') {
                    return false;
                }

                $digits = preg_replace('/[\s-]+/', '', $v);
                if (!is_string($digits)) {
                    return false;
                }

                return preg_match('/^\d{12,19}$/D', $digits) === 1;
            },
            $message,
        );
    }

    /**
     * Accepts a card number shape (12 to 19 digits, whitespace and hyphens removed) that passes the Luhn checksum.
     */
    public static function cardNumberLuhn(string $message = 'Must be a valid card number.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v) || $v === '') {
                    return false;
                }

                $digits = preg_replace('/[\s-]+/', '', $v);
                if (!is_string($digits) || preg_match('/^\d{12,19}$/D', $digits) !== 1) {
                    return false;
                }

                $sum = 0;
                $alt = false;
                for ($i = strlen($digits) - 1; $i >= 0; --$i) {
                    $n = (int) $digits[$i];
                    if ($alt) {
                        $n *= 2;
                        if ($n > 9) {
                            $n -= 9;
                        }
                    }
                    $sum += $n;
                    $alt = !$alt;
                }

                return ($sum % 10) === 0;
            },
            $message,
        );
    }

    /**
     * Accepts a string of 3 or 4 digits; integers fail, so leading zeros survive.
     */
    public static function cardCvv(string $message = 'Must be a valid card CVV.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v) && preg_match('/^\d{3,4}$/D', $v) === 1,
            $message,
        );
    }

    /**
     * Accepts MM/YY with a month from 01 to 12; the date is not compared with today.
     */
    public static function cardExpiryMmyy(string $message = 'Must be a valid card expiry (MM/YY).'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v) && preg_match('/^(0[1-9]|1[0-2])\/\d{2}$/D', $v) === 1,
            $message,
        );
    }

    /**
     * Accepts MM/YYYY with a month from 01 to 12; the date is not compared with today.
     */
    public static function cardExpiryMmyyyy(string $message = 'Must be a valid card expiry (MM/YYYY).'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v) && preg_match('/^(0[1-9]|1[0-2])\/\d{4}$/D', $v) === 1,
            $message,
        );
    }

    /**
     * Accepts strings with at least one non-whitespace character (same check as notBlank).
     */
    public static function nonEmptyString(string $message = 'Must be a non-empty string.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v) && trim($v) !== '',
            $message,
        );
    }

    /**
     * Accepts strings equal to one of $values ignoring case (mb_strtolower); non-strings fail.
     *
     * @param array<int, string> $values
     */
    public static function inCaseInsensitive(array $values, string $message = 'Must be one of the allowed values.'): Rule
    {
        $normalized = array_map(static fn (string $v): string => mb_strtolower($v), $values);

        return Rule::of(
            static fn (mixed $v): bool => is_string($v) && in_array(mb_strtolower($v), $normalized, true),
            $message,
        );
    }

    /**
     * Accepts an RFC 6901 pointer: the empty string (whole document) or a '/'-prefixed path using only ~0 and ~1 escapes.
     */
    public static function jsonPointer(string $message = 'Must be a valid JSON Pointer.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v)) {
                    return false;
                }

                if ($v === '') {
                    return true;
                }

                if (!str_starts_with($v, '/')) {
                    return false;
                }

                return preg_match('/^(?:\/(?:[^~\/]|~0|~1)*)+$/D', $v) === 1;
            },
            $message,
        );
    }

    /**
     * Accepts a number or numeric string from -90 to 90 inclusive.
     */
    public static function latitude(string $message = 'Must be a valid latitude.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (is_string($v) && is_numeric($v)) {
                    $v = (float) $v;
                }

                return (is_float($v) || is_int($v)) && $v >= -90 && $v <= 90;
            },
            $message,
        );
    }

    /**
     * Accepts a number or numeric string from -180 to 180 inclusive.
     */
    public static function longitude(string $message = 'Must be a valid longitude.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (is_string($v) && is_numeric($v)) {
                    $v = (float) $v;
                }

                return (is_float($v) || is_int($v)) && $v >= -180 && $v <= 180;
            },
            $message,
        );
    }

    /**
     * Accepts a non-negative whole number of seconds (int or digit string); signs and decimals fail.
     */
    public static function unixTimestamp(string $message = 'Must be a valid Unix timestamp.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (is_string($v) && ctype_digit($v)) {
                    $v = (int) $v;
                }

                return is_int($v) && $v >= 0;
            },
            $message,
        );
    }

    /**
     * Accepts a non-negative whole number of milliseconds (int or digit string); signs and decimals fail.
     */
    public static function epochMilliseconds(string $message = 'Must be a valid epoch-milliseconds value.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (is_string($v) && ctype_digit($v)) {
                    $v = (int) $v;
                }

                return is_int($v) && $v >= 0;
            },
            $message,
        );
    }

    /**
     * Accepts a strong ETag ("abc") or weak ETag (W/"abc"); the opaque tag may be empty.
     */
    public static function etag(string $message = 'Must be a valid HTTP ETag.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^(?:W\/)?"[\x21\x23-\x7E]*"$/D', $v) === 1,
            $message,
        );
    }

    /**
     * Accepts '*' or a comma-separated list of ETags; an empty list item fails.
     */
    public static function ifNoneMatch(string $message = 'Must be a valid If-None-Match header.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => self::isEtagListOrWildcard($v),
            $message,
        );
    }

    /**
     * Accepts '*' or a comma-separated list of ETags; an empty list item fails.
     */
    public static function ifMatch(string $message = 'Must be a valid If-Match header.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => self::isEtagListOrWildcard($v),
            $message,
        );
    }

    private static function isEtagListOrWildcard(mixed $v): bool
    {
        if (!is_string($v) || $v === '') {
            return false;
        }

        if ($v === '*') {
            return true;
        }

        $etag = self::etag();
        foreach (explode(',', $v) as $item) {
            if (!$etag->test(trim($item, " \t"))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Accepts an HTTP-date (IMF-fixdate, e.g. Mon, 23 Feb 2026 20:31:00 GMT).
     */
    public static function httpDate(string $message = 'Must be a valid HTTP date.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v) || $v === '' || str_contains($v, "\0")) {
                    return false;
                }

                $date = \DateTimeImmutable::createFromFormat(
                    'D, d M Y H:i:s \\G\\M\\T',
                    $v,
                    new \DateTimeZone('GMT'),
                );

                return $date !== false && $date->format('D, d M Y H:i:s \\G\\M\\T') === $v;
            },
            $message,
        );
    }

    /**
     * Accepts an If-Modified-Since header value.
     */
    public static function ifModifiedSince(string $message = 'Must be a valid If-Modified-Since header.'): Rule
    {
        return self::httpDate($message);
    }

    /**
     * Accepts an If-Unmodified-Since header value.
     */
    public static function ifUnmodifiedSince(string $message = 'Must be a valid If-Unmodified-Since header.'): Rule
    {
        return self::httpDate($message);
    }

    /**
     * Accepts an If-Range header value (HTTP-date or single ETag).
     */
    public static function ifRange(string $message = 'Must be a valid If-Range header.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => self::httpDate()->test($v) || self::etag()->test($v),
            $message,
        );
    }

    /**
     * Accepts 'bytes=' followed by comma-separated 'first-last', 'first-' or '-suffix' ranges.
     * Checks the syntax and first <= last only, not the resource size.
     */
    public static function byteRange(string $message = 'Must be a valid byte range header.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v) || !str_starts_with($v, 'bytes=')) {
                    return false;
                }

                foreach (explode(',', substr($v, 6)) as $range) {
                    $range = trim($range, " \t");

                    if (preg_match('/^(\d+)-(\d+)$/D', $range, $m) === 1) {
                        if ((int) $m[1] > (int) $m[2]) {
                            return false;
                        }
                        continue;
                    }

                    if (preg_match('/^\d+-$/D', $range) === 1) {
                        continue;
                    }

                    if (preg_match('/^-\d+$/D', $range) === 1) {
                        continue;
                    }

                    return false;
                }

                return true;
            },
            $message,
        );
    }

    /**
     * Accepts 'bytes first-last/size' (size may be '*') or the unsatisfied form 'bytes *' with '/size'.
     * Checks first <= last and last < size, not the resource size.
     */
    public static function contentRange(string $message = 'Must be a valid Content-Range header.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v) || !str_starts_with($v, 'bytes ')) {
                    return false;
                }

                $value = substr($v, 6);

                if (preg_match('/^\*\/\d+$/D', $value) === 1) {
                    return true;
                }

                if (preg_match('/^(\d+)-(\d+)\/(\d+|\*)$/D', $value, $m) !== 1) {
                    return false;
                }

                $start = (int) $m[1];
                $end = (int) $m[2];
                if ($start > $end) {
                    return false;
                }

                if ($m[3] === '*') {
                    return true;
                }

                $size = (int) $m[3];

                return $size > 0 && $start < $size && $end < $size;
            },
            $message,
        );
    }

    private function __construct() {}
}
