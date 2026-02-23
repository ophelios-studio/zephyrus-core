<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Data;

use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
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

    // ── construction & pdo() ─────────────────────────────────────────────────

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

    // ── lastInsertId() ───────────────────────────────────────────────────────

    public function testLastInsertIdAfterInsert(): void
    {
        $this->db->query('INSERT INTO users (name, email) VALUES (?, ?)', ['Carol', 'carol@example.com']);
        $id = $this->db->lastInsertId();
        self::assertNotFalse($id);
        self::assertGreaterThan(0, (int) $id);
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
