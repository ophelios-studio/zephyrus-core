<?php

declare(strict_types=1);

namespace Zephyrus\Data;

/**
 * Abstract base for domain-specific data brokers.
 *
 * A broker is responsible for all database interactions for a single
 * entity/domain area. Extend this class to get typed query helpers
 * without repetitive PDO boilerplate.
 *
 * Design rules:
 *   - All SQL lives inside the broker subclass, not in controllers or
 *     service objects.
 *   - Every method accepts and returns plain PHP arrays or scalars;
 *     no ORM magic, no ActiveRecord coupling.
 *   - Transactions are composable via the transaction() helper.
 *
 * Example:
 *
 *   final class UserBroker extends Broker
 *   {
 *       public function findById(int $id): ?array
 *       {
 *           return $this->selectOne('SELECT * FROM users WHERE id = ?', [$id]);
 *       }
 *
 *       public function findAll(): array
 *       {
 *           return $this->select('SELECT * FROM users ORDER BY name');
 *       }
 *
 *       public function insert(array $data): int
 *       {
 *           $this->execute(
 *               'INSERT INTO users (name, email) VALUES (?, ?)',
 *               [$data['name'], $data['email']]
 *           );
 *           return (int) $this->lastInsertId();
 *       }
 *   }
 */
abstract class Broker
{
    public function __construct(protected readonly Database $db)
    {
    }

    /**
     * Execute a SELECT (or any multi-row query) and return all rows as
     * an array of associative arrays.
     *
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     * @throws DatabaseException on query failure.
     */
    protected function select(string $sql, array $params = []): array
    {
        return $this->db->query($sql, $params)->fetchAll();
    }

    /**
     * Execute a query and return the first row, or null when no rows match.
     *
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     * @throws DatabaseException on query failure.
     */
    protected function selectOne(string $sql, array $params = []): ?array
    {
        $row = $this->db->query($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Execute a scalar query and return the first column of the first row.
     *
     * Returns $default when the query yields no rows.
     *
     * @param array<int|string, mixed> $params
     * @throws DatabaseException on query failure.
     */
    protected function selectValue(string $sql, array $params = [], mixed $default = null): mixed
    {
        $value = $this->db->query($sql, $params)->fetchColumn();

        return $value === false ? $default : $value;
    }

    /**
     * Execute a scalar aggregate query (e.g. COUNT, SUM) and return the
     * first column of the first row cast to int.
     *
     * @param array<int|string, mixed> $params
     * @throws DatabaseException on query failure.
     */
    protected function selectCount(string $sql, array $params = []): int
    {
        return (int) $this->selectValue($sql, $params, 0);
    }

    /**
     * Execute an INSERT, UPDATE, or DELETE statement and return the number
     * of affected rows.
     *
     * @param array<int|string, mixed> $params
     * @throws DatabaseException on query failure.
     */
    protected function execute(string $sql, array $params = []): int
    {
        return $this->db->query($sql, $params)->rowCount();
    }

    /**
     * Return the last auto-increment ID produced by an INSERT in this
     * broker's connection.
     */
    protected function lastInsertId(): string|false
    {
        return $this->db->lastInsertId();
    }

    /**
     * Delegate to Database::transaction() so broker subclasses can open
     * transactions without holding a reference to the Database directly.
     *
     * @throws DatabaseException on transaction boundary failure.
     * @throws \Throwable re-throws any exception thrown inside $work.
     */
    protected function transaction(callable $work): mixed
    {
        return $this->db->transaction($work);
    }
}
