<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use InvalidArgumentException;
use Zephyrus\Core\Config\ConfigBoolean;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Http\IpRange;
use Zephyrus\Http\Response;

/**
 * Immutable configuration for the HTTP security response headers.
 *
 * Each property is one response header. An empty string means the header is not emitted.
 * HSTS is enabled by hstsMaxAge > 0, and only sent on HTTPS requests.
 *
 * Defaults:
 *   - X-Frame-Options: SAMEORIGIN
 *   - X-Content-Type-Options: nosniff
 *   - Referrer-Policy: strict-origin-when-cross-origin
 *   - X-XSS-Protection: 0 (the legacy auditor is off; it can be exploited)
 *   - HSTS: disabled (max-age must be set explicitly)
 *   - Content-Security-Policy: empty (no universal safe policy exists)
 *   - Permissions-Policy: empty (opt in per feature)
 */
final readonly class SecureHeadersConfig
{
    /** Accepted spellings per property, in order of preference; any other key is refused. */
    private const array SPELLINGS = [
        'xFrameOptions' => ['xFrameOptions', 'x_frame_options'],
        'xContentTypeOptions' => ['xContentTypeOptions', 'x_content_type_options'],
        'referrerPolicy' => ['referrerPolicy', 'referrer_policy'],
        'xssProtection' => ['xssProtection', 'xss_protection'],
        'hstsMaxAge' => ['hstsMaxAge', 'hsts_max_age'],
        'hstsIncludeSubdomains' => ['hstsIncludeSubdomains', 'hsts_include_subdomains'],
        'csp' => ['csp'],
        'permissionsPolicy' => ['permissionsPolicy', 'permissions_policy'],
    ];

    /**
     * @throws InvalidArgumentException When a header field holds a control character other than HTAB.
     */
    public function __construct(
        public string $xFrameOptions,
        public string $xContentTypeOptions,
        public string $referrerPolicy,
        public string $xssProtection,
        public int    $hstsMaxAge,
        public bool   $hstsIncludeSubdomains,
        public string $csp,
        public string $permissionsPolicy,
    ) {
        $headerFields = [
            'xFrameOptions' => $this->xFrameOptions,
            'xContentTypeOptions' => $this->xContentTypeOptions,
            'referrerPolicy' => $this->referrerPolicy,
            'xssProtection' => $this->xssProtection,
            'csp' => $this->csp,
            'permissionsPolicy' => $this->permissionsPolicy,
        ];

        foreach ($headerFields as $field => $value) {
            if (!Response::isValidHeaderValue($value)) {
                throw new InvalidArgumentException(sprintf(
                    'Invalid security header field "%s": it contains a control character other than HTAB.',
                    $field,
                ));
            }
        }
    }

    /**
     * Sensible security-header defaults for a new application.
     *
     * Use this as a starting point and layer overrides with fromArray().
     */
    public static function defaults(): self
    {
        return new self(
            xFrameOptions:        'SAMEORIGIN',
            xContentTypeOptions:  'nosniff',
            referrerPolicy:       'strict-origin-when-cross-origin',
            xssProtection:        '0',
            hstsMaxAge:           0,
            hstsIncludeSubdomains: false,
            csp:                  '',
            permissionsPolicy:    '',
        );
    }

    /**
     * Builds the config from a key-value array, falling back to defaults() for missing keys.
     *
     * Each key takes its camelCase or snake_case spelling; camelCase wins when both are set. A blank header
     * value is read as empty, which means not emitted.
     *
     * @param array<string, mixed> $values
     * @throws ConfigurationException when a key is unknown, a value is null or not a string or number, a header
     *         value contains a control character other than a horizontal tab, hstsMaxAge is not an integer or a
     *         string of digits, or hstsIncludeSubdomains is not a recognisable boolean.
     */
    public static function fromArray(array $values): self
    {
        self::assertKnownKeys($values);
        $defaults = self::defaults();

        return new self(
            xFrameOptions: self::text($values, 'xFrameOptions', $defaults->xFrameOptions),
            xContentTypeOptions: self::text($values, 'xContentTypeOptions', $defaults->xContentTypeOptions),
            referrerPolicy: self::text($values, 'referrerPolicy', $defaults->referrerPolicy),
            xssProtection: self::text($values, 'xssProtection', $defaults->xssProtection),
            hstsMaxAge: self::hstsMaxAge($values, $defaults->hstsMaxAge),
            hstsIncludeSubdomains: self::includeSubdomains($values, $defaults->hstsIncludeSubdomains),
            csp: self::text($values, 'csp', $defaults->csp),
            permissionsPolicy: self::text($values, 'permissionsPolicy', $defaults->permissionsPolicy),
        );
    }

    /**
     * @param array<string, mixed> $values
     * @throws ConfigurationException
     */
    private static function assertKnownKeys(array $values): void
    {
        foreach ($values as $key => $value) {
            if (!self::isKnownKey((string) $key)) {
                throw ConfigurationException::invalidValue(
                    'security.headers',
                    IpRange::shownEntry((string) $key),
                    self::shownValue($value),
                    'unknown key, the accepted keys are ' . implode(', ', array_keys(self::SPELLINGS)),
                );
            }
        }
    }

    private static function isKnownKey(string $key): bool
    {
        foreach (self::SPELLINGS as $spellings) {
            if (in_array($key, $spellings, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $values
     * @throws ConfigurationException
     */
    private static function includeSubdomains(array $values, bool $default): bool
    {
        $key = self::writtenKey($values, self::SPELLINGS['hstsIncludeSubdomains']);

        return $key === null ? $default : ConfigBoolean::parse('security.headers', $key, $values[$key]);
    }

    /**
     * @param array<string, mixed> $values
     * @throws ConfigurationException
     */
    private static function text(array $values, string $property, string $default): string
    {
        $key = self::writtenKey($values, self::SPELLINGS[$property]);

        if ($key === null) {
            return $default;
        }

        $value = $values[$key];

        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw ConfigurationException::invalidValue(
                'security.headers',
                $key,
                self::shownValue($value),
                'must be a string or a number'
                . ($value === null || $value === false ? "; use '' to omit the header" : ''),
            );
        }

        $text = trim((string) $value, " \t\r\n");

        if ($text === '') {
            return '';
        }

        if (!Response::isValidHeaderValue($text)) {
            throw ConfigurationException::invalidValue(
                'security.headers',
                $key,
                self::shownValue($text),
                'must not contain a control character',
            );
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $values
     * @throws ConfigurationException
     */
    private static function hstsMaxAge(array $values, int $default): int
    {
        $key = self::writtenKey($values, self::SPELLINGS['hstsMaxAge']);

        if ($key === null) {
            return $default;
        }

        $value = $values[$key];

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/\A[0-9]+\z/', $value) === 1) {
            $seconds = filter_var(ltrim($value, '0') ?: '0', FILTER_VALIDATE_INT);

            if ($seconds !== false) {
                return $seconds;
            }
        }

        throw ConfigurationException::invalidValue(
            'security.headers',
            $key,
            self::shownValue($value),
            'must be a whole number of seconds, or a string of digits',
        );
    }

    /**
     * The first spelling present in the array, whatever its value.
     *
     * @param array<string, mixed> $values
     * @param list<string>         $spellings
     */
    private static function writtenKey(array $values, array $spellings): ?string
    {
        foreach ($spellings as $spelling) {
            if (array_key_exists($spelling, $values)) {
                return $spelling;
            }
        }

        return null;
    }

    /** A value for a message: strings escaped and bounded, scalars as written, anything else by type. */
    private static function shownValue(mixed $value): string
    {
        return match (true) {
            is_string($value) => IpRange::shownEntry($value),
            is_bool($value) => $value ? 'true' : 'false',
            is_float($value) => var_export($value, true),
            is_scalar($value) => (string) $value,
            $value === null => 'null',
            default => get_debug_type($value),
        };
    }

    /** The Strict-Transport-Security value, or an empty string when hstsMaxAge is 0 or less. */
    public function hstsHeaderValue(): string
    {
        if ($this->hstsMaxAge <= 0) {
            return '';
        }

        $value = "max-age={$this->hstsMaxAge}";

        if ($this->hstsIncludeSubdomains) {
            $value .= '; includeSubDomains';
        }

        return $value;
    }
}
