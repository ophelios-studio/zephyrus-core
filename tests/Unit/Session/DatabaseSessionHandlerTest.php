<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Session;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Zephyrus\Data\Database;
use Zephyrus\Data\DatabaseException;
use Zephyrus\Session\DatabaseSessionHandler;
use Zephyrus\Session\SessionException;

/**
 * Tests DatabaseSessionHandler using an in-memory SQLite database so no
 * external services are required.
 */
final class DatabaseSessionHandlerTest extends TestCase
{
    private const SCHEMA = 'CREATE TABLE session (session_id VARCHAR PRIMARY KEY, access INTEGER NOT NULL, expire INTEGER NOT NULL DEFAULT 0, data TEXT NOT NULL DEFAULT "")';

    private Database $database;
    private DatabaseSessionHandler $handler;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec(self::SCHEMA);
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
        $this->handler->read('43e880c2447ca10d3092d51d258c050c');
        $this->handler->write('43e880c2447ca10d3092d51d258c050c', 'foo=bar');

        self::assertSame('foo=bar', $this->handler->read('43e880c2447ca10d3092d51d258c050c'));
    }

    public function testWriteUpdatesExistingRow(): void
    {
        $this->handler->read('43e880c2447ca10d3092d51d258c050c');
        $this->handler->write('43e880c2447ca10d3092d51d258c050c', 'first');
        $this->handler->read('43e880c2447ca10d3092d51d258c050c');
        $this->handler->write('43e880c2447ca10d3092d51d258c050c', 'second');

        self::assertSame('second', $this->handler->read('43e880c2447ca10d3092d51d258c050c'));

        // Ensure only one row exists.
        $count = $this->database->count('SELECT COUNT(*) FROM session WHERE session_id = ?', ['43e880c2447ca10d3092d51d258c050c']);
        self::assertSame(1, $count);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function payloads(): iterable
    {
        yield 'object with private and protected properties' => ['cart|' . serialize(new SessionPayloadObject('Zoë', 3))];
        yield 'nul only' => ["\0"];
        yield 'leading nul' => ["\0user_id|i:1;"];
        yield 'not utf-8' => ["token|s:3:\"\xff\xfe\x80\";"];
        yield 'shaped like an encoded payload' => ['base64:dXNlcl9pZHxpOjE7'];
        yield 'encoded prefix only' => ['base64:'];
        yield 'empty' => [''];
        yield 'zero' => ['0'];
        yield 'unicode' => ['name|s:5:"Zoë";'];
        yield 'a megabyte with a nul at the end' => [str_repeat('a', 1 << 20) . "\0"];
    }

    #[DataProvider('payloads')]
    public function testAnyPayloadRoundTripsUnchanged(string $payload): void
    {
        $this->handler->read('43e880c2447ca10d3092d51d258c050c');
        $this->handler->write('43e880c2447ca10d3092d51d258c050c', $payload);

        $handler = new DatabaseSessionHandler($this->database, 'session');
        self::assertSame($payload, $handler->read('43e880c2447ca10d3092d51d258c050c'));
    }

    #[DataProvider('payloads')]
    public function testAnyPayloadRoundTripsThroughAResumedSession(string $payload): void
    {
        $this->handler->read('43e880c2447ca10d3092d51d258c050c');
        $this->handler->write('43e880c2447ca10d3092d51d258c050c', 'user_id|i:1;');
        $this->handler->read('43e880c2447ca10d3092d51d258c050c');
        self::assertTrue($this->handler->write('43e880c2447ca10d3092d51d258c050c', $payload));

        self::assertSame($payload, $this->handler->read('43e880c2447ca10d3092d51d258c050c'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function payloadsATextColumnCannotHoldVerbatim(): iterable
    {
        yield 'object with private and protected properties' => ['cart|' . serialize(new SessionPayloadObject('Zoë', 3))];
        yield 'not utf-8' => ["token|s:3:\"\xff\xfe\x80\";"];
        yield 'shaped like an encoded payload' => ['base64:dXNlcl9pZHxpOjE7'];
    }

    #[DataProvider('payloadsATextColumnCannotHoldVerbatim')]
    public function testAPayloadATextColumnCannotHoldVerbatimIsStoredAsBase64(string $payload): void
    {
        $this->handler->read('43e880c2447ca10d3092d51d258c050c');
        $this->handler->write('43e880c2447ca10d3092d51d258c050c', $payload);

        $stored = $this->storedPayload('43e880c2447ca10d3092d51d258c050c');
        self::assertSame('base64:' . base64_encode($payload), $stored);
    }

    public function testATextPayloadIsStillStoredVerbatim(): void
    {
        $this->handler->read('43e880c2447ca10d3092d51d258c050c');
        $this->handler->write('43e880c2447ca10d3092d51d258c050c', 'user_id|s:5:"Zoë";');

        self::assertSame('user_id|s:5:"Zoë";', $this->storedPayload('43e880c2447ca10d3092d51d258c050c'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rowsWrittenBeforePayloadsWereEncoded(): iterable
    {
        yield 'php serializer' => ['user_id|i:1;'];
        yield 'php_serialize serializer' => ['a:1:{s:7:"user_id";i:1;}'];
        yield 'empty' => [''];
        yield 'key named like the prefix' => ['base64:|i:1;'];
        yield 'prefix followed by non-canonical base64' => ['base64:YWI'];
        yield 'prefix followed by base64 with whitespace' => ['base64:YW Jj'];
    }

    #[DataProvider('rowsWrittenBeforePayloadsWereEncoded')]
    public function testARowWrittenBeforePayloadsWereEncodedReadsBackAsStored(string $stored): void
    {
        $this->database->execute(
            'INSERT INTO session (session_id, access, expire, data) VALUES (?, ?, ?, ?)',
            ['43e880c2447ca10d3092d51d258c050c', time(), time() + 1440, $stored],
        );

        self::assertSame($stored, $this->handler->read('43e880c2447ca10d3092d51d258c050c'));
    }

    public function testDestroyRemovesSession(): void
    {
        $this->handler->read('43e880c2447ca10d3092d51d258c050c');
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
        $this->handler->read('aabbccddeeff00112233445566778899');
        $this->handler->write('aabbccddeeff00112233445566778899', 'fresh_data');

        // GC with a 1-hour lifetime should remove '0f1e2d3c4b5a69788796a5b4c3d2e1f0' but keep 'aabbccddeeff00112233445566778899'.
        $deleted = $this->handler->gc(3600);

        self::assertSame(1, $deleted);
        self::assertSame('', $this->handler->read('0f1e2d3c4b5a69788796a5b4c3d2e1f0'));
        self::assertSame('fresh_data', $this->handler->read('aabbccddeeff00112233445566778899'));
    }

    public function testWriteReturnsTrueOnInsertAndUpdate(): void
    {
        $this->handler->read('43e880c2447ca10d3092d51d258c050c');
        self::assertTrue($this->handler->write('43e880c2447ca10d3092d51d258c050c', 'data'));
        $this->handler->read('43e880c2447ca10d3092d51d258c050c');
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
        $this->handler->read('43e880c2447ca10d3092d51d258c050c');
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

        $handler->read('tenant-abcd1234');
        self::assertTrue($handler->write('tenant-abcd1234', 'payload'));
        self::assertSame('payload', $handler->read('tenant-abcd1234'));
        self::assertFalse($handler->write('43e880c2447ca10d3092d51d258c050c', 'payload'));
    }

    // ── updateTimestamp ───────────────────────────────────────────────────────

    public function testUpdateTimestampRefreshesAccessWithoutAlteringThePayload(): void
    {
        $access = time() - 100;
        $this->database->execute(
            'INSERT INTO session (session_id, access, expire, data) VALUES (?, ?, ?, ?)',
            ['43e880c2447ca10d3092d51d258c050c', $access, $access + 200, 'original payload'],
        );
        self::assertSame('original payload', $this->handler->read('43e880c2447ca10d3092d51d258c050c'));

        // PHP calls updateTimestamp instead of write() when the session did not
        // change, which is most requests, and passes the CURRENT data. The
        // payload in storage must survive untouched.
        self::assertTrue($this->handler->updateTimestamp('43e880c2447ca10d3092d51d258c050c', 'ignored replacement'));

        $row = $this->database->selectOne(
            'SELECT access, expire, data FROM session WHERE session_id = ?',
            ['43e880c2447ca10d3092d51d258c050c'],
        );

        self::assertSame('original payload', $row->data, 'the payload must not be rewritten');
        self::assertGreaterThan($access, (int) $row->access, 'access must be refreshed');
        self::assertGreaterThan($access + 200, (int) $row->expire);
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
        self::assertSame('', $this->handler->read('43e880c2447ca10d3092d51d258c050c'));
        self::assertFalse($this->handler->updateTimestamp('43e880c2447ca10d3092d51d258c050c', 'live payload'));

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
        $pdo->exec(self::SCHEMA);
        $pdo->competitorSql = "INSERT INTO session (session_id, access, expire, data) VALUES ('b7c1f0a94e2d8135c6a0f4e79b23d581', 1, 2, 'competitor')";

        $database = new Database($pdo);
        $handler = new DatabaseSessionHandler($database, 'session');

        self::assertSame('', $handler->read('b7c1f0a94e2d8135c6a0f4e79b23d581'));

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
        $this->handler->read('43e880c2447ca10d3092d51d258c050c');
        $this->handler->write('43e880c2447ca10d3092d51d258c050c', 'user_id|i:1;');

        // The in-flight request loads the session...
        self::assertSame('user_id|i:1;', $this->handler->read('43e880c2447ca10d3092d51d258c050c'));

        // ...and the logout lands from another request while it is running.
        $this->database->execute('DELETE FROM session WHERE session_id = ?', ['43e880c2447ca10d3092d51d258c050c']);

        // The in-flight request now saves what it has.
        self::assertFalse($this->handler->write('43e880c2447ca10d3092d51d258c050c', 'user_id|i:1;role|s:5:"admin";'));

        self::assertSame(
            0,
            $this->database->count('SELECT COUNT(*) FROM session WHERE session_id = ?', ['43e880c2447ca10d3092d51d258c050c']),
            'a revoked session must stay revoked',
        );
        self::assertSame('', $this->handler->read('43e880c2447ca10d3092d51d258c050c'));
    }

    public function testUpdateTimestampDoesNotResurrectASessionDeletedWhileTheRequestWasInFlight(): void
    {
        $this->handler->read('43e880c2447ca10d3092d51d258c050c');
        $this->handler->write('43e880c2447ca10d3092d51d258c050c', 'user_id|i:1;');
        $this->handler->read('43e880c2447ca10d3092d51d258c050c');

        $this->database->execute('DELETE FROM session WHERE session_id = ?', ['43e880c2447ca10d3092d51d258c050c']);

        // PHP calls this, not write(), when the payload did not change, which
        // is most requests and therefore the likelier half of the race.
        self::assertFalse($this->handler->updateTimestamp('43e880c2447ca10d3092d51d258c050c', 'user_id|i:1;'));

        self::assertSame(
            0,
            $this->database->count('SELECT COUNT(*) FROM session WHERE session_id = ?', ['43e880c2447ca10d3092d51d258c050c']),
        );
    }

    public function testWriteRefusesToRebuildARowTheSameRequestJustDestroyed(): void
    {
        $this->handler->read('43e880c2447ca10d3092d51d258c050c');
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

    /**
     * PHP reads before it writes, so only a wrapper that skipped read() (its
     * database was briefly unreachable, say) writes an id this handler never
     * read, with a payload built without the stored one.
     */
    public function testWriteRefusesAnIdThisHandlerNeverReadSoTheStoredSessionSurvives(): void
    {
        $this->insertLiveSession($this->database);

        $warnings = $this->collectWarnings(function (): void {
            self::assertFalse($this->handler->write('43e880c2447ca10d3092d51d258c050c', ''));
        });

        self::assertSame('user_id|i:1;', $this->storedPayload('43e880c2447ca10d3092d51d258c050c'));
        self::assertNeverReadWarning($warnings);
    }

    public function testWriteForAnIdThisHandlerNeverReadCreatesNothing(): void
    {
        $warnings = $this->collectWarnings(function (): void {
            self::assertFalse($this->handler->write('43e880c2447ca10d3092d51d258c050c', 'user_id|i:1;'));
        });

        self::assertSame(0, $this->database->count('SELECT COUNT(*) FROM session', []));
        self::assertNeverReadWarning($warnings);
    }

    public function testUpdateTimestampRefusesAnIdThisHandlerNeverRead(): void
    {
        $this->database->execute(
            'INSERT INTO session (session_id, access, expire, data) VALUES (?, ?, ?, ?)',
            ['43e880c2447ca10d3092d51d258c050c', 1000, time() + 1440, 'user_id|i:1;'],
        );

        $warnings = $this->collectWarnings(function (): void {
            self::assertFalse($this->handler->updateTimestamp('43e880c2447ca10d3092d51d258c050c', 'user_id|i:1;'));
        });

        self::assertSame(1000, $this->database->selectInt('SELECT access FROM session'));
        self::assertNeverReadWarning($warnings);
    }

    /**
     * @param list<array{int, string}> $warnings
     */
    private static function assertNeverReadWarning(array $warnings): void
    {
        self::assertCount(1, $warnings);
        self::assertSame(E_USER_WARNING, $warnings[0][0]);
        self::assertStringContainsString('read()', $warnings[0][1]);
        self::assertStringNotContainsString('43e880c2447ca10d3092d51d258c050c', $warnings[0][1]);
    }

    // ── What the write methods report ─────────────────────────────────────────

    public function testUpdateTimestampReportsTheRowItRefreshed(): void
    {
        $this->handler->read('43e880c2447ca10d3092d51d258c050c');
        $this->handler->write('43e880c2447ca10d3092d51d258c050c', 'user_id|i:1;');
        $this->handler->read('43e880c2447ca10d3092d51d258c050c');

        self::assertTrue($this->handler->updateTimestamp('43e880c2447ca10d3092d51d258c050c', 'user_id|i:1;'));
    }

    public function testDestroyReportsSuccessWhetherItDeletedTheRowOrFoundItGone(): void
    {
        $this->handler->read('43e880c2447ca10d3092d51d258c050c');
        $this->handler->write('43e880c2447ca10d3092d51d258c050c', 'user_id|i:1;');

        self::assertTrue($this->handler->destroy('43e880c2447ca10d3092d51d258c050c'));
        self::assertTrue($this->handler->destroy('43e880c2447ca10d3092d51d258c050c'));
        self::assertSame(0, $this->database->count('SELECT COUNT(*) FROM session', []));
    }

    public function testADestroyWhoseStatementFailsDoesNotReportSuccess(): void
    {
        $handler = new DatabaseSessionHandler(new Database(new PDO('sqlite::memory:')), 'missing_table');

        $this->expectException(DatabaseException::class);
        $handler->destroy('43e880c2447ca10d3092d51d258c050c');
    }

    // ── Expiry window ─────────────────────────────────────────────────────────

    public function testTheExpiryFollowsSessionGcMaxlifetime(): void
    {
        $this->handler->read('43e880c2447ca10d3092d51d258c050c');
        $this->handler->write('43e880c2447ca10d3092d51d258c050c', 'user_id|i:1;');

        self::assertSame(
            (int) ini_get('session.gc_maxlifetime'),
            $this->database->selectInt('SELECT expire - access FROM session'),
        );
    }

    // ── A failed read ─────────────────────────────────────────────────────────

    /**
     * A wrapper that catches the read failure hands PHP an empty session, and
     * PHP then writes that anonymous payload back under the same cookie.
     */
    public function testAFailedReadStopsWriteFromOverwritingTheStoredSession(): void
    {
        [$pdo, $handler, $database] = $this->recordingHandler();
        $pdo->failSessionReads = true;
        $database->execute(
            'INSERT INTO session (session_id, access, expire, data) VALUES (?, ?, ?, ?)',
            ['43e880c2447ca10d3092d51d258c050c', time(), time() + 1440, 'user_id|i:1;'],
        );

        $this->readExpectingFailure($handler, '43e880c2447ca10d3092d51d258c050c');

        self::assertFalse($handler->write('43e880c2447ca10d3092d51d258c050c', ''));
        $pdo->failSessionReads = false;
        self::assertSame('user_id|i:1;', $database->selectString('SELECT data FROM session'));
    }

    public function testAFailedReadStopsUpdateTimestampFromTouchingTheRow(): void
    {
        [$pdo, $handler, $database] = $this->recordingHandler();
        $pdo->failSessionReads = true;
        $database->execute(
            'INSERT INTO session (session_id, access, expire, data) VALUES (?, ?, ?, ?)',
            ['43e880c2447ca10d3092d51d258c050c', 1000, time() + 1440, 'user_id|i:1;'],
        );

        $this->readExpectingFailure($handler, '43e880c2447ca10d3092d51d258c050c');

        self::assertFalse($handler->updateTimestamp('43e880c2447ca10d3092d51d258c050c', ''));
        self::assertSame(1000, $database->selectInt('SELECT access FROM session'));
    }

    public function testAFailedReadOfAnUnknownIdStillCreatesNothing(): void
    {
        [$pdo, $handler, $database] = $this->recordingHandler();
        $pdo->failSessionReads = true;

        $this->readExpectingFailure($handler, '43e880c2447ca10d3092d51d258c050c');

        self::assertFalse($handler->write('43e880c2447ca10d3092d51d258c050c', ''));
        self::assertSame(0, $database->count('SELECT COUNT(*) FROM session', []));
    }

    public function testASuccessfulReadAfterAFailedOneLetsTheWriteThrough(): void
    {
        [$pdo, $handler, $database] = $this->recordingHandler();
        $pdo->failSessionReads = true;
        $database->execute(
            'INSERT INTO session (session_id, access, expire, data) VALUES (?, ?, ?, ?)',
            ['43e880c2447ca10d3092d51d258c050c', time(), time() + 1440, 'user_id|i:1;'],
        );

        $this->readExpectingFailure($handler, '43e880c2447ca10d3092d51d258c050c');
        $pdo->failSessionReads = false;
        self::assertSame('user_id|i:1;', $handler->read('43e880c2447ca10d3092d51d258c050c'));

        self::assertTrue($handler->write('43e880c2447ca10d3092d51d258c050c', 'user_id|i:2;'));
        self::assertSame('user_id|i:2;', $database->selectString('SELECT data FROM session'));
    }

    /** PHP does not call close() when read() throws out of session_start(). */
    public function testAFailedReadReleasesTheLockItTook(): void
    {
        [$pdo, $handler] = $this->recordingHandler();
        $pdo->failSessionReads = true;

        $this->readExpectingFailure($handler, '43e880c2447ca10d3092d51d258c050c');
        self::assertSame(['pg_try_advisory_lock', 'pg_advisory_unlock'], $pdo->advisoryCalls);

        self::assertFalse($handler->write('43e880c2447ca10d3092d51d258c050c', ''));
        self::assertSame(['pg_try_advisory_lock', 'pg_advisory_unlock'], $pdo->advisoryCalls);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonTextDataProvider(): array
    {
        return ['null' => ['NULL'], 'integer' => ['42']];
    }

    #[DataProvider('nonTextDataProvider')]
    public function testADataColumnThatIsNotTextFailsTheReadAndReleasesTheLock(string $storedValue): void
    {
        [$pdo, $handler] = $this->recordingHandler();
        $pdo->exec('DROP TABLE session');
        $pdo->exec('CREATE TABLE session (session_id VARCHAR PRIMARY KEY, access INTEGER NOT NULL, expire INTEGER NOT NULL, data)');
        $pdo->exec(sprintf(
            "INSERT INTO session VALUES ('43e880c2447ca10d3092d51d258c050c', %d, %d, %s)",
            time(),
            time() + 1440,
            $storedValue,
        ));

        try {
            $handler->read('43e880c2447ca10d3092d51d258c050c');
            self::fail('read() was expected to fail');
        } catch (SessionException $exception) {
            self::assertStringContainsString('TEXT', $exception->getMessage());
            self::assertStringNotContainsString('43e880c2447ca10d3092d51d258c050c', $exception->getMessage());
        }

        self::assertSame(['pg_try_advisory_lock', 'pg_advisory_unlock'], $pdo->advisoryCalls);
        self::assertFalse($handler->write('43e880c2447ca10d3092d51d258c050c', ''));
    }

    /**
     * A handler on a connection that reports the pgsql driver and records the
     * lock statements it receives.
     *
     * @return array{0: AdvisoryLockRecordingPdo, 1: DatabaseSessionHandler, 2: Database}
     */
    private function recordingHandler(): array
    {
        $pdo = new AdvisoryLockRecordingPdo('sqlite::memory:');
        $pdo->exec(self::SCHEMA);
        $database = new Database($pdo);

        return [$pdo, new DatabaseSessionHandler($database, 'session'), $database];
    }

    private function insertLiveSession(Database $database): void
    {
        $database->execute(
            'INSERT INTO session (session_id, access, expire, data) VALUES (?, ?, ?, ?)',
            ['43e880c2447ca10d3092d51d258c050c', time(), time() + 1440, 'user_id|i:1;'],
        );
    }

    private function readExpectingFailure(DatabaseSessionHandler $handler, string $id): void
    {
        try {
            $handler->read($id);
        } catch (DatabaseException) {
            return;
        }

        self::fail('read() was expected to fail');
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

    /**
     * The stored expiry was stamped under the lifetime in force at the last
     * write, so a lowered timeout must not wait for it.
     */
    #[RunInSeparateProcess]
    public function testASessionIdleLongerThanTheCurrentLifetimeIsRefusedWhateverItsStoredExpiry(): void
    {
        ini_set('session.gc_maxlifetime', '2');
        $this->database->execute(
            'INSERT INTO session (session_id, access, expire, data) VALUES (?, ?, ?, ?)',
            ['43e880c2447ca10d3092d51d258c050c', time() - 4, time() - 4 + 3600, 'user_id|i:1;'],
        );

        self::assertFalse($this->handler->validateId('43e880c2447ca10d3092d51d258c050c'));
        self::assertSame('', $this->handler->read('43e880c2447ca10d3092d51d258c050c'));
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
        $schema = self::SCHEMA;
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';

        $script = <<<PHP
            require '{$autoload}';
            \$pdo = new PDO('sqlite::memory:');
            \$pdo->exec('{$schema}');
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
        [$pdo, $handler, $database] = $this->recordingHandler();
        $this->insertLiveSession($database);

        $handler->read('43e880c2447ca10d3092d51d258c050c');
        self::assertSame(['pg_try_advisory_lock'], $pdo->advisoryCalls, 'read() must take the lock before it reads');

        $handler->write('43e880c2447ca10d3092d51d258c050c', 'payload');
        self::assertSame(['pg_try_advisory_lock', 'pg_advisory_unlock'], $pdo->advisoryCalls);
    }

    public function testAContendedReadWaitsOnOneBlockingLockInsteadOfPolling(): void
    {
        [$pdo, $handler, $database] = $this->recordingHandler();
        $this->insertLiveSession($database);
        $pdo->lockContended = true;

        $handler->read('43e880c2447ca10d3092d51d258c050c');

        self::assertSame(
            ['pg_try_advisory_lock', 'BEGIN', 'lock_timeout', 'pg_advisory_lock', 'ROLLBACK'],
            $pdo->advisoryCalls,
            'the bound and the wait must share one transaction, rolled back so the bound cannot leak',
        );
    }

    public function testAContendedReadInsideACallerTransactionWaitsUnderARolledBackSavepoint(): void
    {
        [$pdo, $handler, $database] = $this->recordingHandler();
        $this->insertLiveSession($database);
        $pdo->lockContended = true;

        $pdo->beginTransaction();
        $handler->read('43e880c2447ca10d3092d51d258c050c');
        $pdo->commit();

        self::assertSame(
            [
                'BEGIN',
                'SAVEPOINT', 'pg_try_advisory_lock', 'RELEASE SAVEPOINT',
                'SAVEPOINT', 'lock_timeout', 'pg_advisory_lock', 'ROLLBACK TO SAVEPOINT', 'RELEASE SAVEPOINT',
            ],
            $pdo->advisoryCalls,
        );
    }

    /**
     * A new session holds no lock, so a wrapper that never forwards close()
     * cannot leak one per new session.
     */
    public function testAReadThatFindsNoRowReleasesTheLock(): void
    {
        [$pdo, $handler] = $this->recordingHandler();

        self::assertSame('', $handler->read('43e880c2447ca10d3092d51d258c050c'));
        self::assertSame(['pg_try_advisory_lock', 'pg_advisory_unlock'], $pdo->advisoryCalls);
    }

    public function testTheLockIsAlsoReleasedByCloseForARequestThatNeverWrites(): void
    {
        [$pdo, $handler, $database] = $this->recordingHandler();
        $this->insertLiveSession($database);

        $handler->read('43e880c2447ca10d3092d51d258c050c');
        $handler->close();

        self::assertSame(['pg_try_advisory_lock', 'pg_advisory_unlock'], $pdo->advisoryCalls);
    }

    /**
     * Inside the caller's transaction a failed statement aborts everything
     * after it, so each lock statement gets a savepoint of its own.
     */
    public function testALockStatementInsideACallerTransactionRunsUnderItsOwnSavepoint(): void
    {
        [$pdo, $handler, $database] = $this->recordingHandler();
        $this->insertLiveSession($database);

        $pdo->beginTransaction();
        $handler->read('43e880c2447ca10d3092d51d258c050c');
        $pdo->commit();

        self::assertSame(['BEGIN', 'SAVEPOINT', 'pg_try_advisory_lock', 'RELEASE SAVEPOINT'], $pdo->advisoryCalls);
    }

    public function testCloseInsideACallerTransactionReleasesTheLock(): void
    {
        [$pdo, $handler, $database] = $this->recordingHandler();
        $this->insertLiveSession($database);

        $handler->read('43e880c2447ca10d3092d51d258c050c');
        $pdo->beginTransaction();
        $handler->write('43e880c2447ca10d3092d51d258c050c', 'payload');
        $handler->close();
        $pdo->commit();

        self::assertSame(
            ['pg_try_advisory_lock', 'BEGIN', 'SAVEPOINT', 'pg_advisory_unlock', 'RELEASE SAVEPOINT'],
            $pdo->advisoryCalls,
        );
    }

    public function testAFailedUnlockInsideACallerTransactionIsRolledBackToItsSavepoint(): void
    {
        [$pdo, $handler, $database] = $this->recordingHandler();
        $this->insertLiveSession($database);

        $handler->read('43e880c2447ca10d3092d51d258c050c');
        $pdo->failUnlock = true;
        $pdo->beginTransaction();
        $handler->write('43e880c2447ca10d3092d51d258c050c', 'payload');
        $pdo->commit();

        self::assertSame(
            ['pg_try_advisory_lock', 'BEGIN', 'SAVEPOINT', 'pg_advisory_unlock', 'ROLLBACK TO SAVEPOINT', 'RELEASE SAVEPOINT'],
            $pdo->advisoryCalls,
        );
    }

    public function testAnUnlockThatFailedIsRetriedByClose(): void
    {
        [$pdo, $handler, $database] = $this->recordingHandler();
        $this->insertLiveSession($database);

        $handler->read('43e880c2447ca10d3092d51d258c050c');
        $pdo->failUnlock = true;
        $handler->write('43e880c2447ca10d3092d51d258c050c', 'payload');
        $pdo->failUnlock = false;
        $handler->close();
        $handler->close();

        self::assertSame(['pg_try_advisory_lock', 'pg_advisory_unlock', 'pg_advisory_unlock'], $pdo->advisoryCalls);
    }

    /** Signing out another session (sign out everywhere, say) leaves this request's session locked. */
    public function testDestroyingAnotherSessionKeepsTheLockOnThisOne(): void
    {
        [$pdo, $handler, $database] = $this->recordingHandler();
        $this->insertLiveSession($database);
        $handler->read('43e880c2447ca10d3092d51d258c050c');

        self::assertTrue($handler->destroy('aabbccddeeff00112233445566778899'));
        self::assertSame(['pg_try_advisory_lock'], $pdo->advisoryCalls);

        $handler->destroy('43e880c2447ca10d3092d51d258c050c');
        self::assertSame(['pg_try_advisory_lock', 'pg_advisory_unlock'], $pdo->advisoryCalls);
    }

    /**
     * pg_advisory_unlock() answers false when this connection does not hold the
     * lock, which is what transaction pooling produces: the lock stays held on
     * another server connection.
     */
    public function testAnUnlockThatFindsNoLockWarnsAndStopsLocking(): void
    {
        [$pdo, $handler, $database] = $this->recordingHandler();
        $this->insertLiveSession($database);
        $handler->read('43e880c2447ca10d3092d51d258c050c');
        $pdo->unlockFindsNoLock = true;

        $warnings = $this->collectWarnings(static function () use ($handler): void {
            $handler->write('43e880c2447ca10d3092d51d258c050c', 'payload');
            $handler->read('43e880c2447ca10d3092d51d258c050c');
            $handler->close();
        });

        self::assertCount(1, $warnings);
        self::assertSame(E_USER_WARNING, $warnings[0][0]);
        self::assertStringContainsString('lockSessions: false', $warnings[0][1]);
        self::assertSame(['pg_try_advisory_lock', 'pg_advisory_unlock'], $pdo->advisoryCalls);
    }

    public function testReadingAnotherSessionTakesNoLockOnceItsUnlockTurnedLockingOff(): void
    {
        [$pdo, $handler, $database] = $this->recordingHandler();
        $this->insertLiveSession($database);
        $handler->read('43e880c2447ca10d3092d51d258c050c');
        $pdo->unlockFindsNoLock = true;

        $warnings = $this->collectWarnings(static function () use ($handler): void {
            $handler->read('aabbccddeeff00112233445566778899');
        });

        self::assertCount(1, $warnings);
        self::assertSame(['pg_try_advisory_lock', 'pg_advisory_unlock'], $pdo->advisoryCalls);
    }

    /**
     * @param callable(): void $operations
     * @return list<array{int, string}>
     */
    private function collectWarnings(callable $operations): array
    {
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = [$severity, $message];

            return true;
        });

        try {
            $operations();
        } finally {
            restore_error_handler();
        }

        return $warnings;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rowWriterProvider(): array
    {
        return ['write' => ['write'], 'updateTimestamp' => ['updateTimestamp'], 'destroy' => ['destroy']];
    }

    #[DataProvider('rowWriterProvider')]
    public function testAWriteWhoseStatementFailsStillReleasesTheLock(string $method): void
    {
        [$pdo, $handler, $database] = $this->recordingHandler();
        $this->insertLiveSession($database);
        $handler->read('43e880c2447ca10d3092d51d258c050c');
        $pdo->exec('DROP TABLE session');

        try {
            $handler->{$method}('43e880c2447ca10d3092d51d258c050c', 'payload');
            self::fail("{$method}() was expected to fail");
        } catch (DatabaseException) {
        }

        self::assertSame(['pg_try_advisory_lock', 'pg_advisory_unlock'], $pdo->advisoryCalls);
    }

    public function testLockingCanBeTurnedOffForADeploymentThatCannotAffordIt(): void
    {
        [$pdo, , $database] = $this->recordingHandler();
        $handler = new DatabaseSessionHandler($database, 'session', lockSessions: false);

        $handler->read('43e880c2447ca10d3092d51d258c050c');
        $handler->write('43e880c2447ca10d3092d51d258c050c', 'payload');

        self::assertSame([], $pdo->advisoryCalls);
    }

    public function testASqliteConnectionTakesNoLockAndIsUnaffected(): void
    {
        [$pdo, $handler] = $this->recordingHandler();
        $pdo->reportedDriver = 'sqlite';

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
        $pdo->exec(self::SCHEMA);

        $handler = new DatabaseSessionHandler(new Database($pdo), 'session');
        $handler->read('43e880c2447ca10d3092d51d258c050c');

        $pdo->prepared = 0;
        $handler->write('43e880c2447ca10d3092d51d258c050c', 'first');
        self::assertSame(1, $pdo->prepared, 'insert path must be one statement');

        $pdo->prepared = 0;
        $handler->write('43e880c2447ca10d3092d51d258c050c', 'second');
        self::assertSame(1, $pdo->prepared, 'update path must be one statement');

        self::assertSame('second', $handler->read('43e880c2447ca10d3092d51d258c050c'));
    }

    private function storedPayload(string $id): string
    {
        $stored = $this->database->selectString('SELECT data FROM session WHERE session_id = ?', [$id]);
        self::assertIsString($stored);

        return $stored;
    }
}

/** Serialized with NUL bytes around its private and protected property names. */
final class SessionPayloadObject
{
    public function __construct(
        private readonly string $owner,
        protected int $quantity,
    ) {
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

    public bool $failSessionReads = false;

    public bool $lockContended = false;

    public bool $failUnlock = false;

    public bool $unlockFindsNoLock = false;

    /** @var list<string> */
    public array $advisoryCalls = [];

    public function beginTransaction(): bool
    {
        $this->advisoryCalls[] = 'BEGIN';

        return parent::beginTransaction();
    }

    public function rollBack(): bool
    {
        $this->advisoryCalls[] = 'ROLLBACK';

        return parent::rollBack();
    }

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
        if ($this->failSessionReads && str_starts_with(ltrim($query), 'SELECT data FROM')) {
            throw new \PDOException('server closed the connection unexpectedly');
        }

        if (str_contains($query, 'pg_try_advisory_lock')) {
            $this->advisoryCalls[] = 'pg_try_advisory_lock';

            return parent::prepare(
                sprintf('SELECT %d WHERE ? IS NOT NULL AND ? IS NOT NULL', $this->lockContended ? 0 : 1),
                $options,
            );
        }

        if (str_contains($query, 'pg_advisory_lock')) {
            $this->advisoryCalls[] = 'pg_advisory_lock';

            return parent::prepare('SELECT 1 WHERE ? IS NOT NULL AND ? IS NOT NULL', $options);
        }

        if (str_contains($query, "set_config('lock_timeout'")) {
            $this->advisoryCalls[] = 'lock_timeout';

            return parent::prepare('SELECT ?', $options);
        }

        foreach (['ROLLBACK TO SAVEPOINT', 'RELEASE SAVEPOINT', 'SAVEPOINT'] as $savepointStatement) {
            if (str_starts_with($query, $savepointStatement)) {
                $this->advisoryCalls[] = $savepointStatement;

                return parent::prepare($query, $options);
            }
        }

        if (str_contains($query, 'pg_advisory_unlock')) {
            $this->advisoryCalls[] = 'pg_advisory_unlock';

            if ($this->failUnlock) {
                throw new \PDOException('permission denied for function pg_advisory_unlock');
            }

            return parent::prepare(
                sprintf('SELECT %d WHERE ? IS NOT NULL AND ? IS NOT NULL', $this->unlockFindsNoLock ? 0 : 1),
                $options,
            );
        }

        return parent::prepare($query, $options);
    }
}
