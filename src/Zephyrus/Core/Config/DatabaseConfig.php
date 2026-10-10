<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

/**
 * Immutable configuration section for a single database connection.
 *
 * Required fields: database, username. Defaults: driver 'pgsql', host 'localhost', port 5432,
 * password '', charset 'utf8', sslMode and sslRootCert null, columnCacheVersion ''.
 *
 * Validation (fromArray, and the constructor for charset, sslMode and sslRootCert):
 *   - database and username: non-empty strings (fromArray only).
 *   - port: 1-65535 (fromArray only).
 *   - driver: 'pgsql' (fromArray only).
 *   - columnCacheVersion: a string or an integer, trimmed (fromArray only).
 *   - charset: alphanumeric or underscore only, as it is interpolated into SET client_encoding.
 *   - sslMode and sslRootCert: DSN-safe, as they are interpolated into the PDO DSN.
 *
 * Prepared statements are always PostgreSQL server-side (extended query protocol).
 * The emulate_prepares keys are refused, see REMOVED_EMULATE_PREPARES_KEYS.
 *
 * TLS (sslMode, sslRootCert): both default to null, which leaves the parameter out of the
 * DSN so libpq keeps its own default ('prefer'). A pinned mode is a deployment decision:
 * 'require' or stricter fails against a server built without TLS. Client-certificate
 * authentication (sslcert, sslkey) is not supported.
 *
 * columnCacheVersion: a string or an integer, quoted in config.yml when it is a number such as '1.10'.
 * It is part of the shared column shape cache key, see Database. Write a literal that changes with every
 * migration (for example the latest migration id), and change it after the migration has run and before
 * the release serves traffic. It helps when the PHP master survives a release and re-reads config.yml
 * (symlink deploys). A process restart already clears the cache. Processes still running
 * old code keep their cache until APCU_TTL (one hour) expires or flushSharedColumnMetadata() runs from a web request.
 */
