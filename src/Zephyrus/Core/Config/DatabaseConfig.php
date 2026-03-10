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
            driver:   $driver,
            host:     $host,
            port:     $port,
            database: $database,
            username: $username,
            password: $password,
            charset:  $charset,
        );
    }
}
