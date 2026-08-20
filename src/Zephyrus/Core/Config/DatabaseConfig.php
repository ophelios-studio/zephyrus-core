<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

/**
 * Immutable configuration section for a single database connection.
 *
 * Required fields: database, username.
 * All other fields carry safe defaults for a typical local PostgreSQL setup.
 *
 * Validation rules:
 *   - database and username must be non-empty strings.
 *   - port must be in the valid TCP range 1-65535.
 *   - charset must be alphanumeric (safe for SQL SET client_encoding).
 *   - driver must be 'pgsql' (only PostgreSQL is supported).
 *
 * Performance note (emulatePrepares):
 *   PostgreSQL server-side prepared statements cost three network round-trips
 *   per query (Parse, Bind/Describe, Execute). Over a non-local DB link that
 *   dominates query latency. Setting PDO::ATTR_EMULATE_PREPARES collapses each
 *   query to a single round-trip by interpolating parameters client-side.
 *
 *   This is a trade-off: emulated prepares lose server-side plan caching and
 *   typed server-side binding, and PostgreSQL is stricter about parameter
 *   types under emulation (e.g. integer LIMIT/OFFSET, typed casts). It is
 *   therefore OPT-IN and defaults to false, preserving native prepares and the
 *   behavior of every existing application.
 *
 * Transport security note (sslMode / sslRootCert):
 *   libpq negotiates TLS by itself and defaults to 'prefer', meaning it
 *   encrypts when the server offers TLS and silently falls back to plaintext
 *   when it does not. Pinning a mode is a deployment policy decision, never a
 *   safe default: a server built without TLS refuses every connection under
 *   'require' or stricter, so an application that hard-coded it would go down
 *   the moment it was deployed against such a server.
 *
 *   Both fields are therefore OPT-IN and default to null. Null means the
 *   parameter is left OUT of the DSN entirely, so libpq keeps its own default
 *   and the connection string of every existing application is unchanged.
 *
 *   Only client-side verification is covered here. Client-certificate
 *   authentication (sslcert / sslkey) is deliberately out of scope: it is an
 *   authentication mechanism, and this section carries a username/password
 *   pair instead.
 */
final readonly class DatabaseConfig
{
    /**
     * The complete set of libpq sslmode values, in increasing strictness.
     *
     * Kept as an explicit allow-list because this value is interpolated
     * verbatim into the PDO DSN. Anything outside the set must fail at
     * construction rather than reach that string, where a stray separator
     * could truncate the connection parameters or append new ones.
     */
    public const array SSL_MODES = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];

    public function __construct(
        public string $driver,
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        public string $password,
        public string $charset,
        public bool $emulatePrepares = false,
        public ?string $sslMode = null,
        public ?string $sslRootCert = null,
    ) {
        // Validated in the constructor rather than in fromArray() alone, unlike
        // every other field here: these two are the only ones appended to the
        // DSN as free-form text, so the guarantee worth having is that no
        // instance can exist at all carrying a value libpq would not recognise.
        // fromArray() normalises first (trim, lower-case, empty to null); a
        // direct caller is expected to pass a canonical value or null.
        if ($this->sslMode !== null && !in_array($this->sslMode, self::SSL_MODES, true)) {
            throw ConfigurationException::invalidValue(
                'database',
                'sslmode',
                $this->sslMode,
                'must be null (leave the parameter out of the DSN) or one of: ' . implode(', ', self::SSL_MODES),
            );
        }

        // A path is opaque to us, so the only check that means anything is that
        // it cannot break out of its DSN parameter. Existence is left to libpq,
        // which reads the file at connect time and reports a precise error.
        if ($this->sslRootCert !== null && preg_match('/^[^\\s;\'"]+$/', $this->sslRootCert) !== 1) {
            throw ConfigurationException::invalidValue(
                'database',
                'sslrootcert',
                $this->sslRootCert,
                'must be null or a non-empty path free of whitespace, semicolons and quotes, '
                    . 'any of which would truncate or extend the DSN',
            );
        }
    }

    /**
     * Build a DatabaseConfig from a plain key-value array.
     *
     * @param array<string, mixed> $values
     * @throws ConfigurationException if required fields are absent or invalid values are supplied.
     */
    public static function fromArray(array $values): self
    {
        $driver   = (string) ($values['driver']   ?? 'pgsql');
        $host     = (string) ($values['host']     ?? 'localhost');
        $port     = (int)    ($values['port']     ?? 5432);
        $database = (string) ($values['database'] ?? '');
        $username = (string) ($values['username'] ?? '');
        $password = (string) ($values['password'] ?? '');
        $charset  = (string) ($values['charset']  ?? 'utf8');
        // Opt-in client-side parameter emulation. Accepts both a camelCase key
        // and the canonical snake_case config key, mirroring the mixed-case
        // key handling used across the other configuration sections.
        $emulatePrepares = (bool) ($values['emulatePrepares'] ?? $values['emulate_prepares'] ?? false);
        // Opt-in TLS policy for the connection. Accepts the camelCase key and
        // the canonical libpq spelling, like the pair above. Absent, blank or
        // whitespace-only all collapse to null, which keeps the parameter out
        // of the DSN: an operator clearing an environment variable must land
        // back on the previous behaviour, not on a malformed connection string.
        $sslMode     = self::normalizeOptional($values['sslMode'] ?? $values['sslmode'] ?? null);
        $sslRootCert = self::normalizeOptional($values['sslRootCert'] ?? $values['sslrootcert'] ?? null);

        // Case-folded because libpq matches sslmode exactly and an environment
        // variable spelled REQUIRE is an operator typo, not a different policy.
        // The cert path is left alone: file systems are case-sensitive.
        if ($sslMode !== null) {
            $sslMode = strtolower($sslMode);
        }

        if (trim($database) === '') {
            throw ConfigurationException::missingRequired('database', 'database');
        }

        if (trim($username) === '') {
            throw ConfigurationException::missingRequired('database', 'username');
        }

        if ($port < 1 || $port > 65535) {
            throw ConfigurationException::invalidValue(
                'database',
                'port',
                $port,
                'must be between 1 and 65535',
            );
        }

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $charset)) {
            throw ConfigurationException::invalidValue(
                'database',
                'charset',
                $charset,
                'must contain only alphanumeric characters and underscores',
            );
        }

        if ($driver !== 'pgsql') {
            throw ConfigurationException::invalidValue(
                'database',
                'driver',
                $driver,
                'only pgsql driver is supported',
            );
        }

        return new self(
            driver:          $driver,
            host:            $host,
            port:            $port,
            database:        $database,
            username:        $username,
            password:        $password,
            charset:         $charset,
            emulatePrepares: $emulatePrepares,
            sslMode:         $sslMode,
            sslRootCert:     $sslRootCert,
        );
    }

    /**
     * Reduce an optional configuration value to a trimmed string, or to null
     * when it carries nothing usable.
     *
     * A YAML `!env VAR` with no default resolves to null when the variable is
     * unset, and to an empty string when the variable is set but blank. Both
     * mean "not configured", and both must produce the same result, otherwise
     * an empty environment variable would push an empty parameter into the DSN.
     */
    private static function normalizeOptional(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
