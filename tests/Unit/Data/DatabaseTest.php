<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Data;

use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\DatabaseConfig;
use Zephyrus\Data\Database;
use Zephyrus\Data\DatabaseException;
use Zephyrus\Data\FilterRequest;
use Zephyrus\Data\PaginatedResult;
use Zephyrus\Data\PaginationRequest;
use Zephyrus\Data\SortRequest;

final class DatabaseTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Database($pdo);
        $this->db->query('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL)');
    }

    // ── construction & fromConfig() & pdo() ──────────────────────────────────

    public function testFromConfigUsesFactoryWithExpectedDsnAndOptions(): void
    {
        $config = DatabaseConfig::fromArray([
            'host' => 'db.internal',
            'port' => 5433,
            'database' => 'zephyrus',
            'username' => 'app',
            'password' => 'secret',
            'charset' => 'utf8',
        ]);

        $captured = [];

        $database = Database::fromConfig(
            $config,
            function (string $dsn, string $username, string $password, array $options) use (&$captured): PDO {
                $captured = [
                    'dsn' => $dsn,
                    'username' => $username,
                    'password' => $password,
                    'options' => $options,
                ];

                return new PDO('sqlite::memory:');
            },
        );

        self::assertSame('pgsql:host=db.internal;port=5433;dbname=zephyrus', $captured['dsn']);
        self::assertSame('app', $captured['username']);
        self::assertSame('secret', $captured['password']);
        self::assertArrayHasKey(PDO::ATTR_PERSISTENT, $captured['options']);
        self::assertFalse($captured['options'][PDO::ATTR_PERSISTENT]);
        self::assertInstanceOf(Database::class, $database);
    }

    public function testFromConfigAlwaysPinsEmulatePreparesOff(): void
    {
        // Not "absent", PINNED. The key must be present and false in the options
        // the factory receives, because that array is exactly what drives the real
        // PDO at connect time and an absent key leaves the decision to the driver.
        $config = DatabaseConfig::fromArray([
            'database' => 'zephyrus',
            'username' => 'app',
        ]);

        $captured = [];

        Database::fromConfig(
            $config,
            function (string $dsn, string $username, string $password, array $options) use (&$captured): PDO {
                $captured = $options;

                return new PDO('sqlite::memory:');
            },
        );

        self::assertArrayHasKey(PDO::ATTR_EMULATE_PREPARES, $captured);
        self::assertFalse($captured[PDO::ATTR_EMULATE_PREPARES]);
    }

    /**
     * The connect-time option only covers the connection fromConfig() opens.
     * A $pdoFactory is free to ignore the options it is handed and build its own
     * PDO with emulation on, so the constructor re-asserts the attribute on every
     * connection that reaches it. Without that, the factory is a documented hole
     * straight back to client-side interpolation.
     */
    public function testAPdoFactoryCannotReintroduceEmulatedPrepares(): void
    {
        $config = DatabaseConfig::fromArray([
            'database' => 'zephyrus',
            'username' => 'app',
        ]);

        $spy = null;

        Database::fromConfig(
            $config,
            function (string $dsn, string $username, string $password, array $options) use (&$spy): PDO {
                // Deliberately DISCARDS $options and asks for emulation.
                $spy = new AttributeSpyPdo('sqlite::memory:');

                return $spy;
            },
        );

        self::assertContains(false, $spy->emulateSettings, 'expected emulation to be forced off');
        self::assertSame(false, end($spy->emulateSettings), 'the last word must be false');
    }

    /**
     * Same guarantee for the other door: a pre-built PDO injected through the
     * constructor. Testability was never meant to be an escape hatch out of the
     * connection's security posture.
     */
    public function testAnInjectedPdoIsForcedOntoNativePrepares(): void
    {
        $spy = new AttributeSpyPdo('sqlite::memory:');

        new Database($spy);

        self::assertContains(false, $spy->emulateSettings);
        self::assertSame(false, end($spy->emulateSettings));
    }

    public function testTrickyParametersStillRoundTripOnNativePrepares(): void
    {
        // Kept from the emulated-prepares era, and still worth having: an integer
        // LIMIT, a typed WHERE int_col = ? comparison and a NULL bound value are
        // the three shapes a prepare-mode change is most likely to move, so they
        // are exercised end to end through fromConfig() on the pinned setting.
        $config = DatabaseConfig::fromArray([
            'database' => 'zephyrus',
            'username' => 'app',
        ]);

        $spy = null;

        $db = Database::fromConfig(
            $config,
            function (string $dsn, string $username, string $password, array $options) use (&$spy): PDO {
                $spy = new AttributeSpyPdo('sqlite::memory:', null, null, $options);

                return $spy;
            },
        );

        // The connection was built from options that pin emulation off.
        self::assertFalse($spy->constructorEmulateOption);

        $db->execute('CREATE TABLE items (id INTEGER PRIMARY KEY, qty INTEGER, note TEXT)');
        $db->execute('INSERT INTO items (id, qty, note) VALUES (?, ?, ?)', [1, 10, 'first']);
        $db->execute('INSERT INTO items (id, qty, note) VALUES (?, ?, ?)', [2, 20, 'second']);
        $db->execute('INSERT INTO items (id, qty, note) VALUES (?, ?, ?)', [3, 30, null]);

        // (a) integer LIMIT ? — must be treated as an integer, not a string.
        $limited = $db->select('SELECT id FROM items ORDER BY id LIMIT ?', [2]);
        self::assertCount(2, $limited);
        self::assertSame([1, 2], array_map(static fn ($r): int => (int) $r->id, $limited));

        // (b) typed WHERE int_col = ? comparison against an integer column.
        $exact = $db->selectOne('SELECT id, qty FROM items WHERE qty = ?', [20]);
        self::assertNotNull($exact);
        self::assertSame(2, (int) $exact->id);
        self::assertSame(20, (int) $exact->qty);

        // (c) NULL parameter — the row with a NULL note must match IS NULL,
        //     and a NULL-bound equality must NOT spuriously match rows.
        $nullNotes = $db->select('SELECT id FROM items WHERE note IS ? ORDER BY id', [null]);
        self::assertCount(1, $nullNotes);
        self::assertSame(3, (int) $nullNotes[0]->id);

        $noneEqualNull = $db->select('SELECT id FROM items WHERE note = ?', [null]);
        self::assertCount(0, $noneEqualNull);
    }

    // ── fromConfig(): opt-in TLS DSN parameters ──────────────────────────────

    public function testFromConfigOmitsSslParametersByDefault(): void
    {
        // The regression that matters: with neither key configured the DSN must
        // be byte-for-byte the string every existing application already
        // connects with, so libpq keeps its own 'prefer' default and nothing
        // about an untouched deployment changes. The literal is spelled out
        // rather than composed, so any accidental addition fails here.
        $config = DatabaseConfig::fromArray([
            'host' => 'db.internal',
            'port' => 5433,
            'database' => 'zephyrus',
            'username' => 'app',
        ]);

        self::assertNull($config->sslMode);
        self::assertNull($config->sslRootCert);
        self::assertSame(
            'pgsql:host=db.internal;port=5433;dbname=zephyrus',
            $this->captureDsn($config),
        );
    }

    public function testFromConfigOmitsSslParametersForBlankConfiguredValues(): void
    {
        // An environment variable that exists but is empty (a cleared Fly
        // secret, an unset !env with no default) must land on the untouched DSN
        // too, never on a malformed 'sslmode=' with nothing after it.
        $config = DatabaseConfig::fromArray([
            'database' => 'zephyrus',
            'username' => 'app',
            'sslmode' => '   ',
            'sslrootcert' => '',
        ]);

        self::assertSame(
            'pgsql:host=localhost;port=5432;dbname=zephyrus',
            $this->captureDsn($config),
        );
    }

    public function testFromConfigAppendsEverySupportedSslMode(): void
    {
        foreach (DatabaseConfig::SSL_MODES as $mode) {
            $config = DatabaseConfig::fromArray([
                'database' => 'zephyrus',
                'username' => 'app',
                'sslmode' => $mode,
            ]);

            self::assertSame(
                'pgsql:host=localhost;port=5432;dbname=zephyrus;sslmode=' . $mode,
                $this->captureDsn($config),
                sprintf('sslmode=%s must reach the DSN verbatim', $mode),
            );
        }
    }

    public function testFromConfigAppendsSslRootCertOnlyWhenSet(): void
    {
        $without = DatabaseConfig::fromArray([
            'database' => 'zephyrus',
            'username' => 'app',
            'sslmode' => 'verify-full',
        ]);

        self::assertSame(
            'pgsql:host=localhost;port=5432;dbname=zephyrus;sslmode=verify-full',
            $this->captureDsn($without),
        );

        $with = DatabaseConfig::fromArray([
            'database' => 'zephyrus',
            'username' => 'app',
            'sslmode' => 'verify-full',
            'sslrootcert' => '/etc/ssl/certs/pg-root.crt',
        ]);

        self::assertSame(
            'pgsql:host=localhost;port=5432;dbname=zephyrus'
                . ';sslmode=verify-full;sslrootcert=/etc/ssl/certs/pg-root.crt',
            $this->captureDsn($with),
        );
    }

    public function testFromConfigAppendsSslRootCertIndependentlyOfTheMode(): void
    {
        // A configured trust anchor is never silently dropped: libpq simply
        // ignores it under a non-verifying mode, which is a better outcome than
        // the framework deciding the operator did not mean it.
        $config = DatabaseConfig::fromArray([
            'database' => 'zephyrus',
            'username' => 'app',
            'sslrootcert' => 'system',
        ]);

        self::assertSame(
            'pgsql:host=localhost;port=5432;dbname=zephyrus;sslrootcert=system',
            $this->captureDsn($config),
        );
    }

    public function testSslDsnParametersCarryNoCredentials(): void
    {
        // The DSN is the shared column shape cache key and is echoed in
        // connection-failure messages, so it must stay free of the password.
        $config = DatabaseConfig::fromArray([
            'database' => 'zephyrus',
            'username' => 'zephyrus_app_role',
            'password' => 'sup3r-s3cret',
            'sslmode' => 'require',
            'sslrootcert' => '/etc/ssl/certs/pg-root.crt',
        ]);

        $dsn = $this->captureDsn($config);

        self::assertStringNotContainsString('sup3r-s3cret', $dsn);
        self::assertStringNotContainsString('zephyrus_app_role', $dsn);
    }

    /**
     * Open a connection through the injected factory purely to read back the
     * DSN it was handed, with no real database involved.
     */
    private function captureDsn(DatabaseConfig $config): string
    {
        $captured = '';

        Database::fromConfig(
            $config,
            function (string $dsn) use (&$captured): PDO {
                $captured = $dsn;

                return new PDO('sqlite::memory:');
            },
        );

        return $captured;
    }

    public function testFromConfigWrapsFactoryFailureAsDatabaseException(): void
    {
        $config = DatabaseConfig::fromArray([
            'database' => 'zephyrus',
            'username' => 'app',
        ]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Database connection failed for DSN [pgsql:host=localhost;port=5432;dbname=zephyrus]: factory boom');

        Database::fromConfig(
            $config,
            function (): PDO {
                throw new \RuntimeException('factory boom');
            },
        );
    }

    public function testFromConfigWrapsPdoExceptionAsDatabaseException(): void
    {
        $config = DatabaseConfig::fromArray([
            'database' => 'zephyrus',
            'username' => 'app',
        ]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Database connection failed for DSN [pgsql:host=localhost;port=5432;dbname=zephyrus]: pdo boom');

        Database::fromConfig(
            $config,
            function (): PDO {
                throw new PDOException('pdo boom');
            },
        );
    }


    public function testPdoAccessorReturnsPdo(): void
    {
        self::assertInstanceOf(PDO::class, $this->db->pdo());
    }

    public function testErrorModeIsSetToException(): void
    {
        self::assertSame(
            PDO::ERRMODE_EXCEPTION,
            $this->db->pdo()->getAttribute(PDO::ATTR_ERRMODE),
        );
    }

    public function testFetchModeIsObj(): void
    {
        self::assertSame(
            PDO::FETCH_OBJ,
            $this->db->pdo()->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE),
        );
    }

    // ── query() ──────────────────────────────────────────────────────────────

    public function testQueryReturnsStatement(): void
    {
        $stmt = $this->db->query('SELECT 1 AS val');
        self::assertInstanceOf(PDOStatement::class, $stmt);
    }

    /**
     * @return iterable<string, array{array<int|string, mixed>, string}>
     */
    public static function positionalKeysOutsideZeroToN(): iterable
    {
        yield 'keys start at 1' => [[1 => 'Alice'], '1'];
        yield 'a hole after the first key' => [[0 => 'Alice', 2 => 'Bob'], '0, 2'];
        yield 'keys out of order' => [[1 => 'Bob', 0 => 'Alice', 5 => 'Eve'], '1, 0, 5'];
    }

    #[DataProvider('positionalKeysOutsideZeroToN')]
    public function testQueryRefusesPositionalKeysThatAreNotZeroToN(array $params, string $keys): void
    {
        try {
            $this->db->query('SELECT ?, ?', $params);
            self::fail('expected the parameters to be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertSame(
                "Positional query parameters must use the keys 0 to n-1 (got keys {$keys}): renumber them or use named parameters.",
                $e->getMessage(),
            );
        }
    }

    public function testTheKeysListedInThePositionalKeysMessageAreCapped(): void
    {
        $params = [];
        for ($key = 1; $key <= 60000; $key++) {
            $params[$key] = 'value';
        }

        try {
            $this->db->query('SELECT ?', $params);
            self::fail('expected the parameters to be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertSame(
                'Positional query parameters must use the keys 0 to n-1 (got keys 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, ...): renumber them or use named parameters.',
                $e->getMessage(),
            );
        }
    }

    public function testPositionalKeysOutOfOrderBindByKey(): void
    {
        $row = $this->db->selectOne('SELECT ? AS first, ? AS second', [1 => 'b', 0 => 'a']);

        self::assertSame('a', $row->first);
        self::assertSame('b', $row->second);
    }

    public function testPlainPositionalListBindsInOrder(): void
    {
        $row = $this->db->selectOne('SELECT ? AS first, ? AS second', ['a', 'b']);

        self::assertSame('a', $row->first);
        self::assertSame('b', $row->second);
    }

    public function testQueryRefusesSqlTextHoldingANulByteBeforePreparing(): void
    {
        try {
            $this->db->query("SELECT 1 \0 AND false");
            self::fail('expected the SQL text to be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertSame(
                'SQL text cannot hold a NUL byte: bind the value as a parameter instead of concatenating it into the statement.',
                $e->getMessage(),
            );
        }
    }

    public function testQueryWithPositionalParams(): void
    {
        $this->db->query('INSERT INTO users (name, email) VALUES (?, ?)', ['Alice', 'alice@example.com']);
        $stmt = $this->db->query('SELECT * FROM users WHERE name = ?', ['Alice']);
        $row = $stmt->fetch();
        self::assertSame('Alice', $row->name);
    }

    public function testQueryWithNamedParams(): void
    {
        $this->db->query('INSERT INTO users (name, email) VALUES (:name, :email)', [
            ':name' => 'Bob',
            ':email' => 'bob@example.com',
        ]);
        $stmt = $this->db->query('SELECT * FROM users WHERE name = :name', [':name' => 'Bob']);
        $row = $stmt->fetch();
        self::assertSame('Bob', $row->name);
    }

    public function testQueryThrowsOnInvalidSql(): void
    {
        $this->expectException(DatabaseException::class);
        $this->db->query('INVALID SQL STATEMENT');
    }

    // ── convenience read/write helpers ──────────────────────────────────────

    public function testSelectReturnsAllRows(): void
    {
        $this->db->query('INSERT INTO users (name, email) VALUES (?, ?)', ['Alice', 'alice@example.com']);
        $this->db->query('INSERT INTO users (name, email) VALUES (?, ?)', ['Bob', 'bob@example.com']);

        $rows = $this->db->select('SELECT * FROM users ORDER BY id');

        self::assertCount(2, $rows);
        self::assertSame('Alice', $rows[0]->name);
        self::assertSame('Bob', $rows[1]->name);
    }

    public function testSelectOneReturnsNullWhenNoRows(): void
    {
        self::assertNull($this->db->selectOne('SELECT * FROM users WHERE id = ?', [999]));
    }

    public function testSelectOneReturnsFirstRow(): void
    {
        $this->db->query('INSERT INTO users (name, email) VALUES (?, ?)', ['Cara', 'cara@example.com']);

        $row = $this->db->selectOne('SELECT * FROM users WHERE name = ?', ['Cara']);

        self::assertNotNull($row);
        self::assertSame('cara@example.com', $row->email);
    }

    public function testSelectValueReturnsDefaultWhenNoRows(): void
    {
        self::assertSame('fallback', $this->db->selectValue('SELECT name FROM users WHERE id = ?', [999], 'fallback'));
    }

    public function testTypedScalarHelpersReturnExpectedCasts(): void
    {
        $this->db->execute('INSERT INTO users (name, email) VALUES (?, ?)', ['Dan', 'dan@example.com']);
        $this->db->execute('INSERT INTO users (name, email) VALUES (?, ?)', ['Eve', 'eve@example.com']);

        self::assertSame(2, $this->db->selectInt('SELECT COUNT(*) FROM users'));
        self::assertSame('Dan', $this->db->selectString('SELECT name FROM users WHERE email = ?', ['dan@example.com']));
        self::assertTrue($this->db->selectBool('SELECT EXISTS(SELECT 1 FROM users WHERE email = ?)', ['dan@example.com']));
        self::assertSame(1.5, $this->db->selectFloat('SELECT AVG(id) FROM users'));
    }

    public function testInsertUpdateDeleteHelpersWorkAsConvenienceAliases(): void
    {
        $id = $this->db->insertGetId('INSERT INTO users (name, email) VALUES (?, ?)', ['Eva', 'eva@example.com']);
        self::assertNotFalse($id);

        $updated = $this->db->update('UPDATE users SET name = ? WHERE id = ?', ['Evelyn', (int) $id]);
        self::assertSame(1, $updated);

        $deleted = $this->db->delete('DELETE FROM users WHERE id = ?', [(int) $id]);
        self::assertSame(1, $deleted);
    }

    public function testCountReturnsScalarCount(): void
    {
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['A', 'a@example.com']);
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['B', 'b@example.com']);

        self::assertSame(2, $this->db->count('SELECT COUNT(*) FROM users'));
    }

    public function testSelectPageReturnsLimitedWindow(): void
    {
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['A', 'a@example.com']);
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['B', 'b@example.com']);
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['C', 'c@example.com']);

        $rows = $this->db->selectPage('SELECT * FROM users ORDER BY id', 2, 1);

        self::assertCount(1, $rows);
        self::assertSame('B', $rows[0]->name);
    }

    public function testPaginateReturnsExpectedEnvelope(): void
    {
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['A', 'a@example.com']);
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['B', 'b@example.com']);
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['C', 'c@example.com']);

        $page = $this->db->paginate(
            'SELECT * FROM users ORDER BY id',
            'SELECT COUNT(*) FROM users',
            2,
            2,
        );

        self::assertSame(3, $page['total']);
        self::assertSame(2, $page['page']);
        self::assertSame(2, $page['per_page']);
        self::assertSame(2, $page['total_pages']);
        self::assertTrue($page['has_previous']);
        self::assertFalse($page['has_next']);
        self::assertCount(1, $page['items']);
        self::assertSame('C', $page['items'][0]->name);
    }

    public function testPaginateResultReturnsTypedEnvelope(): void
    {
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['A', 'a@example.com']);
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['B', 'b@example.com']);
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['C', 'c@example.com']);

        $page = $this->db->paginateResult(
            'SELECT * FROM users ORDER BY id',
            'SELECT COUNT(*) FROM users',
            2,
            2,
        );

        self::assertInstanceOf(PaginatedResult::class, $page);
        self::assertSame(3, $page->total);
        self::assertSame(2, $page->page);
        self::assertSame(2, $page->perPage);
        self::assertSame(2, $page->totalPages);
        self::assertTrue($page->hasPrevious);
        self::assertFalse($page->hasNext);
        self::assertSame(1, $page->itemCount());
    }

    public function testPaginationRequestBasedHelpersReturnExpectedData(): void
    {
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['A', 'a@example.com']);
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['B', 'b@example.com']);
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['C', 'c@example.com']);

        $pagination = new PaginationRequest(page: 2, perPage: 2);

        $rows = $this->db->selectPageWith('SELECT * FROM users ORDER BY id', $pagination);
        self::assertCount(1, $rows);
        self::assertSame('C', $rows[0]->name);

        $page = $this->db->paginateWith('SELECT * FROM users ORDER BY id', 'SELECT COUNT(*) FROM users', $pagination);
        self::assertSame(3, $page['total']);
        self::assertSame(2, $page['page']);

        $typed = $this->db->paginateResultWith('SELECT * FROM users ORDER BY id', 'SELECT COUNT(*) FROM users', $pagination);
        self::assertSame(1, $typed->itemCount());
    }

    public function testMappedPaginateResultHelpersTransformItems(): void
    {
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['alpha', 'a@example.com']);
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['beta', 'b@example.com']);
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['gamma', 'c@example.com']);

        $mapped = $this->db->paginateResultMapped(
            'SELECT * FROM users ORDER BY id',
            'SELECT COUNT(*) FROM users',
            1,
            2,
            static fn (\stdClass $row): \stdClass => (object) [...(array) $row, 'name' => strtoupper($row->name)],
        );
        self::assertSame('ALPHA', $mapped->items[0]->name);
        self::assertSame('BETA', $mapped->items[1]->name);

        $mappedWith = $this->db->paginateResultMappedWith(
            'SELECT * FROM users ORDER BY id',
            'SELECT COUNT(*) FROM users',
            new PaginationRequest(2, 2),
            static fn (\stdClass $row): \stdClass => (object) [...(array) $row, 'name' => strtoupper($row->name)],
        );
        self::assertSame('GAMMA', $mappedWith->items[0]->name);
    }

    public function testPaginateResultFromQueryBuildsBoundedRequest(): void
    {
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['alpha', 'a@example.com']);
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['beta', 'b@example.com']);
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['gamma', 'c@example.com']);

        $page = $this->db->paginateResultFromQuery(
            'SELECT * FROM users ORDER BY id',
            'SELECT COUNT(*) FROM users',
            ['page' => 2, 'per_page' => 999],
            defaultPerPage: 25,
            maxPerPage: 2,
        );

        self::assertSame(2, $page->page);
        self::assertSame(2, $page->perPage);
        self::assertSame(1, $page->itemCount());
        self::assertSame('gamma', strtolower((string) $page->firstItem()->name));
    }

    public function testSortedDatabaseHelpersApplyOrderingAndPagination(): void
    {
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['charlie', 'c@example.com']);
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['alice', 'a@example.com']);
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['bravo', 'b@example.com']);

        $sort = new SortRequest('name', 'ASC');

        $sorted = $this->db->selectSorted('SELECT * FROM users', $sort);
        self::assertSame('alice', $sorted[0]->name);
        self::assertSame('bravo', $sorted[1]->name);
        self::assertSame('charlie', $sorted[2]->name);

        $page = $this->db->selectPageSorted('SELECT * FROM users', $sort, new PaginationRequest(2, 2));
        self::assertCount(1, $page);
        self::assertSame('charlie', $page[0]->name);

        $typed = $this->db->paginateSortedResultWith(
            'SELECT * FROM users',
            'SELECT COUNT(*) FROM users',
            $sort,
            new PaginationRequest(1, 2),
        );
        self::assertSame('alice', $typed->firstItem()->name);
    }

    public function testFilteredAndFilteredSortedQueriesApplyWhereBindings(): void
    {
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['charlie', 'c@example.com']);
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['alice', 'a@example.com']);
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['bravo', 'b@example.com']);

        $filter = new FilterRequest(['email' => 'a@example.com']);

        $filtered = $this->db->selectFiltered(
            'SELECT * FROM users',
            $filter,
            ['email' => 'email'],
        );
        self::assertCount(1, $filtered);
        self::assertSame('alice', $filtered[0]->name);

        $filteredSorted = $this->db->selectFilteredSorted(
            'SELECT * FROM users',
            new FilterRequest(['name' => 'charlie']),
            ['name' => 'name'],
            new SortRequest('name', 'DESC'),
        );
        self::assertCount(1, $filteredSorted);
        self::assertSame('charlie', $filteredSorted[0]->name);
    }

    public function testPaginateFilteredSortedResultWithCombinesFilterSortAndPaging(): void
    {
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['alpha', 'a@example.com']);
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['beta', 'b@example.com']);
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['alpha', 'z@example.com']);

        $result = $this->db->paginateFilteredSortedResultWith(
            'SELECT * FROM users',
            'SELECT COUNT(*) FROM users',
            new FilterRequest(['name' => 'alpha']),
            ['name' => 'name'],
            new SortRequest('email', 'ASC'),
            new PaginationRequest(1, 1),
        );

        self::assertSame(2, $result->total);
        self::assertSame('a@example.com', $result->firstItem()->email);
    }

    public function testSelectPageThrowsOnInvalidPaginationArguments(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Page must be >= 1');

        $this->db->selectPage('SELECT * FROM users', 0, 10);
    }

    public function testPaginateThrowsOnInvalidPerPageArgument(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Per-page must be >= 1');

        $this->db->paginate('SELECT * FROM users', 'SELECT COUNT(*) FROM users', 1, 0);
    }

    public function testExecuteReturnsAffectedRows(): void
    {
        $affected = $this->db->execute('INSERT INTO users (name, email) VALUES (?, ?)', ['Dan', 'dan@example.com']);

        self::assertSame(1, $affected);
    }

    public function testExistsReturnsFalseWhenQueryReturnsNoMatch(): void
    {
        self::assertFalse($this->db->exists('SELECT EXISTS(SELECT 1 FROM users WHERE email = ?)', ['none@example.com']));
    }

    public function testExistsReturnsTrueWhenQueryHasMatch(): void
    {
        $this->db->execute('INSERT INTO users (name, email) VALUES (?, ?)', ['Eli', 'eli@example.com']);

        self::assertTrue($this->db->exists('SELECT EXISTS(SELECT 1 FROM users WHERE email = ?)', ['eli@example.com']));
    }

    // ── insertGetId() ────────────────────────────────────────────────────────

    public function testInsertGetIdReturnsTheReturningIdOnSqlite(): void
    {
        self::assertSame('1', $this->db->insertGetId('INSERT INTO users (name, email) VALUES (?, ?) RETURNING id', ['Frank', 'frank@example.com']));
    }

    public function testInsertGetIdReturnsFalseWhenReturningYieldsNoRow(): void
    {
        $this->db->query('INSERT INTO users (id, name, email) VALUES (?, ?, ?)', [1, 'Gina', 'gina@example.com']);

        $id = $this->db->insertGetId(
            'INSERT INTO users (id, name, email) VALUES (?, ?, ?) ON CONFLICT (id) DO NOTHING RETURNING id',
            [1, 'Hugo', 'hugo@example.com'],
        );

        self::assertFalse($id);
    }

    public function testInsertGetIdWithoutReturningKeepsLastInsertIdOnSqlite(): void
    {
        self::assertSame('1', $this->db->insertGetId('INSERT INTO users (name, email) VALUES (?, ?)', ['Ivan', 'ivan@example.com']));
    }

    public function testInsertGetIdRefusesPostgresStatementWithoutReturningBeforeRunningIt(): void
    {
        $pdo = new DriverNamePdo('sqlite::memory:');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL)');
        $db = new Database($pdo);

        try {
            $db->insertGetId('INSERT INTO users (name, email) VALUES (?, ?)', ['Judy', 'judy@example.com']);
            self::fail('expected the statement without RETURNING to be refused');
        } catch (DatabaseException $e) {
            self::assertStringContainsString('INSERT ... RETURNING id', $e->getMessage());
            self::assertStringNotContainsString('Judy', $e->getMessage());
            self::assertNull($e->sql());
        }

        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testInsertGetIdMatchesReturningAsAWholeWordOnPostgres(): void
    {
        $pdo = new DriverNamePdo('sqlite::memory:');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, returning_id INTEGER, name TEXT NOT NULL)');
        $db = new Database($pdo);

        $this->expectException(DatabaseException::class);
        try {
            $db->insertGetId('INSERT INTO users (returning_id, name) VALUES (?, ?)', [7, 'Kim']);
        } finally {
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        }
    }

    public function testInsertGetIdAcceptsLowercaseReturningOnPostgres(): void
    {
        $pdo = new DriverNamePdo('sqlite::memory:');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        $db = new Database($pdo);

        self::assertSame('1', $db->insertGetId('insert into users (name) values (?) returning id', ['Lou']));
    }

    public function testInsertGetIdRejectsNulByteBeforeTheStatementRuns(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->db->insertGetId("INSERT INTO users (name, email) VALUES ('a', 'b') RETURNING id\0", []);
    }

    // ── lastInsertId() ───────────────────────────────────────────────────────

    public function testLastInsertIdAfterInsert(): void
    {
        $this->db->query('INSERT INTO users (name, email) VALUES (?, ?)', ['Carol', 'carol@example.com']);
        $id = $this->db->lastInsertId();
        self::assertNotFalse($id);
        self::assertGreaterThan(0, (int) $id);
    }

    public function testLastInsertIdIsRefusedOnPostgresWithoutCallingTheDriver(): void
    {
        $pdo = new DriverNamePdo('sqlite::memory:');
        $db = new Database($pdo);

        try {
            $db->lastInsertId();
            self::fail('expected lastInsertId() to be refused on PostgreSQL');
        } catch (DatabaseException $e) {
            self::assertStringContainsString('insertGetId()', $e->getMessage());
        }

        self::assertFalse($pdo->lastInsertIdCalled);
    }

    public function testLastInsertIdTakesNoSequenceName(): void
    {
        $method = new \ReflectionMethod(Database::class, 'lastInsertId');

        self::assertSame(0, $method->getNumberOfParameters());
    }

    public function testInTransactionReflectsActiveTransactionState(): void
    {
        self::assertFalse($this->db->inTransaction());

        $this->db->transaction(function (Database $db): void {
            self::assertTrue($db->inTransaction());
        });

        self::assertFalse($this->db->inTransaction());
    }

    // ── transaction() ────────────────────────────────────────────────────────

    public function testTransactionCommitsOnSuccess(): void
    {
        $result = $this->db->transaction(function (Database $db): int {
            $db->query('INSERT INTO users (name, email) VALUES (?, ?)', ['Dave', 'dave@example.com']);
            return 42;
        });

        self::assertSame(42, $result);
        $stmt = $this->db->query('SELECT COUNT(*) AS cnt FROM users WHERE name = ?', ['Dave']);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testTransactionRollsBackOnException(): void
    {
        try {
            $this->db->transaction(function (Database $db): void {
                $db->query('INSERT INTO users (name, email) VALUES (?, ?)', ['Eve', 'eve@example.com']);
                throw new \RuntimeException('intentional failure');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $stmt = $this->db->query('SELECT COUNT(*) AS cnt FROM users WHERE name = ?', ['Eve']);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testTransactionRethrowsOriginalException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('intentional failure');

        $this->db->transaction(function (): void {
            throw new \RuntimeException('intentional failure');
        });
    }

    public function testNestedTransactionCommitsWithTheOuter(): void
    {
        $this->db->transaction(function (Database $db): void {
            $db->query('INSERT INTO users (name, email) VALUES (?, ?)', ['Frank', 'frank@example.com']);

            $db->transaction(function (Database $db): void {
                $db->query('INSERT INTO users (name, email) VALUES (?, ?)', ['Grace', 'grace@example.com']);
            });
        });

        $stmt = $this->db->query('SELECT COUNT(*) AS cnt FROM users');
        self::assertSame(2, (int) $stmt->fetchColumn());
    }

    public function testTransactionReturnsWorkReturnValue(): void
    {
        $value = $this->db->transaction(fn (): string => 'hello');
        self::assertSame('hello', $value);
    }

    // ── query failures do not leak the statement ────────────────────────────

    public function testQueryFailureWithholdsTheStatementAndDriverTextByDefault(): void
    {
        $this->db->query('INSERT INTO users (id, name, email) VALUES (?, ?, ?)', [1, 'Jane Roe', 'jane@example.com']);

        try {
            $this->db->query('INSERT INTO users (id, name, email) VALUES (?, ?, ?)', [1, 'Jane Roe', 'jane@example.com']);
            self::fail('expected the duplicate key to raise a DatabaseException');
        } catch (DatabaseException $e) {
            self::assertStringNotContainsString('INSERT INTO users', $e->getMessage());
            self::assertStringNotContainsString('UNIQUE constraint failed', $e->getMessage());
            self::assertStringContainsString('Query failed', $e->getMessage());

            // Still fully available to a caller that scrubs before logging.
            self::assertStringContainsString('INSERT INTO users', (string) $e->sql());
            self::assertStringContainsString('UNIQUE constraint failed', (string) $e->driverMessage());
        }
    }
}

/**
 * A PDO that records every PDO::ATTR_EMULATE_PREPARES decision made about it,
 * both the driver option it was constructed with and every subsequent
 * setAttribute() call, while behaving as a normal (SQLite-backed) connection so
 * bound-parameter queries actually execute.
 *
 * Recording setAttribute() is the point: pdo_sqlite does not implement the
 * attribute, so getAttribute() would throw and the enforcement could not be
 * observed from the outside on the connection the tests actually use.
 */
final class AttributeSpyPdo extends PDO
{
    public bool $constructorEmulateOption = false;

    /** @var list<bool> every value passed to setAttribute(ATTR_EMULATE_PREPARES), in order */
    public array $emulateSettings = [];

    /**
     * @param array<int, mixed>|null $options
     */
    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null)
    {
        $this->constructorEmulateOption = ($options[PDO::ATTR_EMULATE_PREPARES] ?? false) === true;

        parent::__construct($dsn, $username, $password, $options);
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        if ($attribute === PDO::ATTR_EMULATE_PREPARES) {
            $this->emulateSettings[] = (bool) $value;

            // Swallowed rather than forwarded: pdo_sqlite answers false for an
            // attribute it does not implement, and the parent's return value is
            // what Database's constructor would see.
            return true;
        }

        return parent::setAttribute($attribute, $value);
    }
}

/**
 * A SQLite connection that reports the PostgreSQL driver name, so its rules run without a server.
 */
final class DriverNamePdo extends PDO
{
    public bool $lastInsertIdCalled = false;

    public function lastInsertId(?string $name = null): string|false
    {
        $this->lastInsertIdCalled = true;

        return '42';
    }

    public function getAttribute(int $attribute): mixed
    {
        return $attribute === PDO::ATTR_DRIVER_NAME ? 'pgsql' : parent::getAttribute($attribute);
    }
}
