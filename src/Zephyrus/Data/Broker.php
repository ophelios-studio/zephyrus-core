<?php

declare(strict_types=1);

namespace Zephyrus\Data;

/**
 * Base class for domain data brokers: the SQL of one domain area lives in a subclass.
 *
 * Methods take and return stdClass rows or scalars. Transactions compose through transaction().
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
 *       public function insert(string $name): string|false
 *       {
 *           return $this->insertRowGetId('INSERT INTO users (name) VALUES (?) RETURNING id', [$name]);
 *       }
 *   }
 */
abstract class Broker
{
    public function __construct(protected readonly Database $db)
    {
    }

    /**
     * Execute a multi-row query and return all rows.
     *
     * @param array<int|string, mixed> $params
     * @return \stdClass[]
     * @throws DatabaseException on query failure.
     */
    protected function select(string $sql, #[\SensitiveParameter] array $params = []): array
    {
        return $this->db->select($sql, $params);
    }

    /**
     * Execute a query and return the first row, or null when no rows match.
     *
     * @param array<int|string, mixed> $params
     * @throws DatabaseException on query failure.
     */
    protected function selectOne(string $sql, #[\SensitiveParameter] array $params = []): ?\stdClass
    {
        return $this->db->selectOne($sql, $params);
    }

    /**
     * Return the first column of the first row, or $default when the query yields no rows.
     *
     * @param array<int|string, mixed> $params
     * @throws DatabaseException on query failure.
     */
    protected function selectValue(string $sql, #[\SensitiveParameter] array $params = [], mixed $default = null): mixed
    {
        return $this->db->selectValue($sql, $params, $default);
    }

    /**
     * Return the first column of the first row cast to int.
     *
     * @param array<int|string, mixed> $params
     */
    protected function selectInt(string $sql, #[\SensitiveParameter] array $params = [], int $default = 0): int
    {
        return $this->db->selectInt($sql, $params, $default);
    }

    /**
     * Return the first column of the first row cast to string, or null.
     *
     * @param array<int|string, mixed> $params
     */
    protected function selectString(string $sql, #[\SensitiveParameter] array $params = [], ?string $default = null): ?string
    {
        return $this->db->selectString($sql, $params, $default);
    }

    /**
     * Return the first column of the first row cast to bool.
     *
     * @param array<int|string, mixed> $params
     */
    protected function selectBool(string $sql, #[\SensitiveParameter] array $params = [], bool $default = false): bool
    {
        return $this->db->selectBool($sql, $params, $default);
    }

    /**
     * Return the first column of the first row cast to float.
     *
     * @param array<int|string, mixed> $params
     */
    protected function selectFloat(string $sql, #[\SensitiveParameter] array $params = [], float $default = 0.0): float
    {
        return $this->db->selectFloat($sql, $params, $default);
    }

    /**
     * Return the first column of the first row of an aggregate query, cast to int.
     *
     * @param array<int|string, mixed> $params
     * @throws DatabaseException on query failure.
     */
    protected function selectCount(string $sql, #[\SensitiveParameter] array $params = []): int
    {
        return $this->db->count($sql, $params);
    }

    /**
     * Execute a SELECT limited to one page with LIMIT/OFFSET.
     *
     * @param array<int|string, mixed> $params
     * @return \stdClass[]
     */
    protected function selectPage(string $sql, int $page, int $perPage, #[\SensitiveParameter] array $params = []): array
    {
        return $this->db->selectPage($sql, $page, $perPage, $params);
    }

    /**
     * Execute a SELECT limited to the page described by a PaginationRequest.
     *
     * @param array<int|string, mixed> $params
     * @return \stdClass[]
     */
    protected function selectPageWith(string $sql, PaginationRequest $pagination, #[\SensitiveParameter] array $params = []): array
    {
        return $this->db->selectPageWith($sql, $pagination, $params);
    }

    /**
     * Execute a SELECT ordered by a SortRequest.
     *
     * @param array<int|string, mixed> $params
     * @return \stdClass[]
     */
    protected function selectSorted(string $sql, SortRequest $sort, #[\SensitiveParameter] array $params = []): array
    {
        return $this->db->selectSorted($sql, $sort, $params);
    }

    /**
     * Execute a SELECT filtered through a FilterRequest and its column map.
     *
     * @param array<string, string> $columnMap
     * @param array<int|string, mixed> $params
     * @return \stdClass[]
     */
    protected function selectFiltered(string $sql, FilterRequest $filter, array $columnMap, #[\SensitiveParameter] array $params = []): array
    {
        return $this->db->selectFiltered($sql, $filter, $columnMap, $params);
    }

    /**
     * Execute a filtered and sorted SELECT.
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
        #[\SensitiveParameter] array $params = [],
    ): array {
        return $this->db->selectFilteredSorted($sql, $filter, $columnMap, $sort, $params);
    }

    /**
     * Execute a sorted SELECT limited to one page.
     *
     * @param array<int|string, mixed> $params
     * @return \stdClass[]
     */
    protected function selectPageSorted(string $sql, SortRequest $sort, PaginationRequest $pagination, #[\SensitiveParameter] array $params = []): array
    {
        return $this->db->selectPageSorted($sql, $sort, $pagination, $params);
    }

    /**
     * Run a count query and a page query, returning the page with its totals.
     *
     * @param array<int|string, mixed> $params
     * @return array{items: \stdClass[], total: int, page: int, per_page: int, total_pages: int, has_previous: bool, has_next: bool}
     */
    protected function paginate(string $dataSql, string $countSql, int $page, int $perPage, #[\SensitiveParameter] array $params = []): array
    {
        return $this->db->paginate($dataSql, $countSql, $page, $perPage, $params);
    }

    /**
     * Same as paginate(), with the page described by a PaginationRequest.
     *
     * @param array<int|string, mixed> $params
     * @return array{items: \stdClass[], total: int, page: int, per_page: int, total_pages: int, has_previous: bool, has_next: bool}
     */
    protected function paginateWith(string $dataSql, string $countSql, PaginationRequest $pagination, #[\SensitiveParameter] array $params = []): array
    {
        return $this->db->paginateWith($dataSql, $countSql, $pagination, $params);
    }

    /**
     * Same as paginateWith(), with the data query sorted by a SortRequest.
     *
     * @param array<int|string, mixed> $params
     * @return array{items: \stdClass[], total: int, page: int, per_page: int, total_pages: int, has_previous: bool, has_next: bool}
     */
    protected function paginateSortedWith(
        string $dataSql,
        string $countSql,
        SortRequest $sort,
        PaginationRequest $pagination,
        #[\SensitiveParameter] array $params = [],
    ): array {
        return $this->db->paginateSortedWith($dataSql, $countSql, $sort, $pagination, $params);
    }

    /**
     * Run a count query and a page query, returning a PaginatedResult.
     *
     * @param array<int|string, mixed> $params
     * @return PaginatedResult<\stdClass>
     */
    protected function paginateResult(string $dataSql, string $countSql, int $page, int $perPage, #[\SensitiveParameter] array $params = []): PaginatedResult
    {
        return $this->db->paginateResult($dataSql, $countSql, $page, $perPage, $params);
    }

    /**
     * Same as paginateResult(), with the page described by a PaginationRequest.
     *
     * @param array<int|string, mixed> $params
     * @return PaginatedResult<\stdClass>
     */
    protected function paginateResultWith(string $dataSql, string $countSql, PaginationRequest $pagination, #[\SensitiveParameter] array $params = []): PaginatedResult
    {
        return $this->db->paginateResultWith($dataSql, $countSql, $pagination, $params);
    }

    /**
     * Same as paginateResult(), mapping each item through $mapper.
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
        #[\SensitiveParameter] array $params = [],
    ): PaginatedResult {
        return $this->db->paginateResultMapped($dataSql, $countSql, $page, $perPage, $mapper, $params);
    }

    /**
     * Same as paginateResultWith(), mapping each item through $mapper.
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
        #[\SensitiveParameter] array $params = [],
    ): PaginatedResult {
        return $this->db->paginateResultMappedWith($dataSql, $countSql, $pagination, $mapper, $params);
    }

    /**
     * Build the pagination request from query parameters, then paginate.
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
        #[\SensitiveParameter] array $params = [],
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
     * Same as paginateResultWith(), with the data query sorted by a SortRequest.
     *
     * @param array<int|string, mixed> $params
     * @return PaginatedResult<\stdClass>
     */
    protected function paginateSortedResultWith(
        string $dataSql,
        string $countSql,
        SortRequest $sort,
        PaginationRequest $pagination,
        #[\SensitiveParameter] array $params = [],
    ): PaginatedResult {
        return $this->db->paginateSortedResultWith($dataSql, $countSql, $sort, $pagination, $params);
    }

    /**
     * Same as paginateSortedResultWith(), with the WHERE clause filtered by a FilterRequest.
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
        #[\SensitiveParameter] array $params = [],
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
     * Execute an INSERT, UPDATE or DELETE and return the affected row count.
     *
     * @param array<int|string, mixed> $params
     * @throws DatabaseException on query failure.
     */
    protected function execute(string $sql, #[\SensitiveParameter] array $params = []): int
    {
        return $this->db->execute($sql, $params);
    }

    /**
     * Execute an INSERT and return the affected row count.
     *
     * @param array<int|string, mixed> $params
     */
    protected function insertRow(string $sql, #[\SensitiveParameter] array $params = []): int
    {
        return $this->db->insert($sql, $params);
    }

    /**
     * Execute an INSERT ... RETURNING id and return the generated identifier, or false when no row came back.
     *
     * @param array<int|string, mixed> $params
     * @throws DatabaseException see Database::insertGetId() for the driver-dependent refusals.
     */
    protected function insertRowGetId(string $sql, #[\SensitiveParameter] array $params = []): string|false
    {
        return $this->db->insertGetId($sql, $params);
    }

    /**
     * Execute an UPDATE and return the affected row count.
     *
     * @param array<int|string, mixed> $params
     */
    protected function updateRows(string $sql, #[\SensitiveParameter] array $params = []): int
    {
        return $this->db->update($sql, $params);
    }

    /**
     * Execute a DELETE and return the affected row count.
     *
     * @param array<int|string, mixed> $params
     */
    protected function deleteRows(string $sql, #[\SensitiveParameter] array $params = []): int
    {
        return $this->db->delete($sql, $params);
    }

    /**
     * Return true when at least one row matches.
     *
     * @param array<int|string, mixed> $params
     */
    protected function exists(string $sql, #[\SensitiveParameter] array $params = []): bool
    {
        return $this->db->exists($sql, $params);
    }

    /**
     * Return the last generated ID of the connection. Refused on PostgreSQL: use insertRowGetId() with RETURNING instead.
     *
     * @throws DatabaseException on PostgreSQL, or when the driver cannot report the ID.
     */
    protected function lastInsertId(): string|false
    {
        return $this->db->lastInsertId();
    }

    /**
     * Run $work inside a transaction, delegating to Database::transaction().
     *
     * @template T
     * @param callable(Database): T $work
     * @return T
     * @throws DatabaseException on transaction boundary failure.
     * @throws \Throwable re-throws any exception thrown inside $work.
     */
    protected function transaction(callable $work): mixed
    {
        return $this->db->transaction($work);
    }
}
