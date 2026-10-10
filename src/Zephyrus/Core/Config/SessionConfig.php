<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

/**
 * Immutable configuration section for PHP session behaviour.
 *
 * Defaults (secure by default):
 *   - name:       'PHPSESSID'
 *   - lifetime:   0, a browser-session cookie.
 *   - httpOnly:   true, so scripts cannot read the cookie.
 *   - secure:     'auto', Secure only on HTTPS requests (see below).
 *   - sameSite:   'Lax', one of Strict, Lax, None.
 *   - cookiePath: '/'
 *   - idleTimeout: null, session.gc_maxlifetime left as configured.
 *
 * No Domain attribute is ever emitted, so the cookie is host-only.
 *
 * `secure` takes three states:
 *   absent or 'auto'  secure=false, secureAuto=true   Secure on HTTPS requests only
 *   true              secure=true,  secureAuto=false  always Secure
 *   false             secure=false, secureAuto=false  never Secure (local HTTP development)
 *
 * The default 'auto' makes HTTPS requests always carry Secure. Read the result with
 * resolveSecure(), since `secure` alone does not answer whether the cookie carries Secure.
 *
 * Refused at construction, because a browser would silently discard the cookie:
 *   - SameSite=None without Secure.
 *   - A `__Host-` name whose path is not '/', or that can never be Secure.
 *   - A `__Secure-` name that can never be Secure.
 *   A prefixed name with secure 'auto' is allowed: it is valid on the HTTPS origin.
 *
 * Validation:
 *   - name: non-empty string.
 *   - lifetime: 0 or greater.
 *   - idleTimeout: a positive whole number of seconds when set, and small enough that
 *     now plus it fits the INTEGER expire column of the DatabaseSessionHandler schema (2147483647).
 *   - sameSite: one of Strict, Lax, None (case-sensitive).
 */
final readonly class SessionConfig
{
    /** @var array<string> */
    private const VALID_SAME_SITE = ['Strict', 'Lax', 'None'];

    private const IDLE_TIMEOUT_RULE = 'must be a positive whole number of seconds';

    /** The largest value the INTEGER expire column of the documented session table holds. */
    private const EXPIRE_COLUMN_MAX = 2_147_483_647;

    /**
     * @param bool $secure     Force the Secure attribute on regardless of the request.
     * @param bool $secureAuto Add Secure when the request is HTTPS. Ignored when $secure is true.
     *                         Defaults to false for positional callers; fromArray() turns it on.
     * @param ?int $idleTimeout Seconds, applied by SessionManager::start() as session.gc_maxlifetime.
     *                         DatabaseSessionHandler refuses a session idle longer on read.
     */
    public function __construct(
        public string $name,
        public int $lifetime,
        public bool $httpOnly,
        public bool $secure,
        public string $sameSite,
        public string $cookiePath,
        public bool $secureAuto = false,
        public ?int $idleTimeout = null,
    ) {
        // Also checked here, so a directly built object cannot hold a cookie browsers would drop.
        if (trim($this->name) === '') {
            throw ConfigurationException::invalidValue('session', 'name', $this->name, 'must not be empty');
        }

        if ($this->lifetime < 0) {
            throw ConfigurationException::invalidValue('session', 'lifetime', $this->lifetime, 'must be 0 or greater');
        }

        if ($this->idleTimeout !== null && $this->idleTimeout <= 0) {
            throw ConfigurationException::invalidValue('session', 'idleTimeout', $this->idleTimeout, self::IDLE_TIMEOUT_RULE);
        }

        $longestIdleTimeout = self::EXPIRE_COLUMN_MAX - time();
        if ($this->idleTimeout !== null && $this->idleTimeout > $longestIdleTimeout) {
            throw ConfigurationException::invalidValue(
                'session',
                'idleTimeout',
                $this->idleTimeout,
                sprintf(
                    'must be at most %d seconds so that now plus the timeout fits the INTEGER expire column',
                    $longestIdleTimeout,
                ),
            );
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
     * Accepts camelCase and snake_case keys (e.g. http_only, same_site, idle_timeout).
     *
     * @param array<string, mixed> $values
     * @throws ConfigurationException on a non-boolean secure or httpOnly, an idleTimeout that is not
     *         a positive integer within range, or any constructor rule (empty name, negative lifetime,
     *         unknown sameSite, or a combination browsers would discard).
     */
    public static function fromArray(array $values): self
    {
        $rawSecure = $values['secure'] ?? null;
        $auto = $rawSecure === null
            || (is_string($rawSecure) && strtolower(trim($rawSecure)) === 'auto');

        return new self(
            name:       (string) ($values['name']                                 ?? 'PHPSESSID'),
            lifetime:   (int)    ($values['lifetime']                             ?? 0),
            httpOnly:   ConfigBoolean::firstSet('session', $values, ['httpOnly', 'http_only'], true),
            secure:     !$auto && ConfigBoolean::parse('session', 'secure', $rawSecure),
            sameSite:   (string) ($values['sameSite']   ?? $values['same_site']   ?? 'Lax'),
            cookiePath: (string) ($values['cookiePath'] ?? $values['cookie_path'] ?? '/'),
            secureAuto: $auto,
            idleTimeout: self::idleTimeoutFrom($values['idleTimeout'] ?? $values['idle_timeout'] ?? null),
        );
    }

    /**
     * Strict on purpose: a cast would read '30m' as 30 seconds and 'abc' as 0.
     */
    private static function idleTimeoutFrom(mixed $value): ?int
    {
        if ($value === null || is_int($value)) {
            return $value;
        }

        // Leading zeros are dropped so '0600' reads as decimal.
        $digits = is_string($value) && preg_match('/\A[0-9]+\z/', $value) === 1 ? ltrim($value, '0') : '';

        if ($digits !== '') {
            $seconds = filter_var($digits, FILTER_VALIDATE_INT);

            if ($seconds === false) {
                throw ConfigurationException::invalidValue('session', 'idleTimeout', $value, 'is too large');
            }

            return $seconds;
        }

        throw ConfigurationException::invalidValue(
            'session',
            'idleTimeout',
            $value,
            self::IDLE_TIMEOUT_RULE,
        );
    }

    /**
     * Whether the cookie should carry the Secure attribute for this request.
     *
     * @param bool $requestIsSecure Whether the request arrived over HTTPS. The caller decides, since
     *   only it knows whether a forwarded protocol header is trusted (SessionMiddleware uses the
     *   answer Request resolved against the trusted-header allowlist).
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
                    'must be "/" when the session name has the __Host- prefix',
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
