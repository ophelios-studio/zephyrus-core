<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use Zephyrus\Core\Config\ConfigBoolean;
use Zephyrus\Core\Config\ConfigurationException;

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
     * Each key takes its camelCase or snake_case spelling; camelCase wins when both are set.
     *
     * @param array<string, mixed> $values
     * @throws ConfigurationException when hstsIncludeSubdomains is not a recognisable boolean.
     */
    public static function fromArray(array $values): self
    {
        $defaults = self::defaults();

        return new self(
            xFrameOptions: (string) (
                $values['xFrameOptions'] ?? $values['x_frame_options'] ?? $defaults->xFrameOptions
            ),
            xContentTypeOptions: (string) (
                $values['xContentTypeOptions'] ?? $values['x_content_type_options'] ?? $defaults->xContentTypeOptions
            ),
            referrerPolicy: (string) (
                $values['referrerPolicy'] ?? $values['referrer_policy'] ?? $defaults->referrerPolicy
            ),
            xssProtection: (string) (
                $values['xssProtection'] ?? $values['xss_protection'] ?? $defaults->xssProtection
            ),
            hstsMaxAge: (int) (
                $values['hstsMaxAge'] ?? $values['hsts_max_age'] ?? $defaults->hstsMaxAge
            ),
            hstsIncludeSubdomains: ConfigBoolean::firstSet(
                'secureHeaders',
                $values,
                ['hstsIncludeSubdomains', 'hsts_include_subdomains'],
                $defaults->hstsIncludeSubdomains,
            ),
            csp: (string) ($values['csp'] ?? $defaults->csp),
            permissionsPolicy: (string) (
                $values['permissionsPolicy'] ?? $values['permissions_policy'] ?? $defaults->permissionsPolicy
            ),
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
