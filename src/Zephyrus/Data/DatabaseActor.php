<?php

declare(strict_types=1);

namespace Zephyrus\Data;

use PDO;
use SensitiveParameterValue;

/**
 * Publishes the actor that row level security policies read, for one transaction only.
 *
 * Each declared key is the setting "<prefix>.<key>", set with set_config(..., true): it ends with the outermost
 * transaction, so it never reaches the next request, nor another client behind a pooler in transaction mode.
 * A policy reads it with nullif(current_setting('<prefix>.<key>', true), ''). Publishing protects nothing by
 * itself: a superuser, a role with BYPASSRLS and the owner of a table without FORCE ROW LEVEL SECURITY see every
 * row whatever the actor.
 *
 * A second actor is refused only when one of the keys declared by this instance is already set: two instances with
 * different keys on one Database stack, so declare every key your policies read in a single instance.
 */
final class DatabaseActor
{
    private const string SETTING_NAME_PATTERN = '/\A[a-z_][a-z0-9_]{0,62}\z/';

    private const string SET_FOR_THIS_TRANSACTION = 'set_config(?, ?, true)';

    private const string SETTING_IS_UNSET = "nullif(current_setting(?, true), '') IS NULL";

    /** @var array<string, string> each declared key mapped to its setting name */
    private readonly array $settings;

    /**
     * @param list<string> $keys the keys a publication may set, each under $prefix
     * @throws DatabaseActorException when $db is not a PostgreSQL connection, the prefix or a key is not a valid
     *         setting name, no key is given or a key is declared twice.
     */
    public function __construct(private readonly Database $db, string $prefix, array $keys)
    {
        if ($db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw DatabaseActorException::postgresRequired();
        }

        $this->settings = self::settingNames($prefix, $keys);
    }

    /**
     * Opens a transaction, publishes $values in it, runs $work and commits: the actor ends with the transaction.
     *
     * @template T
     * @param array<string, string> $values the declared keys to publish, each mapped to a non-empty value
     * @param callable(Database): T $work
     * @return T
     * @throws DatabaseActorException when a value is refused, a transaction is already open, or an actor is
     *         already published on the connection.
     * @throws DatabaseException when the database fails to begin, publish or commit.
     * @throws \InvalidArgumentException when a value holds a NUL byte.
     * @throws \Throwable whatever $work throws, once the transaction is rolled back.
     */
    public function actAs(#[\SensitiveParameter] array $values, callable $work): mixed
    {
        $this->assertPublishable($values);
        if ($this->db->inTransaction()) {
            throw DatabaseActorException::transactionAlreadyOpen();
        }

        // Captured wrapped: a debug page renders the variables a closure captures.
        $maskedValues = new SensitiveParameterValue($values);

        return $this->db->transaction(function (Database $db) use ($maskedValues, $work): mixed {
            $this->set($maskedValues->getValue());

            return $work($db);
        });
    }

    /**
     * Publishes $values in the open transaction, until the outermost transaction ends; a savepoint rolled back
     * takes them back.
     *
     * @param array<string, string> $values the declared keys to publish, each mapped to a non-empty value
     * @throws DatabaseActorException when a value is refused, no transaction is open, or an actor is already
     *         published on the connection.
     * @throws DatabaseException when the statement fails.
     * @throws \InvalidArgumentException when a value holds a NUL byte.
     */
    public function publish(#[\SensitiveParameter] array $values): void
    {
        $this->assertPublishable($values);
        if (!$this->db->inTransaction()) {
            throw DatabaseActorException::transactionRequired();
        }

        $this->set($values);
    }

    /**
     * @param array<mixed> $keys
     * @return array<string, string>
     */
    private static function settingNames(string $prefix, array $keys): array
    {
        if (!self::isSettingName($prefix)) {
            throw DatabaseActorException::invalidPrefix($prefix);
        }

        if ($keys === []) {
            throw DatabaseActorException::noKeys();
        }

        $settings = [];
        foreach ($keys as $key) {
            if (!is_string($key) || !self::isSettingName($key)) {
                throw DatabaseActorException::invalidKey($key);
            }

            if (isset($settings[$key])) {
                throw DatabaseActorException::duplicateKey($key);
            }

            $settings[$key] = $prefix . '.' . $key;
        }

        return $settings;
    }

    private static function isSettingName(string $name): bool
    {
        return preg_match(self::SETTING_NAME_PATTERN, $name) === 1;
    }

    /**
     * @param array<mixed> $values
     */
    private function assertPublishable(#[\SensitiveParameter] array $values): void
    {
        if ($values === []) {
            throw DatabaseActorException::noValues();
        }

        foreach ($values as $key => $value) {
            if (!is_string($key) || !isset($this->settings[$key])) {
                throw is_string($key) && self::isSettingName($key)
                    ? DatabaseActorException::undeclaredKey($key, array_keys($this->settings))
                    : DatabaseActorException::keyNotASettingName(get_debug_type($key), array_keys($this->settings));
            }

            if (!is_string($value)) {
                throw DatabaseActorException::valueNotString($key, get_debug_type($value));
            }

            if ($value === '') {
                throw DatabaseActorException::emptyValue($key);
            }
        }
    }

    /**
     * Sets every value in one statement, which sets none when a declared setting already holds a value.
     *
     * @param array<string, string> $values
     */
    private function set(#[\SensitiveParameter] array $values): void
    {
        $assignments = [];
        $params = [];
        foreach ($values as $key => $value) {
            $assignments[] = self::SET_FOR_THIS_TRANSACTION;
            $params[] = $this->settings[$key];
            $params[] = $value;
        }

        $sql = sprintf(
            'SELECT %s WHERE %s',
            implode(', ', $assignments),
            implode(' AND ', array_fill(0, count($this->settings), self::SETTING_IS_UNSET)),
        );

        if ($this->db->execute($sql, [...$params, ...array_values($this->settings)]) !== 1) {
            throw DatabaseActorException::alreadyPublished(array_values($this->settings));
        }
    }
}
