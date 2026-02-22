<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

/**
 * Immutable configuration section for PHP session behaviour.
 *
 * Defaults are conservative and security-oriented:
 *   - httpOnly: true  (no JS cookie access)
 *   - secure:   false (caller must enable for HTTPS deployments)
 *   - sameSite: 'Lax' (balanced CSRF protection)
 *   - lifetime: 0     (browser-session cookie)
 *
 * Validation rules:
 *   - name must be a non-empty string.
 *   - lifetime must be ≥ 0.
 *   - sameSite must be one of: Strict, Lax, None.
 */
final readonly class SessionConfig
{
    /** @var array<string> */
    private const VALID_SAME_SITE = ['Strict', 'Lax', 'None'];

    public function __construct(
        public string $name,
        public int $lifetime,
        public bool $httpOnly,
        public bool $secure,
        public string $sameSite,
        public string $cookiePath,
    ) {
    }

    /**
     * Build a SessionConfig from a plain key-value array.
     *
     * Accepts both camelCase and snake_case key variants for ergonomic config files.
     *
     * @param array<string, mixed> $values
     * @throws ConfigurationException if supplied values violate constraints.
     */
    public static function fromArray(array $values): self
    {
        $name       = (string) ($values['name']                                 ?? 'PHPSESSID');
        $lifetime   = (int)    ($values['lifetime']                             ?? 0);
        $httpOnly   = (bool)   ($values['httpOnly']   ?? $values['http_only']   ?? true);
        $secure     = (bool)   ($values['secure']                               ?? false);
        $sameSite   = (string) ($values['sameSite']   ?? $values['same_site']   ?? 'Lax');
        $cookiePath = (string) ($values['cookiePath'] ?? $values['cookie_path'] ?? '/');

        if (trim($name) === '') {
            throw ConfigurationException::invalidValue(
                'session',
                'name',
                $name,
                'must not be empty',
            );
        }

        if ($lifetime < 0) {
            throw ConfigurationException::invalidValue(
                'session',
                'lifetime',
                $lifetime,
                'must be 0 or greater',
            );
        }

        if (!in_array($sameSite, self::VALID_SAME_SITE, strict: true)) {
            throw ConfigurationException::invalidValue(
                'session',
                'sameSite',
                $sameSite,
                sprintf('must be one of: %s', implode(', ', self::VALID_SAME_SITE)),
            );
        }

        return new self(
            name:       $name,
            lifetime:   $lifetime,
            httpOnly:   $httpOnly,
            secure:     $secure,
            sameSite:   $sameSite,
            cookiePath: $cookiePath,
        );
    }
}
