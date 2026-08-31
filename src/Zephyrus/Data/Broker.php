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
 *   - Every method accepts and returns stdClass objects or scalars;
 *     no ORM magic, no ActiveRecord coupling.
 *   - Transactions are composable via the transaction() helper.
 *
 * Example:
 *
 *   final class UserBroker extends Broker
 *   {
 *       public function findById(int $id): ?\stdClass
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
     * an array of stdClass objects.
     *
     * @param array<int|string, mixed> $params
     * @return \stdClass[]
     * @throws DatabaseException on query failure.
     */
    protected function select(string $sql, array $params = []): array
    {
        return $this->db->select($sql, $params);
    }

    /**
     * Execute a query and return the first row, or null when no rows match.
     *
     * @param array<int|string, mixed> $params
     * @throws DatabaseException on query failure.
     */
    protected function selectOne(string $sql, array $params = []): ?\stdClass
    {
        return $this->db->selectOne($sql, $params);
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
        return $this->db->selectValue($sql, $params, $default);
    }

    /**
     * Execute a scalar query and return the value cast to int.
     *
     * @param array<int|string, mixed> $params
     */
    protected function selectInt(string $sql, array $params = [], int $default = 0): int
    {
        return $this->db->selectInt($sql, $params, $default);
    }

    /**
     * Execute a scalar query and return the value cast to string or null.
     *
     * @param array<int|string, mixed> $params
     */
    protected function selectString(string $sql, array $params = [], ?string $default = null): ?string
    {
        return $this->db->selectString($sql, $params, $default);
    }

    /**
     * Execute a scalar query and return the value cast to bool.
     *
     * @param array<int|string, mixed> $params
     */
    protected function selectBool(string $sql, array $params = [], bool $default = false): bool
    {
        return $this->db->selectBool($sql, $params, $default);
    }

    /**
     * Execute a scalar query and return the value cast to float.
     *
     * @param array<int|string, mixed> $params
     */
    protected function selectFloat(string $sql, array $params = [], float $default = 0.0): float
    {
        return $this->db->selectFloat($sql, $params, $default);
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
        return $this->db->count($sql, $params);
    }

    /**
     * Execute a paginated SELECT query by applying LIMIT/OFFSET.
     *
     * @param array<int|string, mixed> $params
     * @return \stdClass[]
     */
    protected function selectPage(string $sql, int $page, int $perPage, array $params = []): array
    {
        return $this->db->selectPage($sql, $page, $perPage, $params);
    }

    /**
     * Execute a paginated SELECT query using a PaginationRequest.
     *
     * @param array<int|string, mixed> $params
     * @return \stdClass[]
     */
    protected function selectPageWith(string $sql, PaginationRequest $pagination, array $params = []): array
    {
        return $this->db->selectPageWith($sql, $pagination, $params);
    }

    /**
     * Execute a sorted SELECT query.
     *
     * @param array<int|string, mixed> $params
     * @return \stdClass[]
     */
    protected function selectSorted(string $sql, SortRequest $sort, array $params = []): array
    {
        return $this->db->selectSorted($sql, $sort, $params);
    }

    /**
     * Execute a filtered SELECT query using FilterRequest column mapping.
     *
     * @param array<string, string> $columnMap
     * @param array<int|string, mixed> $params
     * @return \stdClass[]
     */
    protected function selectFiltered(string $sql, FilterRequest $filter, array $columnMap, array $params = []): array
    {
        return $this->db->selectFiltered($sql, $filter, $columnMap, $params);
    }

    /**
     * Execute a filtered + sorted SELECT query.
     *
     * @param array<string, string> $columnMap
     * @param array<int|string, mixed> $params
     * @return \stdClass[]
     */
    protected function selectFilteredSorted(
        string $sql,
        FilterRequest $filter,
        array $columnMap,
        SortRequest $sort,
        array $params = [],
    ): array {
        return $this->db->selectFilteredSorted($sql, $filter, $columnMap, $sort, $params);
    }

    /**
     * Execute a sorted paginated SELECT query.
     *
     * @param array<int|string, mixed> $params
     * @return \stdClass[]
     */
    protected function selectPageSorted(string $sql, SortRequest $sort, PaginationRequest $pagination, array $params = []): array
    {
        return $this->db->selectPageSorted($sql, $sort, $pagination, $params);
    }

    /**
     * Execute coordinated count + paginated data queries.
     *
     * @param array<int|string, mixed> $params
     * @return array{items: \stdClass[], total: int, page: int, per_page: int, total_pages: int, has_previous: bool, has_next: bool}
     */
    protected function paginate(string $dataSql, string $countSql, int $page, int $perPage, array $params = []): array
    {
        return $this->db->paginate($dataSql, $countSql, $page, $perPage, $params);
    }

    /**
     * Execute coordinated count + paginated data queries with PaginationRequest.
     *
     * @param array<int|string, mixed> $params
     * @return array{items: \stdClass[], total: int, page: int, per_page: int, total_pages: int, has_previous: bool, has_next: bool}
     */
    protected function paginateWith(string $dataSql, string $countSql, PaginationRequest $pagination, array $params = []): array
    {
        return $this->db->paginateWith($dataSql, $countSql, $pagination, $params);
    }

    /**
     * Execute coordinated count + sorted paginated data queries.
     *
     * @param array<int|string, mixed> $params
     * @return array{items: \stdClass[], total: int, page: int, per_page: int, total_pages: int, has_previous: bool, has_next: bool}
     */
    protected function paginateSortedWith(
        string $dataSql,
        string $countSql,
        SortRequest $sort,
        PaginationRequest $pagination,
        array $params = [],
    ): array {
        return $this->db->paginateSortedWith($dataSql, $countSql, $sort, $pagination, $params);
    }

    /**
     * Execute coordinated count + paginated data queries and return object envelope.
     *
     * @param array<int|string, mixed> $params
     * @return PaginatedResult<\stdClass>
     */
    protected function paginateResult(string $dataSql, string $countSql, int $page, int $perPage, array $params = []): PaginatedResult
    {
        return $this->db->paginateResult($dataSql, $countSql, $page, $perPage, $params);
    }

    /**
     * Execute coordinated count + paginated data queries and return object envelope.
     *
     * @param array<int|string, mixed> $params
     * @return PaginatedResult<\stdClass>
     */
    protected function paginateResultWith(string $dataSql, string $countSql, PaginationRequest $pagination, array $params = []): PaginatedResult
    {
        return $this->db->paginateResultWith($dataSql, $countSql, $pagination, $params);
    }

    /**
     * Execute paginated query and map each item through a transformer.
     *
     * @param callable(\stdClass): mixed $mapper
     * @param array<int|string, mixed> $params
     * @return PaginatedResult<\stdClass>
     */
    protected function paginateResultMapped(
        string $dataSql,
        string $countSql,
        int $page,
        int $perPage,
        callable $mapper,
        array $params = [],
    ): PaginatedResult {
        return $this->db->paginateResultMapped($dataSql, $countSql, $page, $perPage, $mapper, $params);
    }

    /**
     * Execute paginated query using PaginationRequest and map each item.
     *
     * @param callable(\stdClass): mixed $mapper
     * @param array<int|string, mixed> $params
     * @return PaginatedResult<\stdClass>
     */
    protected function paginateResultMappedWith(
        string $dataSql,
        string $countSql,
        PaginationRequest $pagination,
        callable $mapper,
        array $params = [],
    ): PaginatedResult {
        return $this->db->paginateResultMappedWith($dataSql, $countSql, $pagination, $mapper, $params);
    }

    /**
     * Build pagination request from query params then execute typed pagination.
     *
     * @param array<string, mixed> $query
     * @param array<int|string, mixed> $params
     * @return PaginatedResult<\stdClass>
     */
    protected function paginateResultFromQuery(
        string $dataSql,
        string $countSql,
        array $query,
        int $defaultPerPage = 25,
        int $maxPerPage = 100,
        array $params = [],
    ): PaginatedResult {
        return $this->db->paginateResultFromQuery(
            $dataSql,
            $countSql,
            $query,
            $defaultPerPage,
            $maxPerPage,
            $params,
        );
    }

    /**
     * Execute coordinated count + sorted paginated data queries and return typed envelope.
     *
     * @param array<int|string, mixed> $params
     * @return PaginatedResult<\stdClass>
     */
    protected function paginateSortedResultWith(
        string $dataSql,
        string $countSql,
        SortRequest $sort,
        PaginationRequest $pagination,
        array $params = [],
    ): PaginatedResult {
        return $this->db->paginateSortedResultWith($dataSql, $countSql, $sort, $pagination, $params);
    }

    /**
     * Execute filtered + sorted pagination using shared WHERE bindings.
     *
     * @param array<string, string> $columnMap
     * @param array<int|string, mixed> $params
     * @return PaginatedResult<\stdClass>
     */
    protected function paginateFilteredSortedResultWith(
        string $dataSql,
        string $countSql,
        FilterRequest $filter,
        array $columnMap,
        SortRequest $sort,
        PaginationRequest $pagination,
        array $params = [],
    ): PaginatedResult {
        return $this->db->paginateFilteredSortedResultWith(
            $dataSql,
            $countSql,
            $filter,
            $columnMap,
            $sort,
            $pagination,
            $params,
        );
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
        return $this->db->execute($sql, $params);
    }

    /**
     * Execute an INSERT and return affected row count.
     *
     * @param array<int|string, mixed> $params
     */
    protected function insertRow(string $sql, array $params = []): int
    {
        return $this->db->insert($sql, $params);
    }

    /**
     * Execute an INSERT and return the generated identifier.
     *
     * @param array<int|string, mixed> $params
     */
    protected function insertRowGetId(string $sql, array $params = []): string|false
    {
        return $this->db->insertGetId($sql, $params);
    }

    /**
     * Execute an UPDATE and return affected row count.
     *
     * @param array<int|string, mixed> $params
     */
    protected function updateRows(string $sql, array $params = []): int
    {
        return $this->db->update($sql, $params);
    }

    /**
     * Execute a DELETE and return affected row count.
     *
     * @param array<int|string, mixed> $params
     */
    protected function deleteRows(string $sql, array $params = []): int
    {
        return $this->db->delete($sql, $params);
    }

    /**
     * Execute an existence query and return true when at least one row matches.
     *
     * @param array<int|string, mixed> $params
     */
    protected function exists(string $sql, array $params = []): bool
    {
        return $this->db->exists($sql, $params);
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
