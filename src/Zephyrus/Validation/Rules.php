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

    public static function integerString(string $message = 'Must be an integer string.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^-?\d+$/', $v) === 1,
            $message,
        );
    }

    public static function decimalString(int $scale = 2, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v)
                && preg_match('/^-?\d+(?:\.\d{1,' . max(1, $scale) . '})?$/', $v) === 1,
            $message ?: "Must be a decimal string with up to {$scale} decimal places.",
        );
    }

    public static function min(int|float $min, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_numeric($v) && (float) $v >= $min,
            $message ?: "Must be at least {$min}.",
        );
    }

    public static function greaterThan(int|float $min, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_numeric($v) && (float) $v > $min,
            $message ?: "Must be greater than {$min}.",
        );
    }

    public static function max(int|float $max, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_numeric($v) && (float) $v <= $max,
            $message ?: "Must be at most {$max}.",
        );
    }

    public static function lessThan(int|float $max, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_numeric($v) && (float) $v < $max,
            $message ?: "Must be less than {$max}.",
        );
    }

    public static function between(int|float $min, int|float $max, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_numeric($v) && (float) $v >= $min && (float) $v <= $max,
            $message ?: "Must be between {$min} and {$max}.",
        );
    }

    public static function betweenExclusive(int|float $min, int|float $max, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_numeric($v) && (float) $v > $min && (float) $v < $max,
            $message ?: "Must be strictly between {$min} and {$max}.",
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
     * Validates that a string contains only ASCII characters.
     */
    public static function ascii(string $message = 'Must contain only ASCII characters.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^[\x00-\x7F]*$/', $v) === 1,
            $message,
        );
    }

    /**
     * Validates alphanumeric strings (letters and digits only).
     */
    public static function alphaNumeric(string $message = 'Must contain only letters and numbers.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^[a-zA-Z0-9]+$/', $v) === 1,
            $message,
        );
    }

    /**
     * Validates that a string starts with the provided prefix.
     */
    public static function startsWith(string $prefix, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && str_starts_with($v, $prefix),
            $message ?: "Must start with '{$prefix}'.",
        );
    }

    /**
     * Validates that a string ends with the provided suffix.
     */
    public static function endsWith(string $suffix, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && str_ends_with($v, $suffix),
            $message ?: "Must end with '{$suffix}'.",
        );
    }

    /**
     * Validates that a string contains the provided needle.
     */
    public static function contains(string $needle, string $message = ''): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && str_contains($v, $needle),
            $message ?: "Must contain '{$needle}'.",
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
     * Validates a datetime string against the given format (default Y-m-d H:i:s).
     */
    public static function dateTime(string $format = 'Y-m-d H:i:s', string $message = ''): Rule
    {
        return Rule::of(
            function (mixed $v) use ($format): bool {
                if (!is_string($v)) {
                    return false;
                }

                $dt = \DateTime::createFromFormat($format, $v);

                return $dt !== false && $dt->format($format) === $v;
            },
            $message ?: "Must be a valid datetime in {$format} format.",
        );
    }

    /**
     * Validates an IANA timezone identifier.
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
     * Validates 24-hour time strings (HH:MM).
     */
    public static function time24(string $message = 'Must be a valid 24-hour time (HH:MM).'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $v) === 1,
            $message,
        );
    }

    /**
     * Validates E.164 phone number format.
     */
    public static function phoneE164(string $message = 'Must be a valid E.164 phone number.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^\+[1-9]\d{1,14}$/', $v) === 1,
            $message,
        );
    }

    /**
     * Validates hexadecimal color values (#RGB or #RRGGBB).
     */
    public static function hexColor(string $message = 'Must be a valid hex color.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $v) === 1,
            $message,
        );
    }

    /**
     * Validates MAC address format.
     */
    public static function macAddress(string $message = 'Must be a valid MAC address.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v)
                && preg_match('/^(?:[0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/', $v) === 1,
            $message,
        );
    }

    /**
     * Validates cron expressions with 5 fields.
     */
    public static function cronExpression(string $message = 'Must be a valid cron expression.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v)) {
                    return false;
                }

                $parts = preg_split('/\s+/', trim($v));

                return is_array($parts)
                    && count($parts) === 5
                    && array_reduce($parts, static fn (bool $ok, string $part): bool => $ok && $part !== '', true);
            },
            $message,
        );
    }

    /**
     * Validates simple postal/ZIP code shapes (alphanumeric + space/hyphen).
     */
    public static function postalCode(string $message = 'Must be a valid postal code.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^[A-Za-z0-9][A-Za-z0-9\- ]{1,11}[A-Za-z0-9]$/', $v) === 1,
            $message,
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
     * Validates that a value is lowercase.
     */
    public static function lowercase(string $message = 'Must be lowercase.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && mb_strtolower($v) === $v,
            $message,
        );
    }

    /**
     * Validates that a value is uppercase.
     */
    public static function uppercase(string $message = 'Must be uppercase.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && mb_strtoupper($v) === $v,
            $message,
        );
    }

    /**
     * Validates that a value has no whitespace characters.
     */
    public static function noWhitespace(string $message = 'Must not contain whitespace.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^\S+$/', $v) === 1,
            $message,
        );
    }

    
    /**
     * Validates a Base64-encoded string.
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
     * Validates semantic version strings (SemVer 2.0 core + optional prerelease/build).
     */
    public static function semver(string $message = 'Must be a valid semantic version.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match(
                '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)'
                . '(?:-((?:0|[1-9]\d*|[0-9A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|[0-9A-Za-z-][0-9A-Za-z-]*))*))?'
                . '(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/',
                $v,
            ) === 1,
            $message,
        );
    }

    /**
     * Validates ULID strings (26 Crockford Base32 chars).
     */
    public static function ulid(string $message = 'Must be a valid ULID.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $v) === 1,
            $message,
        );
    }

    /**
     * Validates a lowercase hex-encoded SHA-256 digest.
     */
    public static function sha256(string $message = 'Must be a valid SHA-256 hash.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^[a-f0-9]{64}$/', $v) === 1,
            $message,
        );
    }

    /**
     * Validates an HTTP request path starting with '/'.
     */
    public static function httpPath(string $message = 'Must be a valid HTTP path.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v)
                && $v !== ''
                && str_starts_with($v, '/')
                && !str_contains($v, ' '),
            $message,
        );
    }

    /**
     * Validates a URL query string without the leading '?'.
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

                if (str_starts_with($v, '?') || str_contains($v, '#') || str_contains($v, ' ')) {
                    return false;
                }

                parse_str($v, $parsed);

                return $parsed !== [];
            },
            $message,
        );
    }

    /**
     * Validates a percent-encoded URL fragment/component.
     */
    public static function percentEncoded(string $message = 'Must be a valid percent-encoded string.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v) || $v === '') {
                    return false;
                }

                return preg_match('/^(?:%[0-9A-Fa-f]{2}|[A-Za-z0-9\-._~])*$/', $v) === 1;
            },
            $message,
        );
    }

    /**
     * Validates an HTTP status code (100-599).
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
     * Validates an HTTP method token.
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
     * Validates a MIME type such as "application/json".
     */
    public static function mimeType(string $message = 'Must be a valid MIME type.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^[a-z0-9!#$&^_.+-]+\/[a-z0-9!#$&^_.+-]+$/i', $v) === 1,
            $message,
        );
    }

    /**
     * Validates a Bearer token value (without the "Bearer " prefix).
     */
    public static function bearerToken(string $message = 'Must be a valid bearer token.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^[A-Za-z0-9\-._~+\/]+=*$/', $v) === 1,
            $message,
        );
    }

    /**
     * Validates an IETF BCP-47 language tag (basic form).
     */
    public static function languageTag(string $message = 'Must be a valid language tag.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $v) === 1,
            $message,
        );
    }

    /**
     * Validates an HTTP header name token (RFC 7230 token charset).
     */
    public static function httpHeaderName(string $message = 'Must be a valid HTTP header name.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^[!#$%&\'\*+\-.\^_`\|~0-9A-Za-z]+$/', $v) === 1,
            $message,
        );
    }

    /**
     * Validates an HTTP header value (printable visible ASCII + spaces/tabs).
     */
    public static function httpHeaderValue(string $message = 'Must be a valid HTTP header value.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^[\x09\x20-\x7E]*$/', $v) === 1,
            $message,
        );
    }

    /**
     * Validates JWT compact serialization (header.payload.signature).
     */
    public static function jwt(string $message = 'Must be a valid JWT token format.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v)
                && preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*$/', $v) === 1,
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

    /**
     * Validates ISO 3166-1 alpha-2 country codes.
     */
    public static function countryCode(string $message = 'Must be a valid ISO country code.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v) && preg_match('/^[A-Z]{2}$/', strtoupper($v)) === 1,
            $message,
        );
    }

    /**
     * Validates locale tags in language_REGION form (e.g. en_CA).
     */
    public static function locale(string $message = 'Must be a valid locale (e.g. en_CA).'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v) && preg_match('/^[a-z]{2}_[A-Z]{2}$/', $v) === 1,
            $message,
        );
    }

    /**
     * Validates UUID versions 1-5.
     */
    public static function uuidV1toV5(string $message = 'Must be a valid UUID v1-v5.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v)
                && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $v) === 1,
            $message,
        );
    }

    /**
     * Validates UUID version 4.
     */
    public static function uuidV4(string $message = 'Must be a valid UUID v4.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v)
                && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $v) === 1,
            $message,
        );
    }

    /**
     * Validates ISO 4217 currency codes.
     */
    public static function currencyCode(string $message = 'Must be a valid ISO currency code.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v) && preg_match('/^[A-Z]{3}$/', strtoupper($v)) === 1,
            $message,
        );
    }

    /**
     * Validates IBAN structure (basic format check).
     */
    public static function iban(string $message = 'Must be a valid IBAN format.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v)
                && preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/', strtoupper(str_replace(' ', '', $v))) === 1,
            $message,
        );
    }

    /**
     * Validates BIC / SWIFT code format.
     */
    public static function bic(string $message = 'Must be a valid BIC/SWIFT code.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v)
                && preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}(?:[A-Z0-9]{3})?$/', strtoupper($v)) === 1,
            $message,
        );
    }

    /**
     * Validates payment card number shape (12-19 digits, spaces/hyphens allowed).
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

                return preg_match('/^\d{12,19}$/', $digits) === 1;
            },
            $message,
        );
    }

    /**
     * Validates card number format and Luhn checksum.
     */
    public static function cardNumberLuhn(string $message = 'Must be a valid card number.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v) || $v === '') {
                    return false;
                }

                $digits = preg_replace('/[\s-]+/', '', $v);
                if (!is_string($digits) || preg_match('/^\d{12,19}$/', $digits) !== 1) {
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
     * Validates CVV/CVC shape (3 or 4 digits).
     */
    public static function cardCvv(string $message = 'Must be a valid card CVV.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v) && preg_match('/^\d{3,4}$/', $v) === 1,
            $message,
        );
    }

    /**
     * Validates card expiry in MM/YY format.
     */
    public static function cardExpiryMmyy(string $message = 'Must be a valid card expiry (MM/YY).'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v) && preg_match('/^(0[1-9]|1[0-2])\/\d{2}$/', $v) === 1,
            $message,
        );
    }

    /**
     * Validates card expiry in MM/YYYY format.
     */
    public static function cardExpiryMmyyyy(string $message = 'Must be a valid card expiry (MM/YYYY).'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v) && preg_match('/^(0[1-9]|1[0-2])\/\d{4}$/', $v) === 1,
            $message,
        );
    }

    /**
     * Validates non-empty, non-whitespace-only strings.
     */
    public static function nonEmptyString(string $message = 'Must be a non-empty string.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => is_string($v) && trim($v) !== '',
            $message,
        );
    }

    /**
     * Validates membership in a case-insensitive string allowlist.
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
     * Validates JSON Pointer format (RFC 6901 basic shape).
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

                return preg_match('/^(?:\/(?:[^~\/]|~0|~1)*)+$/', $v) === 1;
            },
            $message,
        );
    }

    /**
     * Validates latitude values in range [-90, 90].
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
     * Validates longitude values in range [-180, 180].
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
     * Validates Unix timestamps in seconds (non-negative integer).
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
     * Validates epoch milliseconds (non-negative integer).
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
     * Validates an HTTP ETag value (RFC 7232).
     * Accepts strong ETags ("abc") and weak ETags (W/"abc").
     * The opaque tag may be empty or any sequence of visible ASCII except '"'.
     */
    public static function etag(string $message = 'Must be a valid HTTP ETag.'): Rule
    {
        return Rule::of(
            fn (mixed $v) => is_string($v) && preg_match('/^(?:W\/)?"[\x21\x23-\x7E]*"$/', $v) === 1,
            $message,
        );
    }

    /**
     * Validates an If-None-Match header value (`*` or comma-separated ETags).
     */
    public static function ifNoneMatch(string $message = 'Must be a valid If-None-Match header.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v) || $v === '') {
                    return false;
                }

                if ($v === '*') {
                    return true;
                }

                $parts = array_map('trim', explode(',', $v));
                if ($parts === [] || in_array('', $parts, true)) {
                    return false;
                }

                $etag = self::etag();
                foreach ($parts as $part) {
                    if (!$etag->test($part)) {
                        return false;
                    }
                }

                return true;
            },
            $message,
        );
    }

    /**
     * Validates an If-Match header value (`*` or comma-separated ETags).
     */
    public static function ifMatch(string $message = 'Must be a valid If-Match header.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v) || $v === '') {
                    return false;
                }

                if ($v === '*') {
                    return true;
                }

                $parts = array_map('trim', explode(',', $v));
                if ($parts === [] || in_array('', $parts, true)) {
                    return false;
                }

                $etag = self::etag();
                foreach ($parts as $part) {
                    if (!$etag->test($part)) {
                        return false;
                    }
                }

                return true;
            },
            $message,
        );
    }

    /**
     * Validates an HTTP-date (IMF-fixdate, e.g. Mon, 23 Feb 2026 20:31:00 GMT).
     */
    public static function httpDate(string $message = 'Must be a valid HTTP date.'): Rule
    {
        return Rule::of(
            static function (mixed $v): bool {
                if (!is_string($v) || $v === '') {
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
     * Validates an If-Modified-Since header value.
     */
    public static function ifModifiedSince(string $message = 'Must be a valid If-Modified-Since header.'): Rule
    {
        return self::httpDate($message);
    }

    /**
     * Validates an If-Unmodified-Since header value.
     */
    public static function ifUnmodifiedSince(string $message = 'Must be a valid If-Unmodified-Since header.'): Rule
    {
        return self::httpDate($message);
    }

    /**
     * Validates an If-Range header value (HTTP-date or single ETag).
     */
    public static function ifRange(string $message = 'Must be a valid If-Range header.'): Rule
    {
        return Rule::of(
            static fn (mixed $v): bool => self::httpDate()->test($v) || self::etag()->test($v),
            $message,
        );
    }

    private function __construct() {}
}
