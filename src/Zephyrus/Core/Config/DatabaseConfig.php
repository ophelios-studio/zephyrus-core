<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

/**
 * Immutable configuration section for a single database connection.
 *
 * Required fields: database, username. Defaults: driver 'pgsql', host 'localhost', port 5432,
 * password '', charset 'utf8', sslMode and sslRootCert null, columnCacheVersion ''.
 *
 * Validation (fromArray, and the constructor for host, database, charset, sslMode and sslRootCert):
 *   - database: non-empty; fromArray reports a blank one as missing.
 *   - username: a non-empty string (fromArray only).
 *   - host: a string (fromArray only).
 *   - port: an integer or a string of digits, 1-65535 (fromArray only).
 *   - driver: 'pgsql' (fromArray only).
 *   - columnCacheVersion: a string or an integer, trimmed (fromArray only).
 *   - keys: a key no setting reads is refused (fromArray only).
 *   - sslMode, sslRootCert, columnCacheVersion: one spelling per setting (fromArray only).
 *   - charset: alphanumeric or underscore only, as it is interpolated into SET client_encoding.
 *   - host, database and sslRootCert: non-empty, valid UTF-8, with no ASCII whitespace, semicolons, equals
 *     signs, quotes, backslashes or control characters, as they are interpolated into the PDO DSN.
 *     host also refuses commas and "@": it is a single host name or address, not a libpq host list or a URL.
 *     database also refuses "://": it is a database name, not a connection URL.
 *   - sslMode: one of SSL_MODES.
 *   - fromArray trims host, port and database first.
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

    /** Everything after a password= key, in any case, with $1 holding the key. */
    private const string PASSWORD_VALUE_PATTERN = '#(password\s*=\s*).*#is';

    /** Accepted keys per property, preferred first; any other key is refused. */
    private const array SPELLINGS = [
        'driver' => ['driver'],
        'host' => ['host'],
        'port' => ['port'],
        'database' => ['database'],
        'username' => ['username'],
        'password' => ['password'],
        'charset' => ['charset'],
        'sslMode' => ['sslMode', 'sslmode', 'ssl_mode'],
        'sslRootCert' => ['sslRootCert', 'sslrootcert', 'ssl_root_cert'],
        'columnCacheVersion' => ['columnCacheVersion', 'column_cache_version'],
    ];

    public function __construct(
        public string $driver,
        #[\SensitiveParameter] public string $host,
        public int $port,
        #[\SensitiveParameter] public string $database,
        public string $username,
        #[\SensitiveParameter] public string $password,
        public string $charset,
        public ?string $sslMode = null,
        public ?string $sslRootCert = null,
        public string $columnCacheVersion = '',
    ) {
        // The constructor re-checks these values, so a directly built config cannot carry a
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

        self::assertDsnSafeValue($this->host, 'host', singleHost: true);
        if (str_contains($this->database, '://')) {
            throw ConfigurationException::invalidValue(
                'database',
                'database',
                self::withoutCredentials($this->database),
                'must be a database name, not a connection URL',
            );
        }
        self::assertDsnSafeValue($this->database, 'database');

        self::assertSslMode($this->sslMode, 'sslMode');
        if ($this->sslRootCert !== null) {
            self::assertDsnSafeValue($this->sslRootCert, 'sslRootCert');
        }
    }

    /**
     * Build a DatabaseConfig from a plain key-value array.
     *
     * Accepts sslMode, sslmode or ssl_mode, sslRootCert, sslrootcert or ssl_root_cert, and columnCacheVersion
     * or column_cache_version. Two spellings of one setting in the same array are refused. Blank values
     * mean null, except a blank columnCacheVersion, which becomes ''.
     *
     * @param array<string, mixed> $values
     * @throws ConfigurationException if a removed emulate_prepares key or an unknown key is present, two spellings
     *                                of one setting are present, a required field is missing, or a value is invalid.
     */
    public static function fromArray(#[\SensitiveParameter] array $values): self
    {
        // Checked first, so an operator upgrading gets the removal message, not an unrelated error.
        foreach (self::REMOVED_EMULATE_PREPARES_KEYS as $removed) {
            if (array_key_exists($removed, $values)) {
                throw self::removedEmulatePrepares($removed);
            }
        }

        $keys = ConfigKeys::read('database', $values, self::SPELLINGS);

        $driver   = (string) ($keys->value('driver') ?? 'pgsql');
        $host     = self::host($keys->value('host'));
        $database = trim((string) ($keys->value('database') ?? ''));
        $username = (string) ($keys->value('username') ?? '');
        $password = (string) ($keys->value('password') ?? '');
        $charset  = (string) ($keys->value('charset') ?? 'utf8');
        [$sslModeKey, $sslMode] = self::optionalSetting($keys, 'sslMode');
        [$sslRootCertKey, $sslRootCert] = self::optionalSetting($keys, 'sslRootCert');
        $columnCacheVersion = self::optionalSetting(
            $keys,
            'columnCacheVersion',
            ": quote a number such as '1.10' to keep its digits",
        )[1] ?? '';

        // libpq matches sslmode exactly, so REQUIRE is folded. The cert path is case-sensitive.
        if ($sslMode !== null) {
            $sslMode = strtolower($sslMode);
        }

        self::assertSslMode($sslMode, $sslModeKey);
        if ($sslRootCert !== null) {
            self::assertDsnSafeValue($sslRootCert, $sslRootCertKey);
        }

        if ($database === '') {
            throw ConfigurationException::missingRequired('database', 'database');
        }

        if (trim($username) === '') {
            throw ConfigurationException::missingRequired('database', 'username');
        }

        $port = self::port($keys->value('port'));

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
        return ConfigurationException::removedField(
            'database',
            $key,
            'Client-side parameter emulation '
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
        );
    }

    /**
     * @throws ConfigurationException if the host is neither null (localhost) nor a string.
     */
    private static function host(#[\SensitiveParameter] mixed $value): string
    {
        if ($value === null) {
            return 'localhost';
        }

        if (!is_string($value)) {
            throw ConfigurationException::invalidValue('database', 'host', $value, 'must be a string, such as db.example.com');
        }

        return trim($value);
    }

    /**
     * @throws ConfigurationException if the port is neither null (5432), an integer nor a string of digits once
     *         trimmed, or is outside 1-65535.
     */
    private static function port(mixed $value): int
    {
        if ($value === null) {
            return 5432;
        }

        $digits = match (true) {
            is_int($value) => (string) $value,
            is_string($value) && preg_match('/\A[0-9]+\z/', trim($value)) === 1 => ltrim(trim($value), '0'),
            default => throw ConfigurationException::invalidValue(
                'database',
                'port',
                $value,
                'must be a whole number between 1 and 65535',
            ),
        };

        $port = filter_var($digits, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false) {
            throw ConfigurationException::invalidValue('database', 'port', $value, 'must be between 1 and 65535');
        }

        return $port;
    }

    /**
     * @throws ConfigurationException if sslMode is not null and not an accepted value.
     */
    private static function assertSslMode(?string $sslMode, string $field): void
    {
        if ($sslMode !== null && !in_array($sslMode, self::SSL_MODES, true)) {
            throw ConfigurationException::invalidValue(
                'database',
                $field,
                $sslMode,
                'must be null (leave the parameter out of the DSN) or one of: ' . implode(', ', self::SSL_MODES),
            );
        }
    }

    /**
     * @throws ConfigurationException if value is empty or holds DSN syntax or a control character.
     */
    private static function assertDsnSafeValue(
        #[\SensitiveParameter] string $value,
        string $field,
        bool $singleHost = false,
    ): void {
        if ($singleHost && $value === '') {
            throw ConfigurationException::invalidValue(
                'database',
                $field,
                $value,
                'must not be empty: give DB_HOST a value (an empty variable does not fall back to its !env default) '
                    . 'or remove the key to use localhost',
            );
        }

        $forbidden = $singleHost ? ',' : '';
        // Space and \x00-\x1F cover every ASCII whitespace character; \s would vary with the locale.
        $pattern = '/^[^ ;=\'"\\\\\x00-\x1F\x7F' . $forbidden . ']+$/Du';

        if (preg_match($pattern, $value) !== 1) {
            throw ConfigurationException::invalidValue(
                'database',
                $field,
                self::withoutCredentials($value),
                'must be non-empty, valid UTF-8 and must not contain ASCII whitespace, semicolons, equals signs, '
                    . 'quotes, backslashes or control characters, any of which would truncate or extend the DSN'
                    . ($singleHost && str_contains($value, ',')
                        ? '; it must be a single host name or address, not a comma-separated list'
                        : ''),
            );
        }

        if ($singleHost && str_contains($value, '@')) {
            throw ConfigurationException::invalidValue(
                'database',
                $field,
                self::withoutCredentials($value),
                'must be a host name, an IP address or a socket directory, without a user name or password',
            );
        }
    }

    /**
     * The value with everything after a password= key (any case) replaced by ***, then everything before its
     * last remaining @ (after a URL scheme).
     *
     * @internal
     */
    public static function withoutCredentials(#[\SensitiveParameter] string $value): string
    {
        // The password mask runs first: a password holding @ would otherwise lose its key to the userinfo mask.
        return preg_replace(
            [self::PASSWORD_VALUE_PATTERN, '#^([A-Za-z][A-Za-z0-9+.\-]*://)?.*@#s'],
            ['$1***', '$1***@'],
            $value,
        ) ?? '***';
    }

    /**
     * The message with everything after a password= key (any case), then the user information of every URL,
     * replaced by ***. A plain user@domain stays readable.
     *
     * @internal
     */
    public static function messageWithoutCredentials(#[\SensitiveParameter] string $message): string
    {
        return preg_replace(
            [self::PASSWORD_VALUE_PATTERN, '#([A-Za-z][A-Za-z0-9+.\-]*://)[^\s"\']*@#'],
            ['$1***', '$1***@'],
            $message,
        ) ?? '***';
    }

    /**
     * Reads an optional string setting.
     *
     * @param string $hint Appended to the refusal message.
     * @return array{string, ?string} The key read (the preferred spelling when none is set) and its
     *                                trimmed value, null when absent or blank.
     * @throws ConfigurationException if the value is neither a string nor an integer.
     */
    private static function optionalSetting(
        #[\SensitiveParameter] ConfigKeys $keys,
        string $property,
        string $hint = '',
    ): array {
        $key = $keys->key($property);
        $value = $keys->value($property);
        if ($value === null) {
            return [$key, null];
        }

        if (!is_string($value) && !is_int($value)) {
            throw ConfigurationException::invalidValue(
                'database',
                $key,
                $value,
                'must be a string or an integer' . $hint,
            );
        }

        $normalized = trim((string) $value);

        return [$key, $normalized === '' ? null : $normalized];
    }
}
