<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Data;

use PDO;
use PHPUnit\Framework\TestCase;
use Zephyrus\Data\Broker;
use Zephyrus\Data\Database;

// ---------------------------------------------------------------------------
// Minimal concrete stub — exposes protected helpers as public for testing.
// ---------------------------------------------------------------------------

final class UserBroker extends Broker
{
    public function findAll(): array
    {
        return $this->select('SELECT * FROM users ORDER BY id');
    }

    public function findById(int $id): ?array
    {
        return $this->selectOne('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function count(): int
    {
        return $this->selectCount('SELECT COUNT(*) FROM users');
    }

    public function findEmailById(int $id): ?string
    {
        $value = $this->selectValue('SELECT email FROM users WHERE id = ?', [$id]);

        return $value === null ? null : (string) $value;
    }

    public function countNamed(string $name): int
    {
        return $this->selectCount('SELECT COUNT(*) FROM users WHERE name = ?', [$name]);
    }

    public function existsByEmail(string $email): bool
    {
        return $this->exists(
            'SELECT EXISTS(SELECT 1 FROM users WHERE email = ?)',
            [$email],
        );
    }

    public function firstIdOr(int $default): int
    {
        return (int) $this->selectValue('SELECT id FROM users ORDER BY id LIMIT 1', default: $default);
    }

    public function insert(string $name, string $email): int
    {
        $this->execute(
            'INSERT INTO users (name, email) VALUES (?, ?)',
            [$name, $email],
        );

        return (int) $this->lastInsertId();
    }

    public function update(int $id, string $name): int
    {
        return $this->execute(
            'UPDATE users SET name = ? WHERE id = ?',
            [$name, $id],
        );
    }

    public function delete(int $id): int
    {
        return $this->execute('DELETE FROM users WHERE id = ?', [$id]);
    }

    public function transactionalInsert(string $name, string $email): int
    {
        return $this->transaction(function () use ($name, $email): int {
            return $this->insert($name, $email);
        });
    }
}

// ---------------------------------------------------------------------------

final class BrokerTest extends TestCase
{
    private UserBroker $broker;
    private Database $db;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->db = new Database($pdo);
        $this->db->query(
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL)'
        );
        $this->broker = new UserBroker($this->db);
    }

    // ── select ───────────────────────────────────────────────────────────────

    public function testSelectReturnsEmptyArrayOnNoRows(): void
    {
        self::assertSame([], $this->broker->findAll());
    }

    public function testSelectReturnsAllRows(): void
    {
        $this->broker->insert('Alice', 'alice@example.com');
        $this->broker->insert('Bob', 'bob@example.com');

        $rows = $this->broker->findAll();
        self::assertCount(2, $rows);
        self::assertSame('Alice', $rows[0]['name']);
        self::assertSame('Bob', $rows[1]['name']);
    }

    // ── selectOne ────────────────────────────────────────────────────────────

    public function testSelectOneReturnsNullOnNoMatch(): void
    {
        self::assertNull($this->broker->findById(999));
    }

    public function testSelectOneReturnsMatchedRow(): void
    {
        $id = $this->broker->insert('Carol', 'carol@example.com');
        $row = $this->broker->findById($id);

        self::assertNotNull($row);
        self::assertSame('Carol', $row['name']);
        self::assertSame('carol@example.com', $row['email']);
    }

    // ── selectCount ──────────────────────────────────────────────────────────

    public function testSelectCountReturnsZeroOnEmpty(): void
    {
        self::assertSame(0, $this->broker->count());
    }

    public function testSelectCountReturnsCorrectCount(): void
    {
        $this->broker->insert('Dave', 'dave@example.com');
        $this->broker->insert('Eve', 'eve@example.com');
        self::assertSame(2, $this->broker->count());
    }

    public function testSelectCountSupportsParameterizedQuery(): void
    {
        $this->broker->insert('Eve', 'eve1@example.com');
        $this->broker->insert('Eve', 'eve2@example.com');
        $this->broker->insert('Alice', 'alice@example.com');

        self::assertSame(2, $this->broker->countNamed('Eve'));
    }

    // ── selectValue ──────────────────────────────────────────────────────────

    public function testSelectValueReturnsScalarValueFromFirstColumn(): void
    {
        $id = $this->broker->insert('Mona', 'mona@example.com');

        self::assertSame('mona@example.com', $this->broker->findEmailById($id));
    }

    public function testSelectValueReturnsNullWhenNoRowsAndNoDefault(): void
    {
        self::assertNull($this->broker->findEmailById(999));
    }

    public function testSelectValueUsesDefaultWhenNoRows(): void
    {
        self::assertSame(123, $this->broker->firstIdOr(123));
    }

    public function testExistsReturnsFalseWhenNoMatchingRow(): void
    {
        self::assertFalse($this->broker->existsByEmail('nope@example.com'));
    }

    public function testExistsReturnsTrueWhenMatchingRowExists(): void
    {
        $this->broker->insert('Zoe', 'zoe@example.com');

        self::assertTrue($this->broker->existsByEmail('zoe@example.com'));
    }

    // ── execute (insert/update/delete) ───────────────────────────────────────

    public function testInsertReturnsNewId(): void
    {
        $id1 = $this->broker->insert('Frank', 'frank@example.com');
        $id2 = $this->broker->insert('Grace', 'grace@example.com');

        self::assertGreaterThan(0, $id1);
        self::assertGreaterThan($id1, $id2);
    }

    public function testUpdateReturnsAffectedRowCount(): void
    {
        $id = $this->broker->insert('Harry', 'harry@example.com');
        $affected = $this->broker->update($id, 'Harold');

        self::assertSame(1, $affected);
        self::assertSame('Harold', $this->broker->findById($id)['name']);
    }

    public function testUpdateNonExistentRowReturnsZero(): void
    {
        self::assertSame(0, $this->broker->update(999, 'Ghost'));
    }

    public function testDeleteReturnsAffectedRowCount(): void
    {
        $id = $this->broker->insert('Iris', 'iris@example.com');
        $affected = $this->broker->delete($id);

        self::assertSame(1, $affected);
        self::assertNull($this->broker->findById($id));
    }

    public function testDeleteNonExistentRowReturnsZero(): void
    {
        self::assertSame(0, $this->broker->delete(999));
    }

    // ── transaction ──────────────────────────────────────────────────────────

    public function testTransactionInsertCommits(): void
    {
        $id = $this->broker->transactionalInsert('Jack', 'jack@example.com');

        self::assertGreaterThan(0, $id);
        self::assertNotNull($this->broker->findById($id));
    }

    public function testTransactionRollsBackOnFailure(): void
    {
        $countBefore = $this->broker->count();

        try {
            $this->db->transaction(function () {
                $this->broker->insert('Kate', 'kate@example.com');
                throw new \RuntimeException('abort');
            });
        } catch (\RuntimeException) {
            // expected
        }

        self::assertSame($countBefore, $this->broker->count());
    }
}
