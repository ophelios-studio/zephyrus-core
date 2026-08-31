<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

use Zephyrus\Http\Request;

/**
 * Immutable configuration section for HTTP security behaviour.
 *
 * Supports both flat keys (legacy) and nested YAML sections:
 *
 * Nested format (preferred):
 *   security:
 *     forceHttps: true
 *     allowedHosts: [example.com]
 *     maxBodySize: 2097152
 *     trustedProxies: []
 *     trustedHeaders: [x-forwarded-for, x-forwarded-host, x-forwarded-proto, x-forwarded-port]
 *     csrf:
 *       enabled: true
 *       autoHtml: false
 *       exceptions: []
 *     encryption:
 *       key: !env ENCRYPTION_KEY
 *
 * Flat format (legacy, still accepted):
 *   security:
 *     forceHttps: true
 *     csrfEnabled: true
 *     csrfAutoHtml: false
 *     csrfExceptions: []
 *     allowedHosts: []
 *     maxBodySize: 2097152
 *     trustedProxies: []
 *     trustedHeaders: []
 *
 * Defaults are conservative yet development-friendly:
 *   - forceHttps:     false (must be explicitly enabled in production)
 *   - csrfEnabled:    true  (on by default)
 *   - csrfAutoHtml:   false (disabled by default)
 *   - csrfExceptions: []    (no excluded paths by default)
 *   - allowedHosts:   []    (empty = any host; populate for production lockdown)
 *   - maxBodySize:    2097152 (2 MB; 0 = unlimited)
 *   - trustedProxies: []    (empty = trust no proxies; forwarded headers ignored)
 *   - trustedHeaders: the X-Forwarded-* family (see Request::TRUSTED_HEADERS_DEFAULT);
 *                     'forwarded', 'x-real-ip', 'cf-connecting-ip' and 'x-client-ip'
 *                     are opt-in, and [] reads no forwarded header at all
 *   - encryptionKey:  null  (must be set explicitly for Cryptography usage)
 *
 * Validation rules:
 *   - maxBodySize must be 0 or greater.
 *   - Each allowedHost entry must be a non-empty string.
 *   - Each csrfExceptions entry must be a non-empty string.
 *   - Each trustedProxies entry must be a non-empty string (IP or CIDR).
 *   - Each trustedHeaders entry must name a header Request can actually read;
 *     an unknown name is REJECTED rather than ignored, because silently dropping
 *     a typo would leave an operator believing they trust a header they do not.
 */
