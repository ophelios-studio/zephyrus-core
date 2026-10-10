<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use InvalidArgumentException;
use Zephyrus\Core\Config\ConfigKeys;
use Zephyrus\Core\Config\ConfigurationException;
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
    /**
     * Characters trimmed from both ends of a header value.
     *
     * @internal
     */
    public const string TRIMMED_CHARACTERS = " \t\r\n";

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
     * Each key takes its camelCase or snake_case spelling, never both.
     * Header values are trimmed of surrounding spaces, tabs, CR and LF; a blank one is read as empty, which means
     * not emitted.
     *
     * @param array<string, mixed> $values
     * @throws ConfigurationException when a key is unknown or written in both spellings, a header value is null,
     *         not a string or number, or holds a control character other than a horizontal tab after trimming,
     *         hstsMaxAge is not an integer or a string of digits, or hstsIncludeSubdomains is not a recognisable
     *         boolean.
     */
    public static function fromArray(array $values): self
    {
        $keys = ConfigKeys::read('security.headers', $values, self::SPELLINGS);
        $defaults = self::defaults();

        return new self(
            xFrameOptions: self::text($keys, 'xFrameOptions', $defaults->xFrameOptions),
            xContentTypeOptions: self::text($keys, 'xContentTypeOptions', $defaults->xContentTypeOptions),
            referrerPolicy: self::text($keys, 'referrerPolicy', $defaults->referrerPolicy),
            xssProtection: self::text($keys, 'xssProtection', $defaults->xssProtection),
            hstsMaxAge: self::hstsMaxAge($keys, $defaults->hstsMaxAge),
            hstsIncludeSubdomains: $keys->boolean('hstsIncludeSubdomains', $defaults->hstsIncludeSubdomains),
            csp: self::text($keys, 'csp', $defaults->csp),
            permissionsPolicy: self::text($keys, 'permissionsPolicy', $defaults->permissionsPolicy),
        );
    }

    /**
     * @throws ConfigurationException
     */
    private static function text(ConfigKeys $keys, string $property, string $default): string
    {
        if (!$keys->has($property)) {
            return $default;
        }

        $key = $keys->key($property);
        $value = $keys->value($property);

        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw ConfigurationException::invalidValue(
                'security.headers',
                $key,
                $value,
                'must be a string or a number'
                . ($value === null || $value === false ? "; use '' to omit the header" : ''),
            );
        }

        $text = trim((string) $value, self::TRIMMED_CHARACTERS);

        if ($text === '') {
            return '';
        }

        if (!Response::isValidHeaderValue($text)) {
            throw ConfigurationException::invalidValue(
                'security.headers',
                $key,
                $text,
                'must not contain a control character'
                . ($property === 'csp' && strpbrk($text, "\r\n") !== false
                    ? '; write the policy on one line or use a folded block (>)'
                    : ''),
            );
        }

        return $text;
    }

    /**
     * @throws ConfigurationException
     */
    private static function hstsMaxAge(ConfigKeys $keys, int $default): int
    {
        if (!$keys->has('hstsMaxAge')) {
            return $default;
        }

        $key = $keys->key('hstsMaxAge');
        $value = $keys->value('hstsMaxAge');

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
            $value,
            'must be a whole number of seconds, or a string of digits',
        );
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
