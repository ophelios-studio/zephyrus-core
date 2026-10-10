<?php

declare(strict_types=1);

namespace Zephyrus\Data;

use PDO;
use PDOException;
use PDOStatement;
use Zephyrus\Core\Config\DatabaseConfig;

/**
 * Thin PDO wrapper that turns driver failures into DatabaseException.
 *
 * Inject a pre-built PDO (for example sqlite::memory:) in tests; use fromConfig() in production.
 */
final class Database
{
    /** @var array<string, callable(string): mixed> */
    private array $typeConversions = [];

    /**
     * Resolved column types for this instance, keyed by SQL text and column count.
     * Dropped by registerTypeConversion(), since it holds callables built from the registry.
     *
     * @var array<string, array<string, callable(string): mixed>>
     */
    private array $columnTypeCache = [];

    /**
     * Process-wide column shapes, keyed by sha1(dsn . '|' . columnCacheVersion . '|' . sql) . '|' . columnCount.
     *
     * Holds plain data (column name => native type), never converters: each instance
     * rebuilds its callables from its own registry, so an override such as a string
     * passthrough for NUMERIC is never bypassed. The column count changes with any
     * SELECT * shape change, which self-invalidates the key. Only fromConfig()
     * connections take part. Backed by APCu across requests, see fetchColumnShapeFromApcu().
     *
     * Known limit: without a columnCacheVersion, a column that changes type while keeping its name and the
     * column count stays undetected until its APCu entry expires (APCU_TTL) or flushSharedColumnMetadata() runs.
     * A columnCacheVersion that changes with each migration removes it for every process that reads the new
     * value, see the DatabaseConfig class docblock.
     *
     * @var array<string, array{count: int, types: array<string, string>}>
     */
    private static array $sharedColumnMetadata = [];

    /**
     * Whether the process-wide layer above is consulted and populated.
     * On by default; see setSharedColumnMetadataEnabled().
     */
    private static bool $sharedColumnMetadataEnabled = true;

    /**
     * DSN of the connection this instance wraps, set by fromConfig().
     *
     * Null when a PDO was injected through the constructor: the connection
     * identity is then unknown, and two unrelated databases must never share
     * a shape, so the process-wide layer is bypassed entirely.
     */
    private ?string $connectionDsn = null;

    /**
     * Schema version set by fromConfig(), part of the shared column shape key. See the DatabaseConfig class docblock.
     */
    private string $columnCacheVersion = '';

    /**
     * Savepoint nesting depth: each nested transaction() level gets its own savepoint name.
     */
    private int $savepointDepth = 0;

    /**
     * Transaction levels in which a caught query() failure happened. PostgreSQL turns a later COMMIT
     * into a silent ROLLBACK, so such a level is checked before it is committed or released.
     *
     * @var array<int, true>
     */
    private array $failedLevels = [];

    /** Upper bound on the process-wide store; reaching it clears the store wholesale. */
    private const MAX_SHARED_COLUMN_SHAPES = 2048;

    /** Namespace for APCu keys, so they cannot collide with the host application. The version segment ignores older layouts. */
    private const APCU_KEY_PREFIX = 'zephyrus:column-shape:v1:';

    /**
     * APCu entry lifetime in seconds. Bounds how long a column that changed type under the same name
     * and width keeps its old converter, since a migration can land before the process restarts.
     */
    private const APCU_TTL = 3600;

    private const RETURNING_PATTERN = '/\bRETURNING\b/i';

    /** Built-in PostgreSQL type conversions, keyed by the uppercased native_type from getColumnMeta(). */
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

