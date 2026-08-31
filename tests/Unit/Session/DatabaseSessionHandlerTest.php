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

    /**
     * Replaces testUpdateTimestampRestoresASessionCollectedMidFlight, which
     * asserted the opposite and blessed the bug.
     *
     * The old insert branch existed to save a session garbage-collected
     * mid-flight, and it could not tell that case apart from a row DELETED on
     * purpose. So logout, sign-out-everywhere and the session eviction a
     * password reset performs were all undone by any request that was already
     * open when the delete landed: the whole authenticated payload went
     * straight back under the same id.
     */
    public function testUpdateTimestampNeverRecreatesARowThatIsNoLongerThere(): void
    {
        self::assertTrue($this->handler->updateTimestamp('43e880c2447ca10d3092d51d258c050c', 'live payload'));

        self::assertSame(
            0,
            $this->database->count('SELECT COUNT(*) FROM session', []),
            'refreshing the access window must never be able to create a session',
        );
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

    // ── Deletion survives an in-flight request ────────────────────────────────

    /**
     * The finding this whole state-tracking exists for.
     *
     * An attacker holding a stolen cookie polls; the victim resets their
     * password, which deletes every row for that user. The attacker's request
     * had already read the row, so its write() used to upsert the authenticated
     * payload back under the same id and the stolen session survived the reset.
     * Measured against the previous code: logout at t+0.00s, a slow request
     * writing at t+2.01s, and the id was still alive with its payload restored.
     */
    public function testWriteDoesNotResurrectASessionDeletedWhileTheRequestWasInFlight(): void
    {
        $this->handler->write('43e880c2447ca10d3092d51d258c050c', 'user_id|i:1;');

        // The in-flight request loads the session...
        self::assertSame('user_id|i:1;', $this->handler->read('43e880c2447ca10d3092d51d258c050c'));

        // ...and the logout lands from another request while it is running.
        $this->database->execute('DELETE FROM session WHERE session_id = ?', ['43e880c2447ca10d3092d51d258c050c']);

        // The in-flight request now saves what it has.
        self::assertTrue($this->handler->write('43e880c2447ca10d3092d51d258c050c', 'user_id|i:1;role|s:5:"admin";'));

        self::assertSame(
            0,
            $this->database->count('SELECT COUNT(*) FROM session WHERE session_id = ?', ['43e880c2447ca10d3092d51d258c050c']),
            'a revoked session must stay revoked',
        );
        self::assertSame('', $this->handler->read('43e880c2447ca10d3092d51d258c050c'));
    }

    public function testUpdateTimestampDoesNotResurrectASessionDeletedWhileTheRequestWasInFlight(): void
    {
        $this->handler->write('43e880c2447ca10d3092d51d258c050c', 'user_id|i:1;');
        $this->handler->read('43e880c2447ca10d3092d51d258c050c');

        $this->database->execute('DELETE FROM session WHERE session_id = ?', ['43e880c2447ca10d3092d51d258c050c']);

        // PHP calls this, not write(), when the payload did not change, which
        // is most requests and therefore the likelier half of the race.
        self::assertTrue($this->handler->updateTimestamp('43e880c2447ca10d3092d51d258c050c', 'user_id|i:1;'));

        self::assertSame(
            0,
            $this->database->count('SELECT COUNT(*) FROM session WHERE session_id = ?', ['43e880c2447ca10d3092d51d258c050c']),
        );
    }

    public function testWriteRefusesToRebuildARowTheSameRequestJustDestroyed(): void
    {
        $this->handler->write('43e880c2447ca10d3092d51d258c050c', 'payload');
        $this->handler->read('43e880c2447ca10d3092d51d258c050c');
        $this->handler->destroy('43e880c2447ca10d3092d51d258c050c');

        self::assertTrue($this->handler->write('43e880c2447ca10d3092d51d258c050c', 'payload'));
        self::assertSame(0, $this->database->count('SELECT COUNT(*) FROM session', []));
    }

    /**
     * The other half of the fix: refusing to resurrect must not stop a session
     * from being CREATED. PHP calls write() (not updateTimestamp) for a
     * brand-new session, with an empty payload, so this is the path every
     * first request takes.
     */
    public function testWriteStillCreatesTheRowForASessionThisRequestOpened(): void
    {
        self::assertSame('', $this->handler->read('43e880c2447ca10d3092d51d258c050c'));

        self::assertTrue($this->handler->write('43e880c2447ca10d3092d51d258c050c', ''));
        self::assertSame(1, $this->database->count('SELECT COUNT(*) FROM session', []));
    }

    // ── Expiry is enforced, not merely recorded ───────────────────────────────

    /**
     * The `expire` column used to be written by write() and updateTimestamp()
     * and READ BY NOTHING, so expiry rested entirely on PHP's GC lottery, which
     * this framework never configures and which Debian and Ubuntu ship disabled
     * (session.gc_probability = 0). A 30-day-old row was adopted and returned
     * its payload.
     */
    public function testAnExpiredRowIsNotAdoptedEvenWhenGarbageCollectionNeverRan(): void
    {
        $this->insertExpiredSession();

        self::assertFalse($this->handler->validateId('43e880c2447ca10d3092d51d258c050c'));
    }

    public function testAnExpiredRowReadsAsAbsent(): void
    {
        $this->insertExpiredSession();

        self::assertSame('', $this->handler->read('43e880c2447ca10d3092d51d258c050c'));
        self::assertSame(
            1,
            $this->database->count('SELECT COUNT(*) FROM session', []),
            'read() must not delete: gc() owns removal, and a read that writes costs every page load a write',
        );
    }

    private function insertExpiredSession(): void
    {
        $this->database->execute(
            'INSERT INTO session (session_id, access, expire, data) VALUES (?, ?, ?, ?)',
            [
                '43e880c2447ca10d3092d51d258c050c',
                time() - 2592000,
                time() - 2592000 + 1440,
                'user_id|i:1;role|s:5:"admin";',
            ],
        );
    }

    // ── Id shape ──────────────────────────────────────────────────────────────

    /**
     * PCRE's "$" also matches immediately before a trailing newline, so the
     * previous pattern accepted 32 valid characters followed by "\n" and stored
     * it as a second, distinct primary key. Unreachable through PHP today
     * (PHP validates the cookie character set first), so latent rather than
     * live, but the pattern is the only bound on what can reach that column.
     */
    public function testAnIdWithATrailingNewlineIsRejected(): void
    {
        $id = str_repeat('a', 32) . "\n";

        self::assertFalse($this->handler->validateId($id));
        self::assertFalse($this->handler->write($id, 'payload'));
        self::assertSame('', $this->handler->read($id));
        self::assertSame(0, $this->database->count('SELECT COUNT(*) FROM session', []));
    }

    // ── Strict mode ───────────────────────────────────────────────────────────

    /**
     * The class docblock used to tell you to register the handler with a bare
     * session_set_save_handler() call. Followed verbatim, that left
     * session.use_strict_mode at PHP's default of 0 and PHP ADOPTED the id the
     * client sent. validateId() cannot catch it, because PHP only calls
     * validateId() when strict mode is already on, so the constructor has to
     * turn the flag on itself.
     *
     * Run in a subprocess because a session ini setting cannot be changed once
     * output has begun, and PHPUnit has printed its progress dots long before
     * this test runs.
     */
    public function testConstructingTheHandlerTurnsStrictModeOnSoAPlantedIdIsDiscarded(): void
    {
        $planted = str_repeat('a', 32);
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';

        $script = <<<PHP
            require '{$autoload}';
            \$pdo = new PDO('sqlite::memory:');
            \$pdo->exec('CREATE TABLE session (session_id VARCHAR PRIMARY KEY, access INTEGER NOT NULL, expire INTEGER NOT NULL DEFAULT 0, data TEXT NOT NULL DEFAULT "")');
            ini_set('session.use_cookies', '0');
            session_id('{$planted}');
            session_set_save_handler(
                new Zephyrus\\Session\\DatabaseSessionHandler(new Zephyrus\\Data\\Database(\$pdo), 'session'),
                true
            );
            session_start();
            echo ini_get('session.use_strict_mode'), '|', session_id();
            PHP;

        $command = sprintf(
            '%s -d session.use_strict_mode=0 -r %s 2>/dev/null',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
        );

        $output = (string) shell_exec($command);
        [$strictMode, $sessionId] = explode('|', $output, 2);

        self::assertSame('1', $strictMode, 'the handler must turn strict mode on for the registration it documents');
        self::assertNotSame($planted, $sessionId, 'a client-supplied id that exists nowhere must be discarded');
        self::assertNotSame('', $sessionId);
    }

    // ── Locking (PostgreSQL) ──────────────────────────────────────────────────

    /**
     * write() hands the database the WHOLE payload, so two concurrent requests
     * on one session lose each other's changes: request A mints a CSRF token,
     * request B writes a locale, and A's token is gone. PHP's own `files`
     * handler holds an flock for the whole request; this handler held nothing.
     *
     * The lock itself is proven against a real PostgreSQL with two concurrent
     * connections, which SQLite cannot model. What is asserted here is that the
     * statements are issued at all, and in the right order, on a pgsql driver.
     */
    public function testAPostgresConnectionLocksTheSessionForTheWholeRequest(): void
    {
        $pdo = new AdvisoryLockRecordingPdo('sqlite::memory:');
        $pdo->exec('CREATE TABLE session (session_id VARCHAR PRIMARY KEY, access INTEGER NOT NULL, expire INTEGER NOT NULL DEFAULT 0, data TEXT NOT NULL DEFAULT "")');
        $handler = new DatabaseSessionHandler(new Database($pdo), 'session');

        $handler->read('43e880c2447ca10d3092d51d258c050c');
        self::assertSame(['pg_try_advisory_lock'], $pdo->advisoryCalls, 'read() must take the lock before it reads');

        $handler->write('43e880c2447ca10d3092d51d258c050c', 'payload');
        self::assertSame(['pg_try_advisory_lock', 'pg_advisory_unlock'], $pdo->advisoryCalls);
    }

    public function testTheLockIsAlsoReleasedByCloseForARequestThatNeverWrites(): void
    {
        $pdo = new AdvisoryLockRecordingPdo('sqlite::memory:');
        $pdo->exec('CREATE TABLE session (session_id VARCHAR PRIMARY KEY, access INTEGER NOT NULL, expire INTEGER NOT NULL DEFAULT 0, data TEXT NOT NULL DEFAULT "")');
        $handler = new DatabaseSessionHandler(new Database($pdo), 'session');

        $handler->read('43e880c2447ca10d3092d51d258c050c');
        $handler->close();

        self::assertSame(['pg_try_advisory_lock', 'pg_advisory_unlock'], $pdo->advisoryCalls);
    }

    public function testLockingCanBeTurnedOffForADeploymentThatCannotAffordIt(): void
    {
        $pdo = new AdvisoryLockRecordingPdo('sqlite::memory:');
        $pdo->exec('CREATE TABLE session (session_id VARCHAR PRIMARY KEY, access INTEGER NOT NULL, expire INTEGER NOT NULL DEFAULT 0, data TEXT NOT NULL DEFAULT "")');
        $handler = new DatabaseSessionHandler(new Database($pdo), 'session', 'session_id', DatabaseSessionHandler::DEFAULT_ID_PATTERN, false);

        $handler->read('43e880c2447ca10d3092d51d258c050c');
        $handler->write('43e880c2447ca10d3092d51d258c050c', 'payload');

        self::assertSame([], $pdo->advisoryCalls);
    }

    public function testASqliteConnectionTakesNoLockAndIsUnaffected(): void
    {
        $pdo = new AdvisoryLockRecordingPdo('sqlite::memory:');
        $pdo->reportedDriver = 'sqlite';
        $pdo->exec('CREATE TABLE session (session_id VARCHAR PRIMARY KEY, access INTEGER NOT NULL, expire INTEGER NOT NULL DEFAULT 0, data TEXT NOT NULL DEFAULT "")');
        $handler = new DatabaseSessionHandler(new Database($pdo), 'session');

        $handler->read('43e880c2447ca10d3092d51d258c050c');
        $handler->write('43e880c2447ca10d3092d51d258c050c', 'payload');

        self::assertSame([], $pdo->advisoryCalls);
        self::assertSame('payload', $handler->read('43e880c2447ca10d3092d51d258c050c'));
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

/**
 * Answers "pgsql" to a driver-name lookup and records the advisory-lock calls
 * the handler makes, rewriting them to something SQLite can execute.
 */
final class AdvisoryLockRecordingPdo extends \PDO
{
    public string $reportedDriver = 'pgsql';

    /** @var list<string> */
    public array $advisoryCalls = [];

    public function getAttribute(int $attribute): mixed
    {
        if ($attribute === \PDO::ATTR_DRIVER_NAME) {
            return $this->reportedDriver;
        }

        return parent::getAttribute($attribute);
    }

    /**
     * @param array<int, mixed> $options
     */
    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        if (str_contains($query, 'pg_try_advisory_lock')) {
            $this->advisoryCalls[] = 'pg_try_advisory_lock';

            return parent::prepare('SELECT 1 WHERE ? IS NOT NULL AND ? IS NOT NULL', $options);
        }

        if (str_contains($query, 'pg_advisory_unlock')) {
            $this->advisoryCalls[] = 'pg_advisory_unlock';

            return parent::prepare('SELECT 1 WHERE ? IS NOT NULL AND ? IS NOT NULL', $options);
        }

        return parent::prepare($query, $options);
    }
}
