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
     * Memoized column-type resolutions for THIS instance, keyed by SQL string
     * plus the statement's column count.
     *
     * getColumnMeta() triggers backend metadata lookups on PostgreSQL
     * (a pg_class query per column, plus a pg_type query for non-builtin
     * OIDs), so resolving the same statement shape on every select() would
     * fire hundreds of identical round-trips over a request. The column
     * layout (name + native type) of a given SQL string is stable for the
     * connection lifetime, so the resolution is computed once per distinct
     * query and reused thereafter.
     *
     * This layer holds the DERIVED callable map, built from this instance's
     * own conversion registry, which is why registerTypeConversion() drops it.
     *
     * @var array<string, array<string, callable(string): mixed>>
     */
    private array $columnTypeCache = [];

    /**
     * Process-wide cache of resolved column SHAPES, keyed by
     * sha1(dsn . '|' . sql) . '|' . columnCount.
     *
     * The per-instance memo above dies with the Database instance, while the
     * PDO handle underneath it may well be persistent, so every new instance
     * re-pays the full metadata cost on a connection that already knows the
     * answer. This layer survives for the lifetime of the PHP process instead.
     *
     * Three deliberate choices make it safe:
     *
     *   1. It stores PLAIN DATA (a column count and a column name => native
     *      type name map), never the converters themselves. The converters are
     *      closures, so caching them would be unserializable, and, worse, would
     *      let one instance's registry leak into another.
     *   2. The callable map is rebuilt from THIS instance's typeConversions on
     *      every hit, so an instance can only ever apply its own converters.
     *      That matters: the built-in NUMERIC conversion is floatval, and an
     *      application that overrides it with a string passthrough to protect
     *      money precision must never be served the framework default.
     *   3. The key carries the column count, which PDOStatement::columnCount()
     *      reports client side (PQnfields) for zero round-trips. Any column
     *      added to or dropped from a SELECT * therefore changes the key and
     *      self-invalidates, with no deploy hook to forget.
     *
     * Only connections opened through fromConfig() take part: a directly
     * injected PDO has no knowable identity, so those instances keep the
     * per-instance memo alone and behave exactly as before.
     *
     * This array is backed by APCu when the extension is available, which is
     * what carries a shape from one REQUEST to the next: PHP resets every
     * static at request shutdown, so under mod_php or FPM this array alone is
     * empty again on the next request even though the persistent PDO handle
     * under it survived. See fetchColumnShapeFromApcu().
     *
     * KNOWN LIMIT: a column that changes TYPE while keeping BOTH its name and
     * the statement's column count (int4 to numeric, say) is invisible to this
     * key, and a process would keep applying the previous converter. A column
     * added or dropped changes the count, so that case self-invalidates. See
     * APCU_TTL for how long the undetectable case can survive, and
     * flushSharedColumnMetadata() for the manual release.
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
     * Hard ceiling on the process-wide store, so a long-running process that
     * generates unbounded distinct SQL (dynamic IN lists, generated filters)
     * cannot grow it forever. Reaching it resets the store wholesale rather
     * than carrying LRU bookkeeping on the hot path; a process that trips this
     * is producing far more query shapes than any cache can help with.
     */
    private const MAX_SHARED_COLUMN_SHAPES = 2048;

    /**
     * Namespace for the APCu keys, so this cache cannot collide with whatever
     * else the host application keeps in the same shared memory. The trailing
     * version segment lets a future change to the stored structure ignore
     * older entries instead of having to reason about them.
     */
    private const APCU_KEY_PREFIX = 'zephyrus:column-shape:v1:';

    /**
     * Lifetime of an APCu entry, in seconds.
     *
     * Deliberately finite rather than unlimited. A deploy replaces the machine
     * and starts APCu empty, so an ordinary release self-clears, and a column
     * being added or dropped changes the column count and self-invalidates via
     * the key. What is left is the one drift the key cannot see: a column that
     * changes TYPE while keeping its name and the statement width. Because the
     * house rule is that a migration reaches production BEFORE the code that
     * needs it, there is a real window where the schema has moved and no
     * process has restarted, and an unlimited entry would apply the previous
     * converter until someone noticed.
     *
     * An hour bounds that window without operator action. The cost is one
     * re-resolution per query shape per hour per machine, which is noise
     * against the round-trips saved on every request in between.
     */
    private const APCU_TTL = 3600;

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
        //
        // The process-wide shape cache is deliberately NOT cleared: registering
        // a converter changes how a type is converted, never what type a column
        // is. The next resolve rebuilds the callable map from the cached shape
        // against the updated registry, so the new converter takes effect
        // immediately without re-asking the backend.
        $this->columnTypeCache = [];
    }

    /**
     * Turn the process-wide column shape cache on or off (on by default).
     *
     * Escape hatch for a deployment where a long-lived process must never hold
     * a schema snapshot, and for tests that need a clean slate. Covers BOTH
     * backing layers, the process static and APCu. Turning it off also flushes
     * them, since entries that can no longer be read are only holding memory;
     * turning it back on simply re-warms on the next query.
     *
     * Correctness never depends on this: the per-instance memo and the live
     * conversion registry produce the same rows either way.
     */
    public static function setSharedColumnMetadataEnabled(bool $enabled): void
    {
        self::$sharedColumnMetadataEnabled = $enabled;

        if (!$enabled) {
            self::flushSharedColumnMetadata();
        }
    }

    /**
     * Drop every cached column shape, from the process static AND from APCu.
     *
     * Needed only in the one case the cache key cannot detect: a column that
     * changed TYPE while keeping both its name and the statement's column
     * count. APCu expires those entries on its own within APCU_TTL, so this is
     * the way to reclaim the window rather than wait it out.
     *
     * OPERATIONAL NOTE: APCu memory belongs to a SAPI instance, not to a
     * machine. Calling this from an HTTP request clears it for every worker
     * process of that web server, which makes a small authenticated admin
     * route the practical fleet-wide flush (one call per machine). Running it
     * from a CLI process does NOT reach the web server's segment, so an
     * `ssh console` one-liner is not a fleet flush. Restarting or redeploying
     * the app is, since fresh machines start with an empty APCu.
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

        // Opt-in only: libpq negotiates TLS on its own and defaults to
        // 'prefer', so it already encrypts whenever the server offers TLS.
        // Pinning a mode is a policy decision about what to do when it does
        // NOT: 'require' and stricter refuse the connection outright, which is
        // the point, and also why this can never be a default. Absent, the key
        // is not added at all, so the DSN is byte-for-byte what it has always
        // been and libpq keeps its own default. Unlike emulatePrepares below
        // this is a DSN parameter, not a PDO driver option, so it belongs in
        // the connection string. DatabaseConfig validates the value against
        // the libpq set at construction, so only a canonical mode can reach
        // this string.
        //
        // CACHE NOTE: this DSN is part of the process-wide column shape cache
        // key (see $connectionDsn and $sharedColumnMetadata). Turning the mode
        // on, off, or from one value to another therefore changes every key
        // and orphans the previous APCu entries. That is harmless and
        // self-correcting: the next query of each shape re-resolves it and
        // re-populates, and the orphans expire on APCU_TTL. It is not a bug.
        if ($config->sslMode !== null) {
            $dsn .= ';sslmode=' . $config->sslMode;
        }

        // Appended whenever it is set, independently of the mode: silently
        // dropping a trust anchor an operator configured would be worse than
        // handing libpq a parameter it ignores under a non-verifying mode.
        //
        // The converse (a verify mode with no root cert) is deliberately NOT
        // rejected here. libpq has its own answer for it: it falls back to
        // ~/.postgresql/root.crt, accepts the literal 'system' for the OS
        // trust store on PostgreSQL 16+, and fails the connection with a
        // precise message when no anchor is found. Refusing it here would
        // reject a configuration libpq accepts.
        if ($config->sslRootCert !== null) {
            $dsn .= ';sslrootcert=' . $config->sslRootCert;
        }

        $options = [
            PDO::ATTR_PERSISTENT => false,
        ];

        // Opt-in only: enabling ATTR_EMULATE_PREPARES interpolates parameters
        // client-side, collapsing PostgreSQL's three round-trips per query
        // (Parse, Bind/Describe, Execute) down to one — a large win over a
        // non-local DB link. It must be set at connect time via the driver
        // options. Absent or false, the key is not added at all, so the PDO
        // keeps its native server-side prepares and existing apps are unchanged.
        if ($config->emulatePrepares) {
            $options[PDO::ATTR_EMULATE_PREPARES] = true;
        }

        $factory = $pdoFactory ?? static fn (string $dsn, string $username, string $password, array $options): PDO
            => new PDO($dsn, $username, $password, $options);

        try {
            $pdo = $factory($dsn, $config->username, $config->password, $options);
        } catch (\Throwable $e) {
            throw DatabaseException::connectionFailed($dsn, $e->getMessage());
        }

        $db = new self($pdo);

        // Record the connection identity so resolved column shapes can be
        // shared across instances opened against the SAME database, and only
        // those. The DSN carries no credentials (PDO takes those separately);
        // the TLS parameters above are transport policy and a public file
        // path, so that stays true.
        $db->connectionDsn = $dsn;

        // Set client encoding for the connection. The value is interpolated, which
        // is only safe because DatabaseConfig validates charset against
        // ^[a-zA-Z0-9_]+$ in BOTH its constructor and fromArray(); nothing here
        // re-checks it.
        //
        // The failure is swallowed, and that is a deliberate trade-off rather than
        // a claim that it cannot fail: the realistic causes are a charset name the
        // server does not know, and a connection that died between connect and this
        // statement. Raising here would turn a connection that works on the server
        // default encoding into a hard boot failure for every application that
        // vendors this framework, so the connection is returned either way.
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
     * @param array<int|string, mixed> $params
     * @throws DatabaseException on prepare or execution failure. Its message
     *         carries the SQLSTATE only; the statement and the driver text are
     *         reachable through DatabaseException::sql() and driverMessage().
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt;
        } catch (PDOException $e) {
            // queryExecutionFailed(), not queryFailed(): the driver text can carry
            // interpolated parameter values under ATTR_EMULATE_PREPARES, so it stays
            // off the message unless DatabaseException::enableVerboseMessages() is on.
            // Both the statement and the driver text remain on the exception, via
            // sql() and driverMessage().
            throw DatabaseException::queryExecutionFailed($sql, $e);
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
     * Two caches back this, in order:
     *
     *   1. The per-instance memo of the finished callable map, which costs a
     *      single array lookup on a hit.
     *   2. The process-wide shape cache, which on a hit skips every
     *      getColumnMeta() call (and the PostgreSQL backend round-trips they
     *      trigger) while still rebuilding the callable map from THIS
     *      instance's converters.
     *
     * Both are transparent: a fixed SQL statement yields the same column layout
     * for the connection lifetime, so a cached resolution is identical to a
     * fresh one.
     *
     * @return array<string, callable(string): mixed> column name → converter
     */
    private function resolveColumnTypes(string $sql, PDOStatement $stmt): array
    {
        // columnCount() reads PQnfields off the already-fetched result: client
        // side, zero round-trips. It is part of both keys so that adding or
        // dropping a column on a SELECT * self-invalidates.
        $columnCount = $stmt->columnCount();
        $localKey = $sql . '|' . $columnCount;

        if (isset($this->columnTypeCache[$localKey])) {
            return $this->columnTypeCache[$localKey];
        }

        $shared = $this->connectionDsn !== null && self::$sharedColumnMetadataEnabled;
        $sharedKey = $shared ? sha1($this->connectionDsn . '|' . $sql) . '|' . $columnCount : '';
        $shape = null;

        if ($shared) {
            $shape = self::$sharedColumnMetadata[$sharedKey] ?? null;

            // The width is in the key already; re-checking it here costs one
            // integer compare and denies a hash collision any chance of serving
            // a shape of the wrong width.
            if ($shape !== null && $shape['count'] !== $columnCount) {
                $shape = null;
            }

            if ($shape === null) {
                $shape = self::fetchColumnShapeFromApcu($sharedKey, $columnCount);

                // An APCu hit warms the static, so nothing else in this process
                // pays even the shared-memory lookup again.
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
                // PostgreSQL array types (e.g. _INT4, _TEXT) → PHP arrays.
                $map[$name] = static function (string $value): array {
                    $inner = str_replace(['{', '}'], '', $value);
                    return $inner === '' ? [] : explode(',', $inner);
                };
            }
        }

        return $this->columnTypeCache[$localKey] = $map;
    }

    /**
     * Read the column layout off an executed statement: the statement width
     * plus a column name → native type name map.
     *
     * This is the only place getColumnMeta() is called, and it deliberately
     * records EVERY column rather than only the ones the calling instance has
     * a converter for. A shape narrowed by one registry would be wrong for any
     * other instance reading it back: a converter registered only by the second
     * instance would silently never fire, because the column it targets was
     * dropped from the shape before it was cached.
     *
     * Recording every column also settles duplicate result column names the way
     * the fetched row does. PDO::FETCH_OBJ keeps the LAST such column's value,
     * so the last one's type is the one that applies here too.
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
     * Record a shape in the process static, resetting the store wholesale if
     * it has reached its ceiling.
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
     * Read a shape back from APCu, or null when there is nothing trustworthy
     * to read.
     *
     * This is the layer that makes the cache worth having on a request-per-
     * process SAPI. PHP destroys every static at request shutdown while the
     * persistent PDO handle survives, so without shared memory the very first
     * query of every request re-asks PostgreSQL for metadata it already
     * answered on that same connection.
     *
     * Every failure path returns null, which means "resolve it properly", not
     * "there are no conversions". Handing back a half-understood entry would
     * silently skip a JSONB decode or a NUMERIC passthrough, and a wrong value
     * is far worse than a slow query.
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

        // The out-param decides, not a comparison against false: false is a
        // perfectly legitimate cached value, and conflating the two is how a
        // cache starts reporting hits as misses.
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
     * Publish a shape to APCu, best effort.
     *
     * A full, disabled or racing APCu simply means the next process resolves
     * the shape itself, so the return value is deliberately ignored and APCu
     * needs no size ceiling of its own: its own expunge handles pressure, and
     * the entries are a few hundred bytes each.
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
     * Whether APCu can be used right now.
     *
     * Checked on the cold path only (a per-instance memo miss), so the cost
     * never lands on a repeated query. Note that apc.enable_cli defaults to
     * off, so CLI and worker processes usually get nothing here: that is fine,
     * because a long-running process is served by the static, which for it
     * lives as long as the process does.
     */
    private static function apcuUsable(): bool
    {
        return function_exists('apcu_fetch') && apcu_enabled();
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
