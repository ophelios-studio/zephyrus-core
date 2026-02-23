<?php

declare(strict_types=1);

namespace Zephyrus\Data;

use PDO;
use PDOException;
use PDOStatement;
use Zephyrus\Core\Config\DatabaseConfig;

/**
 * Thin PDO wrapper that provides:
 *   - A clean construction path from DatabaseConfig or a pre-built PDO.
 *   - Uniform exception wrapping (DatabaseException instead of raw PDOException).
 *   - A transaction() helper that commits on success and rolls back on failure.
 *   - A query() helper that prepares + binds parameters and returns the statement.
 *
 * Testability: inject a pre-built PDO (e.g. sqlite::memory:) via the
 * constructor; use fromConfig() for production usage.
 */
final class Database
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    /**
     * Build a Database instance by opening a MySQL/MariaDB connection described
     * by the given DatabaseConfig.
     *
     * @param null|callable(string, string, string, array<int, mixed>): PDO $pdoFactory
     *        Optional PDO factory for tests/advanced callers. Receives
     *        ($dsn, $username, $password, $options) and must return a PDO.
     *
     * @throws DatabaseException on PDO connection failure.
     */
    public static function fromConfig(DatabaseConfig $config, ?callable $pdoFactory = null): self
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config->host,
            $config->port,
            $config->database,
            $config->charset,
        );

        $options = [
            PDO::ATTR_PERSISTENT => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$config->charset}",
        ];

        $factory = $pdoFactory ?? static fn (string $dsn, string $username, string $password, array $options): PDO
            => new PDO($dsn, $username, $password, $options);

        try {
            $pdo = $factory($dsn, $config->username, $config->password, $options);
        } catch (\Throwable $e) {
            throw DatabaseException::connectionFailed($dsn, $e->getMessage());
        }

        return new self($pdo);
    }

    /**
     * Execute a prepared statement with positional or named placeholders.
     *
     * @param array<int|string, mixed> $params
     * @throws DatabaseException on prepare or execution failure.
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt;
        } catch (PDOException $e) {
            throw DatabaseException::queryFailed($sql, $e->getMessage());
        }
    }

    /**
     * Execute a SELECT and return all rows as associative arrays.
     *
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * Execute a query and return the first row or null when no rows match.
     *
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Execute a scalar query and return the first column of the first row.
     *
     * Returns $default when no row is returned.
     *
     * @param array<int|string, mixed> $params
     */
    public function selectValue(string $sql, array $params = [], mixed $default = null): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();

        return $value === false ? $default : $value;
    }

    /**
     * Execute a scalar query and return the value cast to int.
     *
     * @param array<int|string, mixed> $params
     */
    public function selectInt(string $sql, array $params = [], int $default = 0): int
    {
        return (int) $this->selectValue($sql, $params, $default);
    }

    /**
     * Execute a scalar query and return the value cast to string (or null).
     *
     * @param array<int|string, mixed> $params
     */
    public function selectString(string $sql, array $params = [], ?string $default = null): ?string
    {
        $value = $this->selectValue($sql, $params, $default);

        return $value === null ? null : (string) $value;
    }

    /**
     * Execute a scalar query and return the value cast to bool.
     *
     * @param array<int|string, mixed> $params
     */
    public function selectBool(string $sql, array $params = [], bool $default = false): bool
    {
        return (bool) $this->selectValue($sql, $params, $default);
    }

    /**
     * Execute a scalar query and return the value cast to float.
     *
     * @param array<int|string, mixed> $params
     */
    public function selectFloat(string $sql, array $params = [], float $default = 0.0): float
    {
        return (float) $this->selectValue($sql, $params, $default);
    }

    /**
     * Execute a count-style scalar query and return an int.
     *
     * @param array<int|string, mixed> $params
     */
    public function count(string $sql, array $params = []): int
    {
        return $this->selectInt($sql, $params, 0);
    }

    /**
     * Execute a paginated SELECT query by applying LIMIT/OFFSET.
     *
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function selectPage(string $sql, int $page, int $perPage, array $params = []): array
    {
        if ($page < 1) {
            throw DatabaseException::queryFailed($sql, 'Page must be >= 1');
        }

        if ($perPage < 1) {
            throw DatabaseException::queryFailed($sql, 'Per-page must be >= 1');
        }

        $offset = ($page - 1) * $perPage;

        return $this->select(
            $sql . sprintf(' LIMIT %d OFFSET %d', $perPage, $offset),
            $params,
        );
    }

    /**
     * Execute coordinated count + paginated data queries.
     *
     * @param array<int|string, mixed> $params
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int, has_previous: bool, has_next: bool}
     */
    public function paginate(string $dataSql, string $countSql, int $page, int $perPage, array $params = []): array
    {
        if ($page < 1) {
            throw DatabaseException::queryFailed($dataSql, 'Page must be >= 1');
        }

        if ($perPage < 1) {
            throw DatabaseException::queryFailed($dataSql, 'Per-page must be >= 1');
        }

        $total = $this->count($countSql, $params);
        $items = $this->selectPage($dataSql, $page, $perPage, $params);
        $totalPages = max(1, (int) ceil($total / $perPage));

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'has_previous' => $page > 1,
            'has_next' => $page < $totalPages,
        ];
    }

    /**
     * Execute a write query and return affected row count.
     *
     * @param array<int|string, mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    /**
     * Execute an INSERT and return affected row count.
     *
     * @param array<int|string, mixed> $params
     */
    public function insert(string $sql, array $params = []): int
    {
        return $this->execute($sql, $params);
    }

    /**
     * Execute an INSERT and return the last inserted ID.
     *
     * @param array<int|string, mixed> $params
     */
    public function insertGetId(string $sql, array $params = []): string|false
    {
        $this->insert($sql, $params);

        return $this->lastInsertId();
    }

    /**
     * Execute an UPDATE and return affected row count.
     *
     * @param array<int|string, mixed> $params
     */
    public function update(string $sql, array $params = []): int
    {
        return $this->execute($sql, $params);
    }

    /**
     * Execute a DELETE and return affected row count.
     *
     * @param array<int|string, mixed> $params
     */
    public function delete(string $sql, array $params = []): int
    {
        return $this->execute($sql, $params);
    }

    /**
     * Execute an existence check query and return true when first column is truthy.
     *
     * @param array<int|string, mixed> $params
     */
    public function exists(string $sql, array $params = []): bool
    {
        return $this->selectBool($sql, $params, false);
    }

    /**
     * Run $work inside a database transaction.
     *
     * Commits if $work returns without throwing, rolls back otherwise.
     * The return value of $work is forwarded to the caller.
     *
     * Nested calls re-use the existing transaction (no savepoints).
     *
     * @throws DatabaseException when the DB itself fails to begin/commit/rollback.
     * @throws \Throwable re-throws any exception thrown inside $work after rolling back.
     */
    public function transaction(callable $work): mixed
    {
        $ownTransaction = !$this->pdo->inTransaction();

        if ($ownTransaction) {
            try {
                $this->pdo->beginTransaction();
            } catch (PDOException $e) {
                throw DatabaseException::transactionFailed("begin: {$e->getMessage()}");
            }
        }

        try {
            $result = $work($this);
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                try {
                    $this->pdo->rollBack();
                } catch (PDOException $rollbackEx) {
                    throw DatabaseException::transactionFailed(
                        "rollback after error: {$rollbackEx->getMessage()}"
                    );
                }
            }

            throw $e;
        }

        if ($ownTransaction) {
            try {
                $this->pdo->commit();
            } catch (PDOException $commitEx) {
                if ($this->pdo->inTransaction()) {
                    try {
                        $this->pdo->rollBack();
                    } catch (PDOException $rollbackEx) {
                        throw DatabaseException::transactionFailed(sprintf(
                            'commit: %s; rollback after commit failure: %s',
                            $commitEx->getMessage(),
                            $rollbackEx->getMessage(),
                        ));
                    }
                }

                throw DatabaseException::transactionFailed("commit: {$commitEx->getMessage()}");
            }
        }

        return $result;
    }

    /**
     * Return the last auto-increment ID generated by an INSERT.
     */
    public function lastInsertId(): string|false
    {
        return $this->pdo->lastInsertId();
    }

    /**
     * Report whether the underlying connection currently has an active transaction.
     */
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * Expose the underlying PDO for advanced callers (e.g. schema migrations).
     * Prefer the typed helpers for normal query work.
     */
    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