final readonly class DatabaseConfig
{
    /**
     * The libpq sslmode values, in increasing strictness.
     *
     * An allow-list because the value is interpolated into the PDO DSN.
     */
    public const array SSL_MODES = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];

    /**
     * Keys that fromArray() refuses, in either spelling, even when set to false.
     *
     * A silently ignored key would leave the file documenting a knob that no longer exists.
     */
    public const array REMOVED_EMULATE_PREPARES_KEYS = ['emulatePrepares', 'emulate_prepares'];

    public function __construct(
        public string $driver,
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        #[\SensitiveParameter] public string $password,
        public string $charset,
        public ?string $sslMode = null,
        public ?string $sslRootCert = null,
        public string $columnCacheVersion = '',
    ) {
        // The constructor re-checks these three, so a directly built config cannot carry a
        // value fromArray() would refuse. fromArray() normalises first (trim, lower-case,
        // empty to null).

        // A quote and a semicolon in charset would open a second statement in SET client_encoding.
        if (preg_match('/^[a-zA-Z0-9_]+$/D', $this->charset) !== 1) {
            throw ConfigurationException::invalidValue(
                'database',
                'charset',
                $this->charset,
                'must contain only alphanumeric characters and underscores',
            );
        }

        if ($this->sslMode !== null && !in_array($this->sslMode, self::SSL_MODES, true)) {
            throw ConfigurationException::invalidValue(
                'database',
                'sslmode',
                $this->sslMode,
                'must be null (leave the parameter out of the DSN) or one of: ' . implode(', ', self::SSL_MODES),
            );
        }

        // Whitespace, semicolons, quotes and backslashes are DSN syntax to libpq; whether the
        // file exists is reported at connect time.
        if ($this->sslRootCert !== null && preg_match('/^[^\\s;\'"\\\\]+$/D', $this->sslRootCert) !== 1) {
            throw ConfigurationException::invalidValue(
                'database',
                'sslrootcert',
                $this->sslRootCert,
                'must be null or a non-empty path free of whitespace, semicolons, quotes and backslashes, '
                    . 'any of which would truncate or extend the DSN',
            );
        }
    }

    /**
     * Build a DatabaseConfig from a plain key-value array.
     *
     * Accepts sslMode, sslmode or ssl_mode, sslRootCert, sslrootcert or ssl_root_cert, and columnCacheVersion
     * or column_cache_version, the first spelling listed winning. Blank values mean null, except a blank
     * columnCacheVersion, which becomes ''.
     *
     * @param array<string, mixed> $values
     * @throws ConfigurationException if a removed emulate_prepares key is present, a required
     *                                field is missing, or a value is invalid.
     */
    public static function fromArray(array $values): self
    {
        // Checked first, so an operator upgrading gets the removal message, not an unrelated error.
        foreach (self::REMOVED_EMULATE_PREPARES_KEYS as $removed) {
            if (array_key_exists($removed, $values)) {
                throw self::removedEmulatePrepares($removed);
            }
        }

        $driver   = (string) ($values['driver']   ?? 'pgsql');
        $host     = (string) ($values['host']     ?? 'localhost');
        $port     = (int)    ($values['port']     ?? 5432);
        $database = (string) ($values['database'] ?? '');
        $username = (string) ($values['username'] ?? '');
        $password = (string) ($values['password'] ?? '');
        $charset  = (string) ($values['charset']  ?? 'utf8');
        $sslMode = self::optionalSetting($values, ['sslMode', 'sslmode', 'ssl_mode']);
        $sslRootCert = self::optionalSetting($values, ['sslRootCert', 'sslrootcert', 'ssl_root_cert']);
        $columnCacheVersion = self::optionalSetting(
            $values,
            ['columnCacheVersion', 'column_cache_version'],
            ": quote a number such as '1.10' to keep its digits",
        ) ?? '';

        // libpq matches sslmode exactly, so REQUIRE is folded. The cert path is case-sensitive.
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
            sslMode:         $sslMode,
            sslRootCert:     $sslRootCert,
            columnCacheVersion: $columnCacheVersion,
        );
    }

    /**
     * The boot error for a configuration file that still carries a removed emulation key.
     */
    private static function removedEmulatePrepares(string $key): ConfigurationException
    {
        return new ConfigurationException(sprintf(
            "Configuration section 'database' field '%s' has been REMOVED from Zephyrus and is no "
            . 'longer honoured. Client-side parameter emulation '
            . '(PDO::ATTR_EMULATE_PREPARES) was taken out because it is a security downgrade, not a '
            . "tuning knob:\n"
            . "  - binding invalid UTF-8 kills the worker process instead of raising;\n"
            . "  - PDO::quote() truncates silently at a NUL byte, storing a value nobody typed;\n"
            . "  - parameter values are interpolated into the statement the server parses, so they "
            . "leak into driver error messages;\n"
            . '  - client-side interpolation gives up the extended protocol\'s refusal of a second '
            . "statement, which is a defence-in-depth layer against SQL injection.\n"
            . "Connections now always use native server-side prepares.\n"
            . 'To fix: delete this line from the database section. There is no replacement setting, '
            . 'and setting it to false is not accepted either, because the line would keep '
            . 'documenting a knob that no longer exists.',
            $key,
        ));
    }

    /**
     * Reads an optional string from the first spelling set to a non-null value.
     *
     * @param array<string, mixed> $values
     * @param list<string>         $spellings Accepted keys, the first one winning.
     * @param string               $hint      Appended to the refusal message.
     * @throws ConfigurationException if the value is neither a string nor an integer.
     */
    private static function optionalSetting(array $values, array $spellings, string $hint = ''): ?string
    {
        foreach ($spellings as $key) {
            $value = $values[$key] ?? null;
            if ($value === null) {
                continue;
            }

            if (!is_string($value) && !is_int($value)) {
                throw ConfigurationException::invalidValue(
                    'database',
                    $key,
                    get_debug_type($value),
                    'must be a string or an integer' . $hint,
                );
            }

            $normalized = trim((string) $value);

            return $normalized === '' ? null : $normalized;
        }

        return null;
    }
}
