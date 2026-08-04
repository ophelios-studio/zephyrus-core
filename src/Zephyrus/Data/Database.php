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
    /** @var array<string, callable(string): mixed> */
    private array $typeConversions = [];

    /**
     * Memoized column-type resolutions keyed by SQL string.
     *
     * getColumnMeta() triggers backend metadata lookups on PostgreSQL
     * (a pg_class query per column, plus a pg_type query for non-builtin
     * OIDs), so resolving the same statement shape on every select() would
     * fire hundreds of identical round-trips over a request. The column
     * layout (name + native type) of a given SQL string is stable for the
     * connection lifetime, so the resolution is computed once per distinct
     * query and reused thereafter.
     *
     * @var array<string, array<string, callable(string): mixed>>
     */
    private array $columnTypeCache = [];

    /**
     * Built-in PostgreSQL native type conversions matching v1 DatabaseStatement behavior.
     * Integer types → intval, float/decimal types → floatval, boolean → boolval,
     * JSONB/JSON → json_decode, PostgreSQL arrays → PHP arrays.
     */
    private const BUILTIN_TYPE_CONVERSIONS = [
        // Integer types
        'INT2' => 'intval',
        'INT4' => 'intval',
        'INT8' => 'intval',
        'LONG' => 'intval',
        'LONGLONG' => 'intval',
        // Float/decimal types
        'FLOAT4' => 'floatval',
        'FLOAT8' => 'floatval',
        'NUMERIC' => 'floatval',
        'DECIMAL' => 'floatval',
        'NEWDECIMAL' => 'floatval',
        // Boolean
        'BOOL' => 'boolval',
    ];

    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ);
        $this->registerBuiltinTypeConversions();
    }

    /**
     * Register a callback to convert values from a PostgreSQL column type
     * (e.g. 'JSONB', 'JSON') before rows are returned from select methods.
     *
     * @param string $typeName PostgreSQL type name (case-insensitive, matched via pg_field_type).
     * @param callable(string): mixed $converter Receives the raw string value, returns converted value.
     */
    public function registerTypeConversion(string $typeName, callable $converter): void
    {
        $this->typeConversions[strtoupper($typeName)] = $converter;

        // A newly registered converter can change how already-seen column
        // shapes resolve, so drop the memoized resolutions to stay correct.
        // Registration happens at setup, not in hot paths, so this is cheap.
        $this->columnTypeCache = [];
    }

    /**
     * Build a Database instance by opening a PostgreSQL connection described
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
            'pgsql:host=%s;port=%d;dbname=%s',
            $config->host,
            $config->port,
            $config->database,
        );

        $options = [
            PDO::ATTR_PERSISTENT => false,
        ];

        $factory = $pdoFactory ?? static fn (string $dsn, string $username, string $password, array $options): PDO
            => new PDO($dsn, $username, $password, $options);

        try {
            $pdo = $factory($dsn, $config->username, $config->password, $options);
        } catch (\Throwable $e) {
            throw DatabaseException::connectionFailed($dsn, $e->getMessage());
        }

        $db = new self($pdo);

        // Set client encoding for the connection.
        try {
            $db->query(sprintf("SET client_encoding TO '%s'", $config->charset));
        } catch (DatabaseException) {
            // Encoding already set or not critical; proceed.
        }

        return $db;
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
     * Execute a SELECT and return all rows as stdClass objects.
     *
     * @param array<int|string, mixed> $params
     * @return \stdClass[]
     */
    public function select(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        $rows = $stmt->fetchAll();

        if ($this->typeConversions !== []) {
            $columnTypes = $this->resolveColumnTypes($sql, $stmt);
            foreach ($rows as $row) {
                $this->applyTypeConversions($row, $columnTypes);
            }
        }

        return $rows;
    }

    /**
     * Execute a query and return the first row or null when no rows match.
     *
     * @param array<int|string, mixed> $params
     */
    public function selectOne(string $sql, array $params = []): ?\stdClass
    {
        $stmt = $this->query($sql, $params);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        if ($this->typeConversions !== []) {
            $columnTypes = $this->resolveColumnTypes($sql, $stmt);
            $this->applyTypeConversions($row, $columnTypes);
        }

        return $row;
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
     * @return \stdClass[]
     */
    public function selectPage(string $sql, int $page, int $perPage, array $params = []): array
    {
        return $this->selectPageWith($sql, new PaginationRequest($page, $perPage), $params);
    }

    /**
     * Execute a paginated SELECT query using a PaginationRequest.
     *
     * @param array<int|string, mixed> $params
     * @return \stdClass[]
     */
    public function selectPageWith(string $sql, PaginationRequest $pagination, array $params = []): array
    {
        return $this->select(
            $sql . sprintf(' LIMIT %d OFFSET %d', $pagination->limit(), $pagination->offset()),
            $params,
        );
    }

    /**
     * Execute a sorted SELECT query.
     *
     * @param array<int|string, mixed> $params
     * @return \stdClass[]
     */
    public function selectSorted(string $sql, SortRequest $sort, array $params = []): array
    {
        return $this->select($sql . $sort->toSql(), $params);
    }

    /**
     * Execute a filtered SELECT query using FilterRequest column mapping.
     *
     * @param array<string, string> $columnMap
     * @param array<int|string, mixed> $params
     * @return \stdClass[]
     */
    public function selectFiltered(string $sql, FilterRequest $filter, array $columnMap, array $params = []): array
    {
        $where = $filter->toWhereClause($columnMap);

        return $this->select(
            $sql . $where['sql'],
            array_merge($params, $where['params']),
        );
    }

    /**
     * Execute a filtered + sorted SELECT query.
     *
     * @param array<string, string> $columnMap
     * @param array<int|string, mixed> $params
     * @return \stdClass[]
     */
    public function selectFilteredSorted(
        string $sql,
        FilterRequest $filter,
        array $columnMap,
        SortRequest $sort,
        array $params = [],
    ): array {
        $where = $filter->toWhereClause($columnMap);

        return $this->select(
            $sql . $where['sql'] . $sort->toSql(),
            array_merge($params, $where['params']),
        );
    }

    /**
     * Execute a sorted paginated SELECT query.
     *
     * @param array<int|string, mixed> $params
     * @return \stdClass[]
     */
    public function selectPageSorted(string $sql, SortRequest $sort, PaginationRequest $pagination, array $params = []): array
    {
        return $this->selectPageWith($sql . $sort->toSql(), $pagination, $params);
    }

    /**
     * Execute coordinated count + paginated data queries.
     *
     * @param array<int|string, mixed> $params
     * @return array{items: \stdClass[], total: int, page: int, per_page: int, total_pages: int, has_previous: bool, has_next: bool}
     */
    public function paginate(string $dataSql, string $countSql, int $page, int $perPage, array $params = []): array
    {
        return $this->paginateWith($dataSql, $countSql, new PaginationRequest($page, $perPage), $params);
    }

    /**
     * Execute coordinated count + paginated data queries with PaginationRequest.
     *
     * @param array<int|string, mixed> $params
     * @return array{items: \stdClass[], total: int, page: int, per_page: int, total_pages: int, has_previous: bool, has_next: bool}
     */
    public function paginateWith(string $dataSql, string $countSql, PaginationRequest $pagination, array $params = []): array
    {
        $total = $this->count($countSql, $params);
        $items = $this->selectPageWith($dataSql, $pagination, $params);
        $totalPages = max(1, (int) ceil($total / $pagination->perPage));

        return [
            'items' => $items,
            'total' => $total,
            'page' => $pagination->page,
            'per_page' => $pagination->perPage,
            'total_pages' => $totalPages,
            'has_previous' => $pagination->page > 1,
            'has_next' => $pagination->page < $totalPages,
        ];
    }

    /**
     * Execute coordinated count + sorted paginated data queries.
     *
     * @param array<int|string, mixed> $params
     * @return array{items: \stdClass[], total: int, page: int, per_page: int, total_pages: int, has_previous: bool, has_next: bool}
     */
    public function paginateSortedWith(
        string $dataSql,
        string $countSql,
        SortRequest $sort,
        PaginationRequest $pagination,
        array $params = [],
    ): array {
        $total = $this->count($countSql, $params);
        $items = $this->selectPageSorted($dataSql, $sort, $pagination, $params);
        $totalPages = max(1, (int) ceil($total / $pagination->perPage));

        return [
            'items' => $items,
            'total' => $total,
            'page' => $pagination->page,
            'per_page' => $pagination->perPage,
            'total_pages' => $totalPages,
            'has_previous' => $pagination->page > 1,
            'has_next' => $pagination->page < $totalPages,
        ];
    }

    /**
     * Execute coordinated count + paginated data queries and return an object envelope.
     *
     * @param array<int|string, mixed> $params
     */
    public function paginateResult(string $dataSql, string $countSql, int $page, int $perPage, array $params = []): PaginatedResult
    {
        return $this->paginateResultWith($dataSql, $countSql, new PaginationRequest($page, $perPage), $params);
    }

    /**
     * Execute coordinated count + paginated data queries and return typed envelope.
     *
     * @param array<int|string, mixed> $params
     */
    public function paginateResultWith(string $dataSql, string $countSql, PaginationRequest $pagination, array $params = []): PaginatedResult
    {
        return PaginatedResult::fromArray(
            $this->paginateWith($dataSql, $countSql, $pagination, $params),
        );
    }

    /**
     * Execute paginated query and map each returned item through a transformer.
     *
     * @param array<int|string, mixed> $params
     * @param callable(\stdClass): mixed $mapper
     */
    public function paginateResultMapped(
        string $dataSql,
        string $countSql,
        int $page,
        int $perPage,
        callable $mapper,
        array $params = [],
    ): PaginatedResult {
        return $this->paginateResult($dataSql, $countSql, $page, $perPage, $params)
            ->mapItems($mapper);
    }

    /**
     * Execute paginated query with PaginationRequest and map each returned item.
     *
     * @param array<int|string, mixed> $params
     * @param callable(\stdClass): mixed $mapper
     */
    public function paginateResultMappedWith(
        string $dataSql,
        string $countSql,
        PaginationRequest $pagination,
        callable $mapper,
        array $params = [],
    ): PaginatedResult {
        return $this->paginateResultWith($dataSql, $countSql, $pagination, $params)
            ->mapItems($mapper);
    }

    /**
     * Build pagination request from query params then execute typed pagination.
     *
     * @param array<string, mixed> $query
     * @param array<int|string, mixed> $params
     */
    public function paginateResultFromQuery(
        string $dataSql,
        string $countSql,
        array $query,
        int $defaultPerPage = 25,
        int $maxPerPage = 100,
        array $params = [],
    ): PaginatedResult {
        $pagination = PaginationRequest::fromQuery($query, $defaultPerPage, $maxPerPage);

        return $this->paginateResultWith($dataSql, $countSql, $pagination, $params);
    }

    /**
     * Execute coordinated count + sorted paginated data queries and return typed envelope.
     *
     * @param array<int|string, mixed> $params
     */
    public function paginateSortedResultWith(
        string $dataSql,
        string $countSql,
        SortRequest $sort,
        PaginationRequest $pagination,
        array $params = [],
    ): PaginatedResult {
        return PaginatedResult::fromArray(
            $this->paginateSortedWith($dataSql, $countSql, $sort, $pagination, $params),
        );
    }

    /**
     * Execute filtered + sorted pagination using shared WHERE bindings.
     *
     * @param array<string, string> $columnMap
     * @param array<int|string, mixed> $params
     */
    public function paginateFilteredSortedResultWith(
        string $dataSql,
        string $countSql,
        FilterRequest $filter,
        array $columnMap,
        SortRequest $sort,
        PaginationRequest $pagination,
        array $params = [],
    ): PaginatedResult {
        $where = $filter->toWhereClause($columnMap);
        $bindings = array_merge($params, $where['params']);

        return $this->paginateSortedResultWith(
            $dataSql . $where['sql'],
            $countSql . $where['sql'],
            $sort,
            $pagination,
            $bindings,
        );
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

    /**
     * Build a column-name → converter map for columns whose types have
     * registered converters.
     *
     * The result is memoized per SQL string: getColumnMeta() (and the
     * PostgreSQL metadata round-trips it triggers) runs only the first time
     * a given query shape is seen, then is reused for every subsequent
     * execution on this connection. This is transparent — a fixed SQL
     * statement always yields the same column layout for the connection
     * lifetime, so the cached converter map is identical to a fresh one.
     *
     * @return array<string, callable(string): mixed> column name → converter
     */
    private function resolveColumnTypes(string $sql, PDOStatement $stmt): array
    {
        if (isset($this->columnTypeCache[$sql])) {
            return $this->columnTypeCache[$sql];
        }

        $map = [];
        $columnCount = $stmt->columnCount();

        for ($i = 0; $i < $columnCount; $i++) {
            $meta = $stmt->getColumnMeta($i);
            if ($meta === false) {
                continue;
            }

            $nativeType = strtoupper($meta['native_type'] ?? '');
            if (isset($this->typeConversions[$nativeType])) {
                $map[$meta['name']] = $this->typeConversions[$nativeType];
            } elseif (str_starts_with($nativeType, '_')) {
                // PostgreSQL array types (e.g. _INT4, _TEXT) → PHP arrays.
                $map[$meta['name']] = static function (string $value): array {
                    $inner = str_replace(['{', '}'], '', $value);
                    return $inner === '' ? [] : explode(',', $inner);
                };
            }
        }

        return $this->columnTypeCache[$sql] = $map;
    }

    /**
     * Apply registered type conversions to a fetched row in-place.
     *
     * @param array<string, callable> $columnTypes column name → converter
     */
    private function applyTypeConversions(\stdClass $row, array $columnTypes): void
    {
        foreach ($columnTypes as $column => $converter) {
            if (isset($row->$column) && is_string($row->$column)) {
                $row->$column = $converter($row->$column);
            }
        }
    }

    /**
     * Register built-in PostgreSQL type conversions (int, float, bool, JSON,
     * arrays) matching v1 DatabaseStatement auto-coercion behavior.
     */
    private function registerBuiltinTypeConversions(): void
    {
        foreach (self::BUILTIN_TYPE_CONVERSIONS as $type => $fn) {
            $this->typeConversions[$type] = $fn;
        }

        // JSONB / JSON → decoded PHP value (stdClass or array).
        $jsonDecoder = static fn (string $v): mixed => json_decode($v);
        $this->typeConversions['JSONB'] = $jsonDecoder;
        $this->typeConversions['JSON'] = $jsonDecoder;

        // PostgreSQL array types (e.g. _int4, _text) → PHP arrays.
        // These are handled dynamically in resolveColumnTypes() via
        // the underscore prefix check, not registered statically.
    }
}
