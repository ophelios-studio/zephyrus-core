<?php

declare(strict_types=1);

namespace Zephyrus\Security;

/**
 * Immutable configuration for HTTP security response headers.
 *
 * Each property maps directly to one HTTP response header. An empty string
 * value means that header will NOT be emitted, allowing callers to disable
 * individual headers without subclassing or hacking around the middleware.
 *
 * HSTS is handled separately: hstsMaxAge > 0 enables it; hstsIncludeSubdomains
 * controls the includeSubDomains directive. The middleware only emits HSTS on
 * HTTPS requests (it makes no sense on plain HTTP).
 *
 * Default values follow current OWASP guidance for new applications:
 *   - X-Frame-Options: SAMEORIGIN (clickjacking protection)
 *   - X-Content-Type-Options: nosniff (MIME-sniffing protection)
 *   - Referrer-Policy: strict-origin-when-cross-origin (privacy-safe default)
 *   - X-XSS-Protection: 0 (disables the legacy XSS Auditor — modern browsers
 *     use CSP instead; the auditor can itself be exploited)
 *   - HSTS: disabled (requires explicit opt-in with a real max-age)
 *   - Content-Security-Policy: empty — callers must provide a CSP appropriate
 *     for their application; there is no safe universal default
 *   - Permissions-Policy: empty — callers opt in per-feature
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
     * Build a SecureHeadersConfig from a plain key-value array.
     *
     * Accepts camelCase and snake_case key variants; camelCase takes precedence.
     *
     * @param array<string, mixed> $values
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
            hstsIncludeSubdomains: (bool) (
                $values['hstsIncludeSubdomains'] ?? $values['hsts_include_subdomains'] ?? $defaults->hstsIncludeSubdomains
            ),
            csp: (string) ($values['csp'] ?? $defaults->csp),
            permissionsPolicy: (string) (
                $values['permissionsPolicy'] ?? $values['permissions_policy'] ?? $defaults->permissionsPolicy
            ),
        );
    }

    /**
     * Return the fully-formed Strict-Transport-Security header value, or an
     * empty string when HSTS is disabled (hstsMaxAge == 0).
     */
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
