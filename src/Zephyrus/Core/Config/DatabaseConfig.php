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
 */
final readonly class DatabaseConfig
{
    public function __construct(
        public string $driver,
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        public string $password,
        public string $charset,
        public bool $emulatePrepares = false,
    ) {
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
        );
    }
}
