<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Data;

use PDO;
use PDOException;
use PDOStatement;
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

    public function testFetchModeIsAssoc(): void
    {
        self::assertSame(
            PDO::FETCH_ASSOC,
            $this->db->pdo()->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE),
        );
    }

    // ── query() ──────────────────────────────────────────────────────────────

    public function testQueryReturnsStatement(): void
    {
        $stmt = $this->db->query('SELECT 1 AS val');
        self::assertInstanceOf(PDOStatement::class, $stmt);
    }

    public function testQueryWithPositionalParams(): void
    {
        $this->db->query('INSERT INTO users (name, email) VALUES (?, ?)', ['Alice', 'alice@example.com']);
        $stmt = $this->db->query('SELECT * FROM users WHERE name = ?', ['Alice']);
        $row = $stmt->fetch();
        self::assertSame('Alice', $row['name']);
    }

    public function testQueryWithNamedParams(): void
    {
        $this->db->query('INSERT INTO users (name, email) VALUES (:name, :email)', [
            ':name' => 'Bob',
            ':email' => 'bob@example.com',
        ]);
        $stmt = $this->db->query('SELECT * FROM users WHERE name = :name', [':name' => 'Bob']);
        $row = $stmt->fetch();
        self::assertSame('Bob', $row['name']);
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
        self::assertSame('Alice', $rows[0]['name']);
        self::assertSame('Bob', $rows[1]['name']);
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
        self::assertSame('cara@example.com', $row['email']);
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
        self::assertSame('B', $rows[0]['name']);
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
        self::assertSame('C', $page['items'][0]['name']);
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
        self::assertSame('C', $rows[0]['name']);

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
            static fn (array $row): array => [...$row, 'name' => strtoupper((string) $row['name'])],
        );
        self::assertSame('ALPHA', $mapped->items[0]['name']);
        self::assertSame('BETA', $mapped->items[1]['name']);

        $mappedWith = $this->db->paginateResultMappedWith(
            'SELECT * FROM users ORDER BY id',
            'SELECT COUNT(*) FROM users',
            new PaginationRequest(2, 2),
            static fn (array $row): array => [...$row, 'name' => strtoupper((string) $row['name'])],
        );
        self::assertSame('GAMMA', $mappedWith->items[0]['name']);
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
        self::assertSame('gamma', strtolower((string) $page->firstItem()['name']));
    }

    public function testSortedDatabaseHelpersApplyOrderingAndPagination(): void
    {
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['charlie', 'c@example.com']);
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['alice', 'a@example.com']);
        $this->db->insert('INSERT INTO users (name, email) VALUES (?, ?)', ['bravo', 'b@example.com']);

        $sort = new SortRequest('name', 'ASC');

        $sorted = $this->db->selectSorted('SELECT * FROM users', $sort);
        self::assertSame('alice', $sorted[0]['name']);
        self::assertSame('bravo', $sorted[1]['name']);
        self::assertSame('charlie', $sorted[2]['name']);

        $page = $this->db->selectPageSorted('SELECT * FROM users', $sort, new PaginationRequest(2, 2));
        self::assertCount(1, $page);
        self::assertSame('charlie', $page[0]['name']);

        $typed = $this->db->paginateSortedResultWith(
            'SELECT * FROM users',
            'SELECT COUNT(*) FROM users',
            $sort,
            new PaginationRequest(1, 2),
        );
        self::assertSame('alice', $typed->firstItem()['name']);
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
        self::assertSame('alice', $filtered[0]['name']);

        $filteredSorted = $this->db->selectFilteredSorted(
            'SELECT * FROM users',
            new FilterRequest(['name' => 'charlie']),
            ['name' => 'name'],
            new SortRequest('name', 'DESC'),
        );
        self::assertCount(1, $filteredSorted);
        self::assertSame('charlie', $filteredSorted[0]['name']);
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
        self::assertSame('a@example.com', $result->firstItem()['email']);
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

    // ── lastInsertId() ───────────────────────────────────────────────────────

    public function testLastInsertIdAfterInsert(): void
    {
        $this->db->query('INSERT INTO users (name, email) VALUES (?, ?)', ['Carol', 'carol@example.com']);
        $id = $this->db->lastInsertId();
        self::assertNotFalse($id);
        self::assertGreaterThan(0, (int) $id);
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

    public function testNestedTransactionReusesOuter(): void
    {
        $this->db->transaction(function (Database $db): void {
            $db->query('INSERT INTO users (name, email) VALUES (?, ?)', ['Frank', 'frank@example.com']);

            // Inner transaction should reuse the outer — no exception.
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

    public function testTransactionWrapsBeginFailureAsDatabaseException(): void
    {
        $pdo = new class ('sqlite::memory:') extends PDO {
            public function beginTransaction(): bool
            {
                throw new PDOException('begin failed');
            }
        };

        $db = new Database($pdo);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Transaction failed: begin: begin failed');

        $db->transaction(fn (): string => 'ok');
    }

    public function testTransactionWrapsCommitFailureAsDatabaseException(): void
    {
        $pdo = new class ('sqlite::memory:') extends PDO {
            private bool $inTransaction = false;

            public function beginTransaction(): bool
            {
                $this->inTransaction = true;

                return true;
            }

            public function inTransaction(): bool
            {
                return $this->inTransaction;
            }

            public function commit(): bool
            {
                throw new PDOException('commit failed');
            }

            public function rollBack(): bool
            {
                $this->inTransaction = false;

                return true;
            }
        };

        $db = new Database($pdo);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Transaction failed: commit: commit failed');

        $db->transaction(fn (): string => 'ok');
    }

    public function testTransactionReportsRollbackFailureAfterCommitFailure(): void
    {
        $pdo = new class ('sqlite::memory:') extends PDO {
            private bool $inTransaction = false;

            public function beginTransaction(): bool
            {
                $this->inTransaction = true;

                return true;
            }

            public function inTransaction(): bool
            {
                return $this->inTransaction;
            }

            public function commit(): bool
            {
                throw new PDOException('commit failed hard');
            }

            public function rollBack(): bool
            {
                throw new PDOException('rollback also failed');
            }
        };

        $db = new Database($pdo);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Transaction failed: commit: commit failed hard; rollback after commit failure: rollback also failed');

        $db->transaction(fn (): string => 'ok');
    }

    public function testTransactionReportsRollbackFailureAfterWorkException(): void
    {
        $pdo = new class ('sqlite::memory:') extends PDO {
            private bool $inTransaction = false;

            public function beginTransaction(): bool
            {
                $this->inTransaction = true;

                return true;
            }

            public function inTransaction(): bool
            {
                return $this->inTransaction;
            }

            public function rollBack(): bool
            {
                throw new PDOException('rollback after work failed');
            }
        };

        $db = new Database($pdo);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Transaction failed: rollback after error: rollback after work failed');

        $db->transaction(function (): void {
            throw new \RuntimeException('work failed');
        });
    }
}
