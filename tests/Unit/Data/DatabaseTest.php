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
            'port' => 3307,
            'database' => 'zephyrus',
            'username' => 'app',
            'password' => 'secret',
            'charset' => 'utf8mb4',
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

        self::assertSame('mysql:host=db.internal;port=3307;dbname=zephyrus;charset=utf8mb4', $captured['dsn']);
        self::assertSame('app', $captured['username']);
        self::assertSame('secret', $captured['password']);
        self::assertArrayHasKey(PDO::ATTR_PERSISTENT, $captured['options']);
        self::assertFalse($captured['options'][PDO::ATTR_PERSISTENT]);
        self::assertArrayHasKey(PDO::MYSQL_ATTR_INIT_COMMAND, $captured['options']);
        self::assertSame('SET NAMES utf8mb4', $captured['options'][PDO::MYSQL_ATTR_INIT_COMMAND]);
        self::assertInstanceOf(Database::class, $database);
    }

    public function testFromConfigWrapsFactoryFailureAsDatabaseException(): void
    {
        $config = DatabaseConfig::fromArray([
            'database' => 'zephyrus',
            'username' => 'app',
        ]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Database connection failed for DSN [mysql:host=localhost;port=3306;dbname=zephyrus;charset=utf8mb4]: factory boom');

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
        $this->expectExceptionMessage('Database connection failed for DSN [mysql:host=localhost;port=3306;dbname=zephyrus;charset=utf8mb4]: pdo boom');

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

        self::assertSame(1, $this->db->selectInt('SELECT COUNT(*) FROM users'));
        self::assertSame('Dan', $this->db->selectString('SELECT name FROM users WHERE email = ?', ['dan@example.com']));
        self::assertTrue($this->db->selectBool('SELECT EXISTS(SELECT 1 FROM users WHERE email = ?)', ['dan@example.com']));
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
