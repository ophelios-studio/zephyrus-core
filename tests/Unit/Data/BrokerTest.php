<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Data;

use PDO;
use PHPUnit\Framework\TestCase;
use Zephyrus\Data\Broker;
use Zephyrus\Data\Database;
use Zephyrus\Data\PaginatedResult;
use Zephyrus\Data\PaginationRequest;

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
        return $this->selectInt('SELECT id FROM users ORDER BY id LIMIT 1', default: $default);
    }

    public function firstEmailOr(?string $default = null): ?string
    {
        return $this->selectString('SELECT email FROM users ORDER BY id LIMIT 1', default: $default);
    }

    public function averageIdOr(float $default = 0.0): float
    {
        return $this->selectFloat('SELECT AVG(id) FROM users', default: $default);
    }

    public function hasAnyUsers(): bool
    {
        return $this->selectBool('SELECT EXISTS(SELECT 1 FROM users)');
    }

    public function listPage(int $page, int $perPage): array
    {
        return $this->selectPage('SELECT * FROM users ORDER BY id', $page, $perPage);
    }

    public function paginateUsers(int $page, int $perPage): array
    {
        return $this->paginate(
            'SELECT * FROM users ORDER BY id',
            'SELECT COUNT(*) FROM users',
            $page,
            $perPage,
        );
    }

    public function paginateUsersResult(int $page, int $perPage): PaginatedResult
    {
        return $this->paginateResult(
            'SELECT * FROM users ORDER BY id',
            'SELECT COUNT(*) FROM users',
            $page,
            $perPage,
        );
    }

    public function listPageWith(PaginationRequest $pagination): array
    {
        return $this->selectPageWith('SELECT * FROM users ORDER BY id', $pagination);
    }

    public function paginateUsersWith(PaginationRequest $pagination): array
    {
        return $this->paginateWith(
            'SELECT * FROM users ORDER BY id',
            'SELECT COUNT(*) FROM users',
            $pagination,
        );
    }

    public function paginateUsersResultWith(PaginationRequest $pagination): PaginatedResult
    {
        return $this->paginateResultWith(
            'SELECT * FROM users ORDER BY id',
            'SELECT COUNT(*) FROM users',
            $pagination,
        );
    }

    public function paginateUsersNamesMapped(int $page, int $perPage): PaginatedResult
    {
        return $this->paginateResultMapped(
            'SELECT * FROM users ORDER BY id',
            'SELECT COUNT(*) FROM users',
            $page,
            $perPage,
            static fn (array $row): array => [
                ...$row,
                'name' => strtoupper((string) $row['name']),
            ],
        );
    }

    public function paginateUsersNamesMappedWith(PaginationRequest $pagination): PaginatedResult
    {
        return $this->paginateResultMappedWith(
            'SELECT * FROM users ORDER BY id',
            'SELECT COUNT(*) FROM users',
            $pagination,
            static fn (array $row): array => [
                ...$row,
                'name' => strtoupper((string) $row['name']),
            ],
        );
    }

    public function paginateUsersFromQuery(array $query): PaginatedResult
    {
        return $this->paginateResultFromQuery(
            'SELECT * FROM users ORDER BY id',
            'SELECT COUNT(*) FROM users',
            $query,
            defaultPerPage: 25,
            maxPerPage: 2,
        );
    }

    public function insert(string $name, string $email): int
    {
        return (int) $this->insertRowGetId(
            'INSERT INTO users (name, email) VALUES (?, ?)',
            [$name, $email],
        );
    }

    public function update(int $id, string $name): int
    {
        return $this->updateRows(
            'UPDATE users SET name = ? WHERE id = ?',
            [$name, $id],
        );
    }

    public function delete(int $id): int
    {
        return $this->deleteRows('DELETE FROM users WHERE id = ?', [$id]);
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

    public function testTypedScalarHelpersSupportStringFloatAndBool(): void
    {
        self::assertSame('fallback@example.com', $this->broker->firstEmailOr('fallback@example.com'));
        self::assertSame(0.0, $this->broker->averageIdOr(0.0));
        self::assertFalse($this->broker->hasAnyUsers());

        $this->broker->insert('Lara', 'lara@example.com');
        $this->broker->insert('Milo', 'milo@example.com');

        self::assertSame('lara@example.com', $this->broker->firstEmailOr());
        self::assertGreaterThan(0.0, $this->broker->averageIdOr());
        self::assertTrue($this->broker->hasAnyUsers());
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

    public function testSelectPageReturnsWindowedRows(): void
    {
        $this->broker->insert('A', 'a@example.com');
        $this->broker->insert('B', 'b@example.com');
        $this->broker->insert('C', 'c@example.com');

        $rows = $this->broker->listPage(2, 1);

        self::assertCount(1, $rows);
        self::assertSame('B', $rows[0]['name']);
    }

    public function testPaginateReturnsMetadataAndItems(): void
    {
        $this->broker->insert('A', 'a@example.com');
        $this->broker->insert('B', 'b@example.com');
        $this->broker->insert('C', 'c@example.com');

        $page = $this->broker->paginateUsers(2, 2);

        self::assertSame(3, $page['total']);
        self::assertSame(2, $page['page']);
        self::assertSame(2, $page['per_page']);
        self::assertSame(2, $page['total_pages']);
        self::assertTrue($page['has_previous']);
        self::assertFalse($page['has_next']);
        self::assertCount(1, $page['items']);
        self::assertSame('C', $page['items'][0]['name']);
    }

    public function testPaginateResultReturnsObjectEnvelope(): void
    {
        $this->broker->insert('A', 'a@example.com');
        $this->broker->insert('B', 'b@example.com');
        $this->broker->insert('C', 'c@example.com');

        $page = $this->broker->paginateUsersResult(2, 2);

        self::assertInstanceOf(PaginatedResult::class, $page);
        self::assertSame(3, $page->total);
        self::assertSame(2, $page->page);
        self::assertSame(2, $page->perPage);
        self::assertSame(2, $page->totalPages);
        self::assertTrue($page->hasPrevious);
        self::assertFalse($page->hasNext);
        self::assertSame(1, $page->itemCount());
    }

    public function testPaginationRequestBasedBrokerHelpersWork(): void
    {
        $this->broker->insert('A', 'a@example.com');
        $this->broker->insert('B', 'b@example.com');
        $this->broker->insert('C', 'c@example.com');

        $pagination = new PaginationRequest(page: 2, perPage: 2);

        $rows = $this->broker->listPageWith($pagination);
        self::assertCount(1, $rows);
        self::assertSame('C', $rows[0]['name']);

        $page = $this->broker->paginateUsersWith($pagination);
        self::assertSame(3, $page['total']);

        $typed = $this->broker->paginateUsersResultWith($pagination);
        self::assertInstanceOf(PaginatedResult::class, $typed);
        self::assertSame(1, $typed->itemCount());
    }

    public function testMappedPaginationHelpersTransformItems(): void
    {
        $this->broker->insert('alpha', 'a@example.com');
        $this->broker->insert('beta', 'b@example.com');
        $this->broker->insert('gamma', 'c@example.com');

        $mapped = $this->broker->paginateUsersNamesMapped(1, 2);
        self::assertSame('ALPHA', $mapped->items[0]['name']);
        self::assertSame('BETA', $mapped->items[1]['name']);

        $mappedWith = $this->broker->paginateUsersNamesMappedWith(new PaginationRequest(2, 2));
        self::assertSame('GAMMA', $mappedWith->items[0]['name']);
    }

    public function testPaginateFromQueryBuildsBoundedPaginationRequest(): void
    {
        $this->broker->insert('alpha', 'a@example.com');
        $this->broker->insert('beta', 'b@example.com');
        $this->broker->insert('gamma', 'c@example.com');

        $page = $this->broker->paginateUsersFromQuery(['page' => 2, 'per_page' => 999]);

        self::assertSame(2, $page->page);
        self::assertSame(2, $page->perPage);
        self::assertSame(1, $page->itemCount());
        self::assertSame('c@example.com', $page->firstItem()['email']);
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
