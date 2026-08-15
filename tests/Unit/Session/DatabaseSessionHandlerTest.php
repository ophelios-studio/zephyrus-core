<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Session;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertSame('', $this->handler->read('ffffffffffffffffffffffffffffffff'));
    }

    public function testWriteThenReadReturnsData(): void
    {
        $this->handler->write('43e880c2447ca10d3092d51d258c050c', 'foo=bar');

        self::assertSame('foo=bar', $this->handler->read('43e880c2447ca10d3092d51d258c050c'));
    }

    public function testWriteUpdatesExistingRow(): void
    {
        $this->handler->write('43e880c2447ca10d3092d51d258c050c', 'first');
        $this->handler->write('43e880c2447ca10d3092d51d258c050c', 'second');

        self::assertSame('second', $this->handler->read('43e880c2447ca10d3092d51d258c050c'));

        // Ensure only one row exists.
        $count = $this->database->count('SELECT COUNT(*) FROM session WHERE session_id = ?', ['43e880c2447ca10d3092d51d258c050c']);
        self::assertSame(1, $count);
    }

    public function testDestroyRemovesSession(): void
    {
        $this->handler->write('43e880c2447ca10d3092d51d258c050c', 'data');
        $this->handler->destroy('43e880c2447ca10d3092d51d258c050c');

        self::assertSame('', $this->handler->read('43e880c2447ca10d3092d51d258c050c'));
    }

    public function testDestroyNonexistentSessionDoesNotThrow(): void
    {
        // Should not throw.
        $result = $this->handler->destroy('ffffffffffffffffffffffffffffffff');

        self::assertTrue($result);
    }

    public function testGcRemovesExpiredSessions(): void
    {
        // Insert a row with an old access timestamp.
        $this->database->execute(
            'INSERT INTO session (session_id, access, expire, data) VALUES (?, ?, ?, ?)',
            ['0f1e2d3c4b5a69788796a5b4c3d2e1f0', time() - 7200, time() - 3600, 'old_data'],
        );
        $this->handler->write('aabbccddeeff00112233445566778899', 'fresh_data');

        // GC with a 1-hour lifetime should remove '0f1e2d3c4b5a69788796a5b4c3d2e1f0' but keep 'aabbccddeeff00112233445566778899'.
        $deleted = $this->handler->gc(3600);

        self::assertSame(1, $deleted);
        self::assertSame('', $this->handler->read('0f1e2d3c4b5a69788796a5b4c3d2e1f0'));
        self::assertSame('fresh_data', $this->handler->read('aabbccddeeff00112233445566778899'));
    }

    public function testWriteReturnsTrueOnInsertAndUpdate(): void
    {
        self::assertTrue($this->handler->write('43e880c2447ca10d3092d51d258c050c', 'data'));
        self::assertTrue($this->handler->write('43e880c2447ca10d3092d51d258c050c', 'updated'));
    }

    // ── Session id adoption (strict mode) ─────────────────────────────────────

    /**
     * The test that would have caught the original bug.
     *
     * SessionManager sets session.use_strict_mode=1, but PHP only consults it
     * when the handler supplies validateId(). Without the interface the flag was
     * inert and a client-supplied id was adopted verbatim:
     *
     *   strict mode on, plain handler       -> session_id() = attackerchosenid123
     *   strict mode on, validateId handler  -> session_id() = 43e880c2447c...
     *
     * A test that merely starts a session and reads it back passes against the
     * broken version too, so this asserts the interface and the rejection
     * directly.
     */
    public function testHandlerImplementsTheInterfaceStrictModeRequires(): void
    {
        self::assertInstanceOf(\SessionUpdateTimestampHandlerInterface::class, $this->handler);
    }

    public function testValidateIdRejectsAnIdThatDoesNotAlreadyExist(): void
    {
        // The planted-id case. Rejecting it is what makes PHP discard the
        // client's id and generate a fresh one instead of adopting it.
        self::assertFalse($this->handler->validateId('c0ffee00c0ffee00c0ffee00c0ffee00'));
        self::assertSame(
            0,
            $this->database->count('SELECT COUNT(*) FROM session WHERE session_id = ?', ['c0ffee00c0ffee00c0ffee00c0ffee00']),
            'validating an unknown id must not create a row for it',
        );
    }

    public function testValidateIdAcceptsAnIdThatExistsSoRealSessionsResume(): void
    {
        $this->handler->write('43e880c2447ca10d3092d51d258c050c', 'payload');

        self::assertTrue($this->handler->validateId('43e880c2447ca10d3092d51d258c050c'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedIdProvider(): array
    {
        return [
            'path shaped'        => ['secX/../-marker-18537'],
            'traversal'          => ['../../etc/passwd'],
            'too short'          => ['abc'],
            'overlong'           => [str_repeat('a', 300)],
            'control character'  => ["abc\ndef0123456789012345678901"],
            'null byte'          => ["abcdef0123456789012345678\0"],
            'sql shaped'         => ["' OR 1=1 --"],
            'empty'              => [''],
        ];
    }

    #[DataProvider('malformedIdProvider')]
    public function testMalformedIdsAreRejectedEverywhere(string $id): void
    {
        // An unvalidated id lands in a PRIMARY KEY column: probing produced a
        // real row keyed "secX/../-marker-18537", and another 250 characters
        // long.
        self::assertFalse($this->handler->validateId($id));
        self::assertFalse($this->handler->write($id, 'payload'));
        self::assertSame('', $this->handler->read($id));

        self::assertSame(
            0,
            $this->database->count('SELECT COUNT(*) FROM session', []),
            'no row may ever be stored under a malformed id',
        );
    }

    public function testDestroyToleratesAMalformedIdWithoutBreakingLogout(): void
    {
        // Nothing can have been stored under it, so destroy() is already
        // satisfied. Failing here would break a logout flow for no gain.
        self::assertTrue($this->handler->destroy('../../etc/passwd'));
    }

    public function testACustomIdPatternCanBeSuppliedForAnApplicationThatGeneratesItsOwn(): void
    {
        $handler = new DatabaseSessionHandler($this->database, 'session', 'session_id', '/^tenant-[a-z0-9]{8}$/');

        self::assertTrue($handler->write('tenant-abcd1234', 'payload'));
        self::assertSame('payload', $handler->read('tenant-abcd1234'));
        self::assertFalse($handler->write('43e880c2447ca10d3092d51d258c050c', 'payload'));
    }

    // ── updateTimestamp ───────────────────────────────────────────────────────

    public function testUpdateTimestampRefreshesAccessWithoutAlteringThePayload(): void
    {
        $this->database->execute(
            'INSERT INTO session (session_id, access, expire, data) VALUES (?, ?, ?, ?)',
            ['43e880c2447ca10d3092d51d258c050c', 1000, 2000, 'original payload'],
        );

        // PHP calls updateTimestamp instead of write() when the session did not
        // change, which is most requests, and passes the CURRENT data. The
        // payload in storage must survive untouched.
        self::assertTrue($this->handler->updateTimestamp('43e880c2447ca10d3092d51d258c050c', 'ignored replacement'));

        $row = $this->database->selectOne(
            'SELECT access, expire, data FROM session WHERE session_id = ?',
            ['43e880c2447ca10d3092d51d258c050c'],
        );

        self::assertSame('original payload', $row->data, 'the payload must not be rewritten');
        self::assertGreaterThan(1000, (int) $row->access, 'access must be refreshed');
        self::assertGreaterThan(2000, (int) $row->expire);
    }

    public function testUpdateTimestampRestoresASessionCollectedMidFlight(): void
    {
        // The row is gone (garbage collected between requests). Losing a live
        // session here would be worse than writing it back.
        self::assertTrue($this->handler->updateTimestamp('43e880c2447ca10d3092d51d258c050c', 'live payload'));

        self::assertSame('live payload', $this->handler->read('43e880c2447ca10d3092d51d258c050c'));
    }

    public function testUpdateTimestampRejectsAMalformedId(): void
    {
        self::assertFalse($this->handler->updateTimestamp('../../etc/passwd', 'payload'));
        self::assertSame(0, $this->database->count('SELECT COUNT(*) FROM session', []));
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
        $pdo->competitorSql = "INSERT INTO session (session_id, access, expire, data) VALUES ('b7c1f0a94e2d8135c6a0f4e79b23d581', 1, 2, 'competitor')";

        $database = new Database($pdo);
        $handler = new DatabaseSessionHandler($database, 'session');

        // Must not throw: the competing row appears mid-write.
        self::assertTrue($handler->write('b7c1f0a94e2d8135c6a0f4e79b23d581', 'mine'));
        self::assertTrue($pdo->raced, 'the race window must actually have been exercised');

        // The row ends in a correct state, and there is exactly one of it.
        self::assertSame('mine', $handler->read('b7c1f0a94e2d8135c6a0f4e79b23d581'));
        self::assertSame(1, $database->count('SELECT COUNT(*) FROM session WHERE session_id = ?', ['b7c1f0a94e2d8135c6a0f4e79b23d581']));
    }

    public function testWriteIssuesASingleStatement(): void
    {
        // The property that removes the race: one atomic upsert, no separate
        // read to act on. A second statement would reopen the window above.
        $pdo = new CountingPdo('sqlite::memory:');
        $pdo->exec('CREATE TABLE session (session_id VARCHAR PRIMARY KEY, access INTEGER NOT NULL, expire INTEGER NOT NULL DEFAULT 0, data TEXT NOT NULL DEFAULT "")');

        $handler = new DatabaseSessionHandler(new Database($pdo), 'session');

        $pdo->prepared = 0;
        $handler->write('43e880c2447ca10d3092d51d258c050c', 'first');
        self::assertSame(1, $pdo->prepared, 'insert path must be one statement');

        $pdo->prepared = 0;
        $handler->write('43e880c2447ca10d3092d51d258c050c', 'second');
        self::assertSame(1, $pdo->prepared, 'update path must be one statement');

        self::assertSame('second', $handler->read('43e880c2447ca10d3092d51d258c050c'));
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
