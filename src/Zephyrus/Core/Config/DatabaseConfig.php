<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

/**
 * Immutable configuration section for a single database connection.
 *
 * Required fields: database, username.
 * All other fields carry safe defaults for a typical local MySQL setup.
 *
 * Validation rules:
 *   - database and username must be non-empty strings.
 *   - port must be in the valid TCP range 1–65535.
 */
final readonly class DatabaseConfig
{
    public function __construct(
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
        $host     = (string) ($values['host']     ?? 'localhost');
        $port     = (int)    ($values['port']     ?? 3306);
        $database = (string) ($values['database'] ?? '');
        $username = (string) ($values['username'] ?? '');
        $password = (string) ($values['password'] ?? '');
        $charset  = (string) ($values['charset']  ?? 'utf8mb4');

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

        return new self(
            host:     $host,
            port:     $port,
            database: $database,
            username: $username,
            password: $password,
            charset:  $charset,
        );
    }
}
