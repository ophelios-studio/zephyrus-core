<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

/**
 * Immutable configuration section for PHP session behaviour.
 *
 * Defaults are conservative and security-oriented:
 *   - httpOnly:   true  (no JS cookie access)
 *   - secure:     auto  (on whenever the request itself is HTTPS)
 *   - sameSite:   'Lax' (balanced CSRF protection)
 *   - lifetime:   0     (browser-session cookie)
 *   - cookiePath: '/'
 *
 * No Domain attribute is ever emitted, so the cookie is host-only.
 *
 * ## `secure` is a THREE-state setting
 *
 * SessionConfig::fromArray([]) used to leave the cookie without a Secure
 * attribute: an HTTPS request produced session_get_cookie_params()['secure']
 * = false, so a deployment that did not set the flag itself shipped a session
 * cookie a downgraded request could carry. The default is now the same one
 * Symfony ships, "auto":
 *
 *   absent, or 'auto'  -> secure=false, secureAuto=true   Secure on HTTPS only
 *   true               -> secure=true,  secureAuto=false  always Secure
 *   false              -> secure=false, secureAuto=false  never Secure
 *
 * The explicit `false` is what a local HTTP development environment sets, and
 * it is honoured: auto never overrides it.
 *
 * Read the pair through resolveSecure(); `secure` alone answers "is it forced
 * on", not "will the cookie carry Secure".
 *
 * ## Combinations a browser would silently discard are refused
 *
 *   - SameSite=None without Secure. Every current browser drops the cookie.
 *   - A `__Host-` name whose path is not '/', or that can never be Secure.
 *   - A `__Secure-` name that can never be Secure.
 *
 * These throw rather than being repaired, because the alternative is a cookie
 * that is never stored and an application that looks logged out for no visible
 * reason. Only the definitively broken shapes are refused: a name with a
 * prefix combined with `secure: auto` is allowed, since it is correct on the
 * HTTPS origin the prefix is for.
 *
 * Validation rules:
 *   - name must be a non-empty string.
 *   - lifetime must be >= 0.
 *   - sameSite must be one of: Strict, Lax, None.
 */
final readonly class SessionConfig
{
    /** @var array<string> */
    private const VALID_SAME_SITE = ['Strict', 'Lax', 'None'];

    /**
     * @param bool $secure     Force the Secure attribute on regardless of the request.
     * @param bool $secureAuto Add Secure when the request itself is HTTPS. Ignored when
     *                         $secure is already true. Defaults to false so a caller
     *                         building this object positionally keeps the exact
     *                         behaviour it had; fromArray() turns it on.
     */
    public function __construct(
        public string $name,
        public int $lifetime,
        public bool $httpOnly,
        public bool $secure,
        public string $sameSite,
        public string $cookiePath,
        public bool $secureAuto = false,
    ) {
        // Validated here rather than only in fromArray() so that a caller
        // constructing the object directly, which the framework's own
        // middlewares do, cannot assemble a cookie the browser will drop.
        if (trim($this->name) === '') {
            throw ConfigurationException::invalidValue('session', 'name', $this->name, 'must not be empty');
        }

        if ($this->lifetime < 0) {
            throw ConfigurationException::invalidValue('session', 'lifetime', $this->lifetime, 'must be 0 or greater');
        }

        if (!in_array($this->sameSite, self::VALID_SAME_SITE, strict: true)) {
            throw ConfigurationException::invalidValue(
                'session',
                'sameSite',
                $this->sameSite,
                sprintf('must be one of: %s', implode(', ', self::VALID_SAME_SITE)),
            );
        }

        $this->assertCookieCanBeStored();
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
        $rawSecure = $values['secure'] ?? null;
        $auto = $rawSecure === null
            || (is_string($rawSecure) && strtolower(trim($rawSecure)) === 'auto');

        return new self(
            name:       (string) ($values['name']                                 ?? 'PHPSESSID'),
            lifetime:   (int)    ($values['lifetime']                             ?? 0),
            httpOnly:   (bool)   ($values['httpOnly']   ?? $values['http_only']   ?? true),
            secure:     !$auto && (bool) $rawSecure,
            sameSite:   (string) ($values['sameSite']   ?? $values['same_site']   ?? 'Lax'),
            cookiePath: (string) ($values['cookiePath'] ?? $values['cookie_path'] ?? '/'),
            secureAuto: $auto,
        );
    }

    /**
     * Whether the cookie should carry the Secure attribute for this request.
     *
     * @param bool $requestIsSecure Whether the REQUEST arrived over HTTPS. The
     *   caller supplies it because only the caller knows whether a forwarded
     *   protocol header may be trusted; SessionMiddleware passes the answer
     *   Request already resolved against the trusted-header allowlist.
     */
    public function resolveSecure(bool $requestIsSecure): bool
    {
        return $this->secure || ($this->secureAuto && $requestIsSecure);
    }

    /** Whether Secure can ever be set, i.e. it is not explicitly turned off. */
    private function secureIsPossible(): bool
    {
        return $this->secure || $this->secureAuto;
    }

    private function assertCookieCanBeStored(): void
    {
        if ($this->sameSite === 'None' && !$this->secureIsPossible()) {
            throw ConfigurationException::invalidValue(
                'session',
                'sameSite',
                $this->sameSite,
                'requires secure to be true or auto: browsers discard a SameSite=None cookie without Secure',
            );
        }

        if (str_starts_with($this->name, '__Host-')) {
            if (!$this->secureIsPossible()) {
                throw ConfigurationException::invalidValue(
                    'session',
                    'name',
                    $this->name,
                    'uses the __Host- prefix, which requires secure to be true or auto',
                );
            }

            if ($this->cookiePath !== '/') {
                throw ConfigurationException::invalidValue(
                    'session',
                    'cookiePath',
                    $this->cookiePath,
                    sprintf('must be "/" for the __Host- prefixed name "%s"', $this->name),
                );
            }

            return;
        }

        if (str_starts_with($this->name, '__Secure-') && !$this->secureIsPossible()) {
            throw ConfigurationException::invalidValue(
                'session',
                'name',
                $this->name,
                'uses the __Secure- prefix, which requires secure to be true or auto',
            );
        }
    }
}