final readonly class SecurityConfig
{
    /**
     * @param bool     $forceHttps      Redirect plain-HTTP requests to HTTPS.
     * @param bool     $csrfEnabled     Enable CSRF token verification on mutating requests.
     * @param bool     $csrfAutoHtml    Auto-inject CSRF hidden input in HTML forms.
     * @param string[] $csrfExceptions  Regex path patterns excluded from CSRF validation.
     * @param string[] $allowedHosts    Restrict accepted Host headers; empty allows all.
     * @param int      $maxBodySize     Maximum request body in bytes (0 = unlimited).
     * @param string[] $trustedProxies  IP addresses/CIDR ranges whose forwarded headers
     *                                  are trusted. Empty = trust no proxies (safe default).
     *                                  Use ['*'] to trust all proxies (development only).
     * @param ?string  $encryptionKey   Application encryption key (nullable, from !env).
     * @param string[] $trustedHeaders  Which forwarding headers may be read once the peer is a
     *                                  trusted proxy. Trusting a proxy is NOT the same as trusting
     *                                  every header a caller can name: a proxy manages one family
     *                                  and passes the rest through untouched. Defaults to the
     *                                  X-Forwarded-* family; [] reads none.
     */
    public function __construct(
        public bool $forceHttps,
        public bool $csrfEnabled,
        public bool $csrfAutoHtml,
        public array $csrfExceptions,
        public array $allowedHosts,
        public int $maxBodySize,
        public array $trustedProxies = [],
        public ?string $encryptionKey = null,
        public array $trustedHeaders = Request::TRUSTED_HEADERS_DEFAULT,
    ) {
    }

    /**
     * Build a SecurityConfig from a plain key-value array.
     *
     * Accepts both nested (csrf: / encryption:) and flat (csrfEnabled, etc.)
     * key variants. Nested keys take precedence when both are present.
     *
     * @param array<string, mixed> $values
     * @throws ConfigurationException if supplied values violate constraints.
     */
    public static function fromArray(array $values): self
    {
        $forceHttps = (bool) ($values['forceHttps'] ?? $values['force_https'] ?? false);

        // CSRF: nested 'csrf' section takes precedence over flat keys
        $csrf = isset($values['csrf']) && is_array($values['csrf']) ? $values['csrf'] : [];
        $csrfEnabled = (bool) (
            $csrf['enabled']
            ?? $csrf['csrf_enabled']
            ?? $values['csrfEnabled']
            ?? $values['csrf_enabled']
            ?? true
        );
        $csrfAutoHtml = (bool) (
            $csrf['autoHtml']
            ?? $csrf['auto_html']
            ?? $values['csrfAutoHtml']
            ?? $values['csrf_auto_html']
            ?? false
        );
        $csrfExceptions = (array) (
            $csrf['exceptions']
            ?? $csrf['csrf_exceptions']
            ?? $values['csrfExceptions']
            ?? $values['csrf_exceptions']
            ?? []
        );

        $allowedHosts = (array) ($values['allowedHosts'] ?? $values['allowed_hosts'] ?? []);
        $maxBodySize = (int) ($values['maxBodySize'] ?? $values['max_body_size'] ?? 2_097_152);
        $trustedProxies = (array) ($values['trustedProxies'] ?? $values['trusted_proxies'] ?? []);
        // An ABSENT key takes the default set; an explicitly empty list is a
        // valid, maximally strict setting and must not be confused with it.
        $trustedHeaders = (array) (
            $values['trustedHeaders']
            ?? $values['trusted_headers']
            ?? Request::TRUSTED_HEADERS_DEFAULT
        );

        // Encryption: nested 'encryption' section takes precedence
        $encryption = isset($values['encryption']) && is_array($values['encryption']) ? $values['encryption'] : [];
        $encryptionKey = $encryption['key']
            ?? $values['encryptionKey']
            ?? $values['encryption_key']
            ?? null;
        if (is_string($encryptionKey)) {
            $encryptionKey = trim($encryptionKey);
            if ($encryptionKey === '') {
                $encryptionKey = null;
            }
        } else {
            $encryptionKey = null;
        }

        if ($maxBodySize < 0) {
            throw ConfigurationException::invalidValue(
                'security',
                'maxBodySize',
                $maxBodySize,
                'must be 0 (unlimited) or a positive byte count',
            );
        }

        foreach ($csrfExceptions as $i => $pattern) {
            if (!is_string($pattern) || trim($pattern) === '') {
                throw ConfigurationException::invalidValue(
                    'security',
                    "csrfExceptions[$i]",
                    $pattern,
                    'each entry must be a non-empty string',
                );
            }
        }

        foreach ($allowedHosts as $i => $host) {
            if (!is_string($host) || trim($host) === '') {
                throw ConfigurationException::invalidValue(
                    'security',
                    "allowedHosts[$i]",
                    $host,
                    'each entry must be a non-empty string',
                );
            }
        }

        foreach ($trustedProxies as $i => $proxy) {
            if (!is_string($proxy) || trim($proxy) === '') {
                throw ConfigurationException::invalidValue(
                    'security',
                    "trustedProxies[$i]",
                    $proxy,
                    'each entry must be a non-empty string (IP address or CIDR)',
                );
            }
        }

        $normalizedTrustedHeaders = [];
        foreach ($trustedHeaders as $i => $header) {
            if (!is_string($header) || trim($header) === '') {
                throw ConfigurationException::invalidValue(
                    'security',
                    "trustedHeaders[$i]",
                    $header,
                    'each entry must be a non-empty string',
                );
            }

            $name = strtolower(trim($header));
            if (!in_array($name, Request::TRUSTED_HEADERS_SUPPORTED, true)) {
                throw ConfigurationException::invalidValue(
                    'security',
                    "trustedHeaders[$i]",
                    $header,
                    'unknown forwarding header, supported names are '
                        . implode(', ', Request::TRUSTED_HEADERS_SUPPORTED),
                );
            }

            if (!in_array($name, $normalizedTrustedHeaders, true)) {
                $normalizedTrustedHeaders[] = $name;
            }
        }

        return new self(
            forceHttps: $forceHttps,
            csrfEnabled: $csrfEnabled,
            csrfAutoHtml: $csrfAutoHtml,
            csrfExceptions: array_values($csrfExceptions),
            allowedHosts: array_values($allowedHosts),
            maxBodySize: $maxBodySize,
            trustedProxies: array_values($trustedProxies),
            encryptionKey: $encryptionKey,
            trustedHeaders: $normalizedTrustedHeaders,
        );
    }
}