        // Overridden, not refused: fromConfig() pins it too, but a $pdoFactory or an injected PDO can arrive
        // with emulation on. Refusing would crash on drivers without the attribute (SQLite). The return value
        // is ignored: such a driver answers false without raising.
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        $this->registerBuiltinTypeConversions();
    }

    /**
     * Register a callback to convert values from a PostgreSQL column type
     * (e.g. 'JSONB', 'JSON') before rows are returned from select methods.
     *
     * @param string $typeName PostgreSQL type name (case-insensitive, matched against the uppercased native_type).
     * @param callable(string): mixed $converter Receives the raw string value, returns converted value.
     */
    public function registerTypeConversion(string $typeName, callable $converter): void
    {
        $this->typeConversions[strtoupper($typeName)] = $converter;

        // Drops resolved column types. The process-wide shape cache stays: it holds shapes, not converters.
        $this->columnTypeCache = [];
    }

    /**
     * Turns the process-wide column shape cache on or off (on by default). Turning it off flushes both layers.
     *
     * Correctness does not depend on it: the per-instance memo yields the same rows.
     */
    public static function setSharedColumnMetadataEnabled(bool $enabled): void
    {
        self::$sharedColumnMetadataEnabled = $enabled;

        if (!$enabled) {
            self::flushSharedColumnMetadata();
        }
    }

    /**
     * Drops every cached column shape, from the process static and from APCu.
     *
     * APCu memory belongs to one web SAPI: call this from a web request to reach its workers, not from a CLI process.
     */
    public static function flushSharedColumnMetadata(): void
    {
        self::$sharedColumnMetadata = [];

        if (!self::apcuUsable() || !class_exists('APCUIterator')) {
            return;
        }

        // Prefix scoped on purpose: apcu_clear_cache() would take the host
        // application's own entries down with it.
        apcu_delete(new \APCUIterator('/^' . preg_quote(self::APCU_KEY_PREFIX, '/') . '/'));
    }

    /**
     * Opens a PostgreSQL connection described by $config.
     *
     * @param null|callable(string, string, string, array<int, mixed>): PDO $pdoFactory
     *        Optional factory for tests and advanced callers, receiving ($dsn, $username, $password, $options).
     *
     * @throws DatabaseException on PDO connection failure.
     */
    public static function fromConfig(#[\SensitiveParameter] DatabaseConfig $config, ?callable $pdoFactory = null): self
    {
        // DatabaseConfig refuses quotes and backslashes in these values, so quoting needs no escaping.
        $dsn = sprintf(
            "pgsql:host='%s';port=%d;dbname='%s'",
            $config->host,
            $config->port,
            $config->database,
        );

        // Opt-in: libpq already negotiates TLS ('prefer' by default). A pinned mode makes it refuse what it
        // would otherwise accept unencrypted. The DSN is part of the shape cache key: changing it orphans APCu entries.
        if ($config->sslMode !== null) {
            $dsn .= ';sslmode=' . $config->sslMode;
        }

        // Appended whenever set, whatever the mode: libpq ignores it under a non-verifying mode, and under a
        // verifying mode without one it falls back to its own default store.
        if ($config->sslRootCert !== null) {
            $dsn .= ";sslrootcert='" . $config->sslRootCert . "'";
        }

        $options = [
            PDO::ATTR_PERSISTENT => false,

            // Not configurable: client-side emulation is a security downgrade. See the constructor.
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        // #[\SensitiveParameter] keeps the password out of the backtrace Tracy renders (zend.exception_ignore_args=0).
        $factory = $pdoFactory ?? static fn (string $dsn, string $username, #[\SensitiveParameter] string $password, array $options): PDO
            => new PDO($dsn, $username, $password, $options);

        try {
            $pdo = $factory($dsn, $config->username, $config->password, $options);
        } catch (\Throwable $e) {
            throw DatabaseException::connectionFailed($dsn, $e->getMessage());
        }

        $db = new self($pdo);

        // Identity of the connection for shared shapes. The DSN holds no credentials: PDO receives them separately.
        $db->connectionDsn = $dsn;
        $db->columnCacheVersion = $config->columnCacheVersion;

        // $config->charset is interpolated: DatabaseConfig validates it against ^[a-zA-Z0-9_]+$ on construction.
        // A failure is ignored, so a server that lacks the charset still yields a working connection.
        try {
            $db->query(sprintf("SET client_encoding TO '%s'", $config->charset));
        } catch (DatabaseException) {
            // Intentionally ignored; see above.
        }

        return $db;
    }

    /**
     * Execute a prepared statement with positional or named placeholders.
     *
     * Values bind by type: bool as 0/1, int and null natively, Binary and streams as
     * binary, float, DateTimeInterface, Stringable as text, and a BackedEnum as its backing
     * value; anything else is refused.
     *
     * @param array<int|string, mixed> $params
     * @throws DatabaseException on prepare or execution failure. Its message
     *         carries the SQLSTATE only; the statement and the driver text are
     *         reachable through DatabaseException::sql() and driverMessage().
     * @throws \InvalidArgumentException when the SQL holds a NUL byte, positional keys are not 0 to n-1, positional and
     *         named keys are mixed, a key is not a valid placeholder, a value has no SQL form or a text value holds a NUL byte.
     */
    public function query(string $sql, #[\SensitiveParameter] array $params = []): PDOStatement
    {
        if (str_contains($sql, "\0")) {
            throw new \InvalidArgumentException(
                'SQL text cannot hold a NUL byte: bind the value as a parameter instead of concatenating it into the statement.',
            );
        }

        $bindings = self::bindings($params);

        try {
            $stmt = $this->pdo->prepare($sql);

            foreach ($bindings as [$placeholder, $value, $type]) {
                $stmt->bindValue($placeholder, $value, $type);
            }

            $stmt->execute();

            return $stmt;
        } catch (PDOException $e) {
            $this->markFailedLevel();

            // The driver text can carry column values, so the factory keeps it off the message.
            throw DatabaseException::queryExecutionFailed($sql, $e);
        }
    }

    /**
     * Execute a SELECT and return all rows as stdClass objects.
     *
     * @param array<int|string, mixed> $params
     * @return \stdClass[]
     */
    public function select(string $sql, #[\SensitiveParameter] array $params = []): array
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
    public function selectOne(string $sql, #[\SensitiveParameter] array $params = []): ?\stdClass
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
    public function selectValue(string $sql, #[\SensitiveParameter] array $params = [], mixed $default = null): mixed
    {
        // fetchColumn() answers false both for "no row" and for a false column,
        // so the row itself is what decides between the default and the value.
        $row = $this->query($sql, $params)->fetch(PDO::FETCH_NUM);

        return $row === false ? $default : $row[0];
    }

    /**
     * Execute a scalar query and return the value cast to int.
     *
     * @param array<int|string, mixed> $params
     */
    public function selectInt(string $sql, #[\SensitiveParameter] array $params = [], int $default = 0): int
    {
        return (int) $this->selectValue($sql, $params, $default);
    }

    /**
     * Execute a scalar query and return the value cast to string (or null).
     *
     * @param array<int|string, mixed> $params
     */
    public function selectString(string $sql, #[\SensitiveParameter] array $params = [], ?string $default = null): ?string
    {
        $value = $this->selectValue($sql, $params, $default);

        return $value === null ? null : (string) $value;
    }

    /**
     * Execute a scalar query and return the value cast to bool.
     *
     * @param array<int|string, mixed> $params
     */
    public function selectBool(string $sql, #[\SensitiveParameter] array $params = [], bool $default = false): bool
    {
        return (bool) $this->selectValue($sql, $params, $default);
    }

    /**
     * Execute a scalar query and return the value cast to float.
     *
     * @param array<int|string, mixed> $params
     */
    public function selectFloat(string $sql, #[\SensitiveParameter] array $params = [], float $default = 0.0): float
    {
        return (float) $this->selectValue($sql, $params, $default);
    }

    /**
     * Execute a count-style scalar query and return an int.
     *
     * @param array<int|string, mixed> $params
     */
    public function count(string $sql, #[\SensitiveParameter] array $params = []): int
    {
        return $this->selectInt($sql, $params, 0);
    }

    /**
     * Execute a paginated SELECT query by applying LIMIT/OFFSET.
     *
     * @param array<int|string, mixed> $params
     * @return \stdClass[]
     */
    public function selectPage(string $sql, int $page, int $perPage, #[\SensitiveParameter] array $params = []): array
    {
        return $this->selectPageWith($sql, new PaginationRequest($page, $perPage), $params);
    }

    /**
     * Execute a paginated SELECT query using a PaginationRequest.
     *
     * @param array<int|string, mixed> $params
     * @return \stdClass[]
     */
    public function selectPageWith(string $sql, PaginationRequest $pagination, #[\SensitiveParameter] array $params = []): array
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
    public function selectSorted(string $sql, SortRequest $sort, #[\SensitiveParameter] array $params = []): array
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
    public function selectFiltered(string $sql, FilterRequest $filter, array $columnMap, #[\SensitiveParameter] array $params = []): array
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
        #[\SensitiveParameter] array $params = [],
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
    public function selectPageSorted(string $sql, SortRequest $sort, PaginationRequest $pagination, #[\SensitiveParameter] array $params = []): array
    {
        return $this->selectPageWith($sql . $sort->toSql(), $pagination, $params);
    }

    /**
     * Execute coordinated count + paginated data queries.
     *
     * @param array<int|string, mixed> $params
     * @return array{items: \stdClass[], total: int, page: int, per_page: int, total_pages: int, has_previous: bool, has_next: bool}
     */
    public function paginate(string $dataSql, string $countSql, int $page, int $perPage, #[\SensitiveParameter] array $params = []): array
    {
        return $this->paginateWith($dataSql, $countSql, new PaginationRequest($page, $perPage), $params);
    }

    /**
     * Execute coordinated count + paginated data queries with PaginationRequest.
     *
     * @param array<int|string, mixed> $params
     * @return array{items: \stdClass[], total: int, page: int, per_page: int, total_pages: int, has_previous: bool, has_next: bool}
     */
    public function paginateWith(string $dataSql, string $countSql, PaginationRequest $pagination, #[\SensitiveParameter] array $params = []): array
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
        #[\SensitiveParameter] array $params = [],
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
     * @return PaginatedResult<\stdClass>
     */
    public function paginateResult(string $dataSql, string $countSql, int $page, int $perPage, #[\SensitiveParameter] array $params = []): PaginatedResult
    {
        return $this->paginateResultWith($dataSql, $countSql, new PaginationRequest($page, $perPage), $params);
    }

    /**
     * Execute coordinated count + paginated data queries and return typed envelope.
     *
     * @param array<int|string, mixed> $params
     * @return PaginatedResult<\stdClass>
     */
    public function paginateResultWith(string $dataSql, string $countSql, PaginationRequest $pagination, #[\SensitiveParameter] array $params = []): PaginatedResult
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
     * @return PaginatedResult<\stdClass>
     */
    public function paginateResultMapped(
        string $dataSql,
        string $countSql,
        int $page,
        int $perPage,
        callable $mapper,
        #[\SensitiveParameter] array $params = [],
    ): PaginatedResult {
        return $this->paginateResult($dataSql, $countSql, $page, $perPage, $params)
            ->mapItems($mapper);
    }

    /**
     * Execute paginated query with PaginationRequest and map each returned item.
     *
     * @param array<int|string, mixed> $params
     * @param callable(\stdClass): mixed $mapper
     * @return PaginatedResult<\stdClass>
     */
    public function paginateResultMappedWith(
        string $dataSql,
        string $countSql,
        PaginationRequest $pagination,
        callable $mapper,
        #[\SensitiveParameter] array $params = [],
    ): PaginatedResult {
        return $this->paginateResultWith($dataSql, $countSql, $pagination, $params)
            ->mapItems($mapper);
    }

    /**
     * Build pagination request from query params then execute typed pagination.
     *
     * @param array<string, mixed> $query
     * @param array<int|string, mixed> $params
     * @return PaginatedResult<\stdClass>
     */
    public function paginateResultFromQuery(
        string $dataSql,
        string $countSql,
        array $query,
        int $defaultPerPage = 25,
        int $maxPerPage = 100,
        #[\SensitiveParameter] array $params = [],
    ): PaginatedResult {
        $pagination = PaginationRequest::fromQuery($query, $defaultPerPage, $maxPerPage);

        return $this->paginateResultWith($dataSql, $countSql, $pagination, $params);
    }

    /**
     * Execute coordinated count + sorted paginated data queries and return typed envelope.
     *
     * @param array<int|string, mixed> $params
     * @return PaginatedResult<\stdClass>
     */
    public function paginateSortedResultWith(
        string $dataSql,
        string $countSql,
        SortRequest $sort,
        PaginationRequest $pagination,
        #[\SensitiveParameter] array $params = [],
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
     * @return PaginatedResult<\stdClass>
     */
    public function paginateFilteredSortedResultWith(
        string $dataSql,
        string $countSql,
        FilterRequest $filter,
        array $columnMap,
        SortRequest $sort,
        PaginationRequest $pagination,
        #[\SensitiveParameter] array $params = [],
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
    public function execute(string $sql, #[\SensitiveParameter] array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    /**
     * Execute an INSERT and return affected row count.
     *
     * @param array<int|string, mixed> $params
     */
    public function insert(string $sql, #[\SensitiveParameter] array $params = []): int
    {
        return $this->execute($sql, $params);
    }

    /**
     * Execute an INSERT ... RETURNING id and return the single RETURNING column of its first row, or false when
     * no row comes back (e.g. ON CONFLICT DO NOTHING). Other drivers without RETURNING fall back to lastInsertId().
     *
     * @param array<int|string, mixed> $params
     * @throws DatabaseException on PostgreSQL and SQLite when the SQL has no RETURNING clause (before it runs) or
     *         RETURNING yields no column (after it ran); on any driver when RETURNING yields more than one column
     *         (after it ran). Inside a transaction, let that exception propagate so the row is rolled back.
     */
    public function insertGetId(string $sql, #[\SensitiveParameter] array $params = []): string|false
    {
        $requiresReturning = $this->requiresReturning();
        if ($requiresReturning && preg_match(self::RETURNING_PATTERN, $sql) !== 1) {
            throw DatabaseException::returningRequired();
        }

        $stmt = $this->query($sql, $params);
        if ($stmt->columnCount() === 0) {
            if ($requiresReturning) {
                throw DatabaseException::returningYieldedNoColumn();
            }

            return $this->lastInsertId();
        }

        if ($stmt->columnCount() !== 1) {
            throw DatabaseException::returningNotSingleColumn($stmt->columnCount());
        }

        $id = $stmt->fetchColumn();

        return $id === false || $id === null ? false : (string) $id;
    }

    /**
     * Execute an UPDATE and return affected row count.
     *
     * @param array<int|string, mixed> $params
     */
    public function update(string $sql, #[\SensitiveParameter] array $params = []): int
    {
        return $this->execute($sql, $params);
    }

    /**
     * Execute a DELETE and return affected row count.
     *
     * @param array<int|string, mixed> $params
     */
    public function delete(string $sql, #[\SensitiveParameter] array $params = []): int
    {
        return $this->execute($sql, $params);
    }

    /**
     * Execute an existence check query and return true when first column is truthy.
     *
     * @param array<int|string, mixed> $params
     */
    public function exists(string $sql, #[\SensitiveParameter] array $params = []): bool
    {
        return $this->selectBool($sql, $params, false);
    }

    /**
     * Runs $work in a transaction and returns its result: commits when $work returns, rolls back and rethrows when it throws.
     *
     * Inside an open transaction, $work runs in a savepoint, so its failure undoes only its own writes.
     * A failed query() that $work caught leaves the server aborted (PostgreSQL): the commit or release is refused
     * with SQLSTATE 25P02 rather than reported as done. A statement run through pdo() is not tracked.
     * Let the exception propagate, or catch it around a nested transaction(). A failed rollback never replaces
     * the original error.
     *
     * @throws DatabaseException when the database fails to begin, commit, or set or release a savepoint,
     *         or with SQLSTATE 25P02 as described above. Its message carries the stage and the SQLSTATE only;
     *         the driver text is on driverMessage().
     * @template T
     * @param callable(Database): T $work
     * @return T
     * @throws \Throwable whatever $work throws, once its writes are rolled back.
     */
    public function transaction(callable $work): mixed
    {
        if ($this->inTransaction()) {
            return $this->savepoint($work);
        }

        try {
            $this->pdo->beginTransaction();
        } catch (PDOException $e) {
            throw DatabaseException::transactionExecutionFailed('begin', $e);
        }

        $this->failedLevels = [];

        try {
            $result = $work($this);
        } catch (\Throwable $e) {
            $this->rollBackOpenTransaction();

            throw $e;
        }

        if ($this->failedSince(0) && ($probeFailure = $this->probeFailure('commit')) !== null) {
            $this->rollBackOpenTransaction();

            throw $probeFailure;
        }

        try {
            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->rollBackOpenTransaction();

            throw DatabaseException::transactionExecutionFailed('commit', $e);
        }

        return $result;
    }

    /**
     * Returns the last generated id of the session. Refused on PostgreSQL, where it can name another table's
     * sequence: use insertGetId() with INSERT ... RETURNING id. Stale on SQLite after an insert that wrote no row.
     *
     * @throws DatabaseException on PostgreSQL, or when the driver cannot report the id.
     */
    public function lastInsertId(): string|false
    {
        if ($this->isPostgres()) {
            throw DatabaseException::lastInsertIdRefused();
        }

        try {
            return $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            $this->markFailedLevel();

            throw DatabaseException::queryExecutionFailed('lastInsertId()', $e);
        }
    }

    private function isPostgres(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }

    private function requiresReturning(): bool
    {
        return in_array($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME), ['pgsql', 'sqlite'], true);
    }

    private function markFailedLevel(): void
    {
        if ($this->inTransaction()) {
            $this->failedLevels[$this->savepointDepth] = true;
        }
    }

    /**
     * Reports whether the connection has an active transaction. Never cache the answer:
     * a transaction() callable may commit or roll back on its own.
     *
     * @phpstan-impure
     */
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * Exposes the underlying PDO for advanced callers (for example schema migrations). Prefer the typed helpers.
     *
     * A statement run through it inside transaction() is not tracked: if it fails and the exception is caught,
     * PostgreSQL turns the COMMIT into a silent ROLLBACK. Let the exception escape.
     */
    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Resolves each parameter to its placeholder, PDO value and type, refusing values with no SQL form before the driver sees them.
     * Binding everything as a string would make PostgreSQL reject false on a boolean and misread bytes on a bytea.
     *
     * @param array<int|string, mixed> $params list keys for ? placeholders, names (with or without the colon) for named ones
     * @return list<array{int|string, mixed, int}>
     */
    private static function bindings(#[\SensitiveParameter] array $params): array
    {
        self::assertPositionalKeysAreZeroToN($params);

        $bindings = [];

        foreach ($params as $key => $value) {
            $placeholder = self::placeholder($key);
            $bindings[] = [$placeholder, ...self::bindable($placeholder, $value)];
        }

        return $bindings;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    private static function assertPositionalKeysAreZeroToN(#[\SensitiveParameter] array $params): void
    {
        $keys = array_keys($params);
        $positional = array_filter($keys, static fn (int|string $key): bool => is_int($key));
        if ($positional === []) {
            return;
        }

        if (count($positional) !== count($keys)) {
            throw new \InvalidArgumentException('Query parameters cannot mix positional and named keys.');
        }

        $sorted = $keys;
        sort($sorted);
        if ($sorted !== range(0, count($keys) - 1)) {
            $listed = implode(', ', array_slice($keys, 0, 10));
            if (count($keys) > 10) {
                $listed .= ', ...';
            }

            throw new \InvalidArgumentException(sprintf(
                'Positional query parameters must use the keys 0 to n-1 (got keys %s): renumber them or use named parameters.',
                $listed,
            ));
        }
    }

    private static function placeholder(int|string $key): int|string
    {
        if ($key === '' || $key === PHP_INT_MAX || (is_int($key) && $key < 0)) {
            throw new \InvalidArgumentException(sprintf(
                'Query parameter key %s is not a placeholder: use a list for ? placeholders and names for named ones.',
                $key === '' ? "''" : $key,
            ));
        }

        // PDO numbers ? placeholders from 1, the list from 0.
        return is_int($key) ? $key + 1 : $key;
    }

    /**
     * @return array{mixed, int}
     */
    private static function bindable(int|string $placeholder, #[\SensitiveParameter] mixed $value): array
    {
        return match (true) {
            $value === null => [null, PDO::PARAM_NULL],
            // As 0/1 rather than PARAM_BOOL's 't'/'f', which a text column keeps and reads back as true.
            is_bool($value) => [(int) $value, PDO::PARAM_INT],
            is_int($value) => [$value, PDO::PARAM_INT],
            is_string($value) => [self::refuseNulByte($placeholder, $value), PDO::PARAM_STR],
            is_float($value) => [self::floatLiteral($value), PDO::PARAM_STR],
            $value instanceof Binary => [$value->bytes, PDO::PARAM_LOB],
            $value instanceof \DateTimeInterface => [$value->format('Y-m-d H:i:s.uP'), PDO::PARAM_STR],
            $value instanceof \BackedEnum => self::bindable($placeholder, $value->value),
            $value instanceof \Stringable => [self::refuseNulByte($placeholder, (string) $value), PDO::PARAM_STR],
            is_resource($value) && get_resource_type($value) === 'stream' => [$value, PDO::PARAM_LOB],
            default => throw new \InvalidArgumentException(sprintf(
                'Query parameter %s cannot be bound: %s has no SQL form. Bind a scalar, null, a '
                . 'DateTimeInterface, a BackedEnum, a Stringable, a Binary or a stream.',
                self::placeholderName($placeholder),
                get_debug_type($value),
            )),
        };
    }

    /**
     * Refuse text holding a NUL byte, which pdo_pgsql would cut short there.
     */
    private static function refuseNulByte(int|string $placeholder, #[\SensitiveParameter] string $value): string
    {
        if (str_contains($value, "\0")) {
            throw new \InvalidArgumentException(sprintf(
                'Query parameter %s cannot be bound: text cannot hold a NUL byte. '
                . 'Validate request input before querying; bind real binary data as a %s.',
                self::placeholderName($placeholder),
                Binary::class,
            ));
        }

        return $value;
    }

    private static function placeholderName(int|string $placeholder): string
    {
        return is_int($placeholder) ? '#' . $placeholder : ':' . ltrim($placeholder, ':');
    }

    /**
     * The shortest text that reads back as the same double, independent of precision and locale, spelled as PostgreSQL reads it.
     */
    private static function floatLiteral(float $value): string
    {
        if (is_nan($value)) {
            return 'NaN';
        }

        if (is_infinite($value)) {
            return $value > 0 ? 'Infinity' : '-Infinity';
        }

        if ($value === 0.0) {
            return fdiv(1.0, $value) < 0 ? '-0' : '0';
        }

        // Below 2^53 an integral double is exact, and an integer column rejects "45.0".
        if ($value === floor($value) && abs($value) < 9007199254740992.0) {
            return sprintf('%.0F', $value);
        }

        for ($precision = 15; $precision < 17; $precision++) {
            $literal = sprintf("%.{$precision}h", $value);

            if ((float) $literal === $value) {
                return $literal;
            }
        }

        return sprintf('%.17h', $value);
    }

    /**
     * Run $work inside a savepoint of the transaction already open.
     *
     * @template T
     * @param callable(Database): T $work
     * @return T
     */
    private function savepoint(callable $work): mixed
    {
        $name = 'zephyrus_tx_' . ($this->savepointDepth + 1);

        try {
            $this->pdo->exec("SAVEPOINT {$name}");
        } catch (PDOException $e) {
            throw DatabaseException::transactionExecutionFailed('savepoint', $e);
        }

        $depth = ++$this->savepointDepth;

        try {
            $result = $work($this);
        } catch (\Throwable $e) {
            $this->rollBackToSavepoint($name, $depth);

            throw $e;
        } finally {
            $this->savepointDepth--;
        }

        if ($this->failedSince($depth) && ($probeFailure = $this->probeFailure('release savepoint')) !== null) {
            $this->rollBackToSavepoint($name, $depth);

            throw $probeFailure;
        }

        try {
            $this->pdo->exec("RELEASE SAVEPOINT {$name}");
        } catch (PDOException $e) {
            $this->rollBackToSavepoint($name, $depth);

            throw DatabaseException::transactionExecutionFailed('release savepoint', $e);
        }

        return $result;
    }

    private function rollBackOpenTransaction(): void
    {
        $this->failedLevels = [];

        if (!$this->inTransaction()) {
            return;
        }

        try {
            $this->pdo->rollBack();
        } catch (PDOException) {
            // A failed rollback must not mask the exception that caused it.
        }
    }

    private function rollBackToSavepoint(string $name, int $depth): void
    {
        // One try on purpose: a release without the rollback would keep the writes.
        try {
            $this->pdo->exec("ROLLBACK TO SAVEPOINT {$name}");
            $this->pdo->exec("RELEASE SAVEPOINT {$name}");
        } catch (PDOException) {
            // A failed rollback must not mask the exception that caused it.
            return;
        }

        $this->failedLevels = array_filter(
            $this->failedLevels,
            static fn (int $level): bool => $level < $depth,
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Returns why the transaction can no longer run statements, or null when the probe succeeds. A recorded
     * failure alone does not decide it: the caller may have repaired it with its own savepoint, and SQLite never aborts.
     */
    private function probeFailure(string $stage): ?DatabaseException
    {
        try {
            $this->pdo->query('SELECT 1');
        } catch (PDOException $e) {
            return DatabaseException::transactionProbeFailed($stage, $e);
        }

        return null;
    }

    private function failedSince(int $depth): bool
    {
        foreach ($this->failedLevels as $level => $_) {
            if ($level >= $depth) {
                return true;
            }
        }

        return false;
    }

    /**
     * Builds the column-name => converter map for columns whose native type has a converter.
     *
     * Per-instance memo first, then the process-wide shape cache, which skips getColumnMeta() on a hit while
     * still rebuilding the callables from this instance's converters.
     *
     * @return array<string, callable(string): mixed> column name => converter
     */
    private function resolveColumnTypes(string $sql, PDOStatement $stmt): array
    {
        // columnCount() is client side, zero round-trips. It is part of both keys, so adding or dropping
        // a SELECT * column self-invalidates.
        $columnCount = $stmt->columnCount();
        $localKey = $sql . '|' . $columnCount;

        if (isset($this->columnTypeCache[$localKey])) {
            return $this->columnTypeCache[$localKey];
        }

        $shared = $this->connectionDsn !== null && self::$sharedColumnMetadataEnabled;
        $sharedKey = $shared
            ? sha1($this->connectionDsn . '|' . $this->columnCacheVersion . '|' . $sql) . '|' . $columnCount
            : '';
        $shape = null;

        if ($shared) {
            $shape = self::$sharedColumnMetadata[$sharedKey] ?? null;

            // The width is already in the key; this compare stops a hash collision from serving a shape of the wrong width.
            if ($shape !== null && $shape['count'] !== $columnCount) {
                $shape = null;
            }

            if ($shape === null) {
                $shape = self::fetchColumnShapeFromApcu($sharedKey, $columnCount);

                // An APCu hit warms the static, so the shared-memory lookup happens once per process.
                if ($shape !== null) {
                    self::rememberColumnShape($sharedKey, $shape);
                }
            }
        }

        if ($shape === null) {
            $shape = self::describeColumns($stmt, $columnCount);

            if ($shared) {
                self::rememberColumnShape($sharedKey, $shape);
                self::storeColumnShapeInApcu($sharedKey, $shape);
            }
        }

        $map = [];

        foreach ($shape['types'] as $name => $nativeType) {
            if (isset($this->typeConversions[$nativeType])) {
                $map[$name] = $this->typeConversions[$nativeType];
            } elseif (str_starts_with($nativeType, '_')) {
                // PostgreSQL array types (e.g. _INT4, _TEXT) => PHP arrays.
                $map[$name] = PostgresArrayParser::parse(...);
            }
        }

        return $this->columnTypeCache[$localKey] = $map;
    }

    /**
     * Reads the column layout off an executed statement: the width plus a column name => native type map.
     *
     * Records every column, not only those this instance has a converter for: a shape narrowed by one
     * registry would hide a converter registered by another instance. Duplicate names keep the last
     * column's type, as the fetched row keeps the last value (PDO::FETCH_OBJ).
     *
     * @return array{count: int, types: array<string, string>}
     */
    private static function describeColumns(PDOStatement $stmt, int $columnCount): array
    {
        $types = [];

        for ($i = 0; $i < $columnCount; $i++) {
            $meta = $stmt->getColumnMeta($i);
            if ($meta === false) {
                continue;
            }

            $types[$meta['name']] = strtoupper($meta['native_type'] ?? '');
        }

        return ['count' => $columnCount, 'types' => $types];
    }

    /**
     * Records a shape in the process static, clearing the store first if it has reached its ceiling.
     *
     * @param array{count: int, types: array<string, string>} $shape
     */
    private static function rememberColumnShape(string $key, array $shape): void
    {
        if (count(self::$sharedColumnMetadata) >= self::MAX_SHARED_COLUMN_SHAPES) {
            self::$sharedColumnMetadata = [];
        }

        self::$sharedColumnMetadata[$key] = $shape;
    }

    /**
     * Reads a shape back from APCu, or returns null when nothing trustworthy is stored.
     *
     * This layer carries a shape across requests: PHP clears every static at request shutdown while the
     * persistent PDO handle survives. Every failure returns null, meaning "resolve it again", never
     * "no conversions": a silently skipped JSONB decode is worse than a slow query.
     *
     * @return array{count: int, types: array<string, string>}|null
     */
    private static function fetchColumnShapeFromApcu(string $key, int $columnCount): ?array
    {
        if (!self::apcuUsable()) {
            return null;
        }

        $success = false;
        /** @var mixed $cached */
        $cached = apcu_fetch(self::APCU_KEY_PREFIX . $key, $success);

        // The out-param decides: false is a legitimate cached value.
        if (!$success) {
            return null;
        }

        if (!is_array($cached)
            || !is_int($cached['count'] ?? null)
            || !is_array($cached['types'] ?? null)
            || $cached['count'] !== $columnCount
        ) {
            return null;
        }

        foreach ($cached['types'] as $nativeType) {
            if (!is_string($nativeType)) {
                return null;
            }
        }

        /** @var array{count: int, types: array<string, string>} $cached */
        return $cached;
    }

    /**
     * Publishes a shape to APCu, best effort: a full or disabled APCu just means the next process resolves it.
     *
     * @param array{count: int, types: array<string, string>} $shape
     */
    private static function storeColumnShapeInApcu(string $key, array $shape): void
    {
        if (!self::apcuUsable()) {
            return;
        }

        apcu_store(self::APCU_KEY_PREFIX . $key, $shape, self::APCU_TTL);
    }

    /**
     * Whether APCu can be used right now. Checked on the cold path only. apc.enable_cli is off by default,
     * so CLI and worker processes rely on the static instead.
     */
    private static function apcuUsable(): bool
    {
        return function_exists('apcu_fetch') && apcu_enabled();
    }

    /**
     * Apply registered type conversions to a fetched row in-place.
     *
     * @param array<string, callable> $columnTypes column name => converter
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
     * Registers the built-in PostgreSQL type conversions (int, float, bool, JSON).
     */
    private function registerBuiltinTypeConversions(): void
    {
        foreach (self::BUILTIN_TYPE_CONVERSIONS as $type => $fn) {
            $this->typeConversions[$type] = $fn;
        }

        // JSONB / JSON => decoded PHP value (stdClass or array).
        $jsonDecoder = static fn (string $v): mixed => json_decode($v);
        $this->typeConversions['JSONB'] = $jsonDecoder;
        $this->typeConversions['JSON'] = $jsonDecoder;

        // Array types (_int4, _text) are resolved in resolveColumnTypes(), not registered here.
    }
}
