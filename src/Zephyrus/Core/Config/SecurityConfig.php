<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

/**
 * Immutable configuration section for HTTP security behaviour.
 *
 * Defaults are conservative yet development-friendly:
 *   - forceHttps:  false (must be explicitly enabled in production)
 *   - csrfEnabled: true  (on by default)
 *   - allowedHosts: []   (empty = any host; populate for production lockdown)
 *   - maxBodySize: 2097152 (2 MB; 0 = unlimited)
 *
 * Validation rules:
 *   - maxBodySize must be 0 or greater.
 *   - Each allowedHost entry must be a non-empty string.
 */
final readonly class SecurityConfig
{
    /**
     * @param bool          $forceHttps   Redirect plain-HTTP requests to HTTPS.
     * @param bool          $csrfEnabled  Enable CSRF token verification on mutating requests.
     * @param string[]      $allowedHosts Restrict accepted Host headers; empty allows all.
     * @param int           $maxBodySize  Maximum request body in bytes (0 = unlimited).
     */
    public function __construct(
        public bool $forceHttps,
        public bool $csrfEnabled,
        public array $allowedHosts,
        public int $maxBodySize,
    ) {
    }

    /**
     * Build a SecurityConfig from a plain key-value array.
     *
     * Accepts both camelCase and snake_case key variants for ergonomic config files.
     *
     * @param array<string, mixed> $values
     * @throws ConfigurationException if supplied values violate constraints.
     */
    public static function fromArray(array $values): self
    {
        $forceHttps   = (bool) ($values['forceHttps']   ?? $values['force_https']   ?? false);
        $csrfEnabled  = (bool) ($values['csrfEnabled']  ?? $values['csrf_enabled']  ?? true);
        $allowedHosts = (array) ($values['allowedHosts'] ?? $values['allowed_hosts'] ?? []);
        $maxBodySize  = (int)  ($values['maxBodySize']  ?? $values['max_body_size']  ?? 2_097_152);

        if ($maxBodySize < 0) {
            throw ConfigurationException::invalidValue(
                'security',
                'maxBodySize',
                $maxBodySize,
                'must be 0 (unlimited) or a positive byte count',
            );
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

        return new self(
            forceHttps:   $forceHttps,
            csrfEnabled:  $csrfEnabled,
            allowedHosts: array_values($allowedHosts),
            maxBodySize:  $maxBodySize,
        );
    }
}
