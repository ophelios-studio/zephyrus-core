<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Session;

use PDO;
use PHPUnit\Framework\TestCase;
use Zephyrus\Data\Database;
use Zephyrus\Session\DatabaseSessionHandler;

/**
 * Tests DatabaseSessionHandler using an in-memory SQLite database so no
 * external services are required.
 */
final class DatabaseSessionHandlerTest extends TestCase
{
    private Database $database;
    private DatabaseSessionHandler $handler;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE session (session_id VARCHAR PRIMARY KEY, access INTEGER NOT NULL, expire INTEGER NOT NULL DEFAULT 0, data TEXT NOT NULL DEFAULT "")');
        $this->database = new Database($pdo);
        $this->handler = new DatabaseSessionHandler($this->database, 'session');
    }

    public function testOpenReturnsTrue(): void
    {
        self::assertTrue($this->handler->open('/tmp', 'PHPSESSID'));
    }

    public function testCloseReturnsTrue(): void
    {
        self::assertTrue($this->handler->close());
    }

    public function testReadMissingKeyReturnsEmptyString(): void
    {
        self::assertSame('', $this->handler->read('nonexistent'));
    }

    public function testWriteThenReadReturnsData(): void
    {
        $this->handler->write('sess_1', 'foo=bar');

        self::assertSame('foo=bar', $this->handler->read('sess_1'));
    }

    public function testWriteUpdatesExistingRow(): void
    {
        $this->handler->write('sess_1', 'first');
        $this->handler->write('sess_1', 'second');

        self::assertSame('second', $this->handler->read('sess_1'));

        // Ensure only one row exists.
        $count = $this->database->count('SELECT COUNT(*) FROM session WHERE session_id = ?', ['sess_1']);
        self::assertSame(1, $count);
    }

    public function testDestroyRemovesSession(): void
    {
        $this->handler->write('sess_1', 'data');
        $this->handler->destroy('sess_1');

        self::assertSame('', $this->handler->read('sess_1'));
    }

    public function testDestroyNonexistentSessionDoesNotThrow(): void
    {
        // Should not throw.
        $result = $this->handler->destroy('nonexistent');

        self::assertTrue($result);
    }

    public function testGcRemovesExpiredSessions(): void
    {
        // Insert a row with an old access timestamp.
        $this->database->execute(
            'INSERT INTO session (session_id, access, expire, data) VALUES (?, ?, ?, ?)',
            ['old_sess', time() - 7200, time() - 3600, 'old_data'],
        );
        $this->handler->write('fresh_sess', 'fresh_data');

        // GC with a 1-hour lifetime should remove 'old_sess' but keep 'fresh_sess'.
        $deleted = $this->handler->gc(3600);

        self::assertSame(1, $deleted);
        self::assertSame('', $this->handler->read('old_sess'));
        self::assertSame('fresh_data', $this->handler->read('fresh_sess'));
    }

    public function testWriteReturnsTrueOnInsertAndUpdate(): void
    {
        self::assertTrue($this->handler->write('sess_1', 'data'));
        self::assertTrue($this->handler->write('sess_1', 'updated'));
    }

    // ── Concurrency ───────────────────────────────────────────────────────────

    /**
     * Reproduces the duplicate-key race that write() used to lose sessions to.
     *
     * write() was a check-then-act: SELECT for an existing row, then INSERT or
     * UPDATE. Two concurrent requests carrying the same NEW session id both saw
     * no row and both INSERTed, and the loser hit a duplicate-key violation on
     * the primary key. Because handlers are commonly wrapped to swallow write
     * failures (so a session problem cannot break a page render), the session
     * write silently did not happen and nothing was logged anywhere.
     *
     * The interleaving is simulated deterministically rather than with threads:
     * RacingPdo inserts the competing row in the instant before the handler's
     * own write statement executes, which is exactly the window that was
     * unsafe. Against the old check-then-act code this test throws
     * DatabaseException with SQLSTATE 23000; against the single atomic upsert
     * it succeeds and the row holds this writer's data.
     */
    public function testWriteSurvivesAConcurrentInsertOfTheSameNewSessionId(): void
    {
        $pdo = new RacingPdo('sqlite::memory:');
        $pdo->exec('CREATE TABLE session (session_id VARCHAR PRIMARY KEY, access INTEGER NOT NULL, expire INTEGER NOT NULL DEFAULT 0, data TEXT NOT NULL DEFAULT "")');
        $pdo->competitorSql = "INSERT INTO session (session_id, access, expire, data) VALUES ('sess_race', 1, 2, 'competitor')";

        $database = new Database($pdo);
        $handler = new DatabaseSessionHandler($database, 'session');

        // Must not throw: the competing row appears mid-write.
        self::assertTrue($handler->write('sess_race', 'mine'));
        self::assertTrue($pdo->raced, 'the race window must actually have been exercised');

        // The row ends in a correct state, and there is exactly one of it.
        self::assertSame('mine', $handler->read('sess_race'));
        self::assertSame(1, $database->count('SELECT COUNT(*) FROM session WHERE session_id = ?', ['sess_race']));
    }

    public function testWriteIssuesASingleStatement(): void
    {
        // The property that removes the race: one atomic upsert, no separate
        // read to act on. A second statement would reopen the window above.
        $pdo = new CountingPdo('sqlite::memory:');
        $pdo->exec('CREATE TABLE session (session_id VARCHAR PRIMARY KEY, access INTEGER NOT NULL, expire INTEGER NOT NULL DEFAULT 0, data TEXT NOT NULL DEFAULT "")');

        $handler = new DatabaseSessionHandler(new Database($pdo), 'session');

        $pdo->prepared = 0;
        $handler->write('sess_1', 'first');
        self::assertSame(1, $pdo->prepared, 'insert path must be one statement');

        $pdo->prepared = 0;
        $handler->write('sess_1', 'second');
        self::assertSame(1, $pdo->prepared, 'update path must be one statement');

        self::assertSame('second', $handler->read('sess_1'));
    }
}

/**
 * Inserts a competing row in the window immediately before the handler's own
 * write statement runs, simulating a second concurrent request.
 */
final class RacingPdo extends \PDO
{
    public bool $raced = false;
    public string $competitorSql = '';

    /**
     * @param array<int, mixed> $options
     */
    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        if (!$this->raced && $this->competitorSql !== '' && stripos(ltrim($query), 'INSERT') === 0) {
            $this->raced = true;
            parent::exec($this->competitorSql);
        }

        return parent::prepare($query, $options);
    }
}

/** Counts prepared statements so a check-then-act shape is detectable. */
final class CountingPdo extends \PDO
{
    public int $prepared = 0;

    /**
     * @param array<int, mixed> $options
     */
    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $this->prepared++;

        return parent::prepare($query, $options);
    }
}
