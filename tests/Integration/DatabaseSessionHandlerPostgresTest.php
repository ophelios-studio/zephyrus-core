<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Zephyrus\Data\Database;
use Zephyrus\Data\DatabaseException;
use Zephyrus\Session\DatabaseSessionHandler;
use Zephyrus\Session\SessionException;

/**
 * DatabaseSessionHandler against a real PostgreSQL server, for the behaviour
 * SQLite cannot model: advisory locks, lock_timeout, aborted caller
 * transactions, and the row counts PostgreSQL reports for the session writes.
 *
 * Runs only when ZEPHYRUS_TEST_PGSQL_DSN holds a PDO DSN carrying its own
 * credentials. Each test works in a throwaway schema it drops afterwards.
 */
final class DatabaseSessionHandlerPostgresTest extends TestCase
{
    private string $dsn;
    private string $schema;
    private Database $database;

    protected function setUp(): void
    {
        $dsn = getenv('ZEPHYRUS_TEST_PGSQL_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped(
                'Set ZEPHYRUS_TEST_PGSQL_DSN (e.g. pgsql:host=127.0.0.1;port=5432;dbname=postgres;user=postgres;password=postgres) to run the PostgreSQL session handler tests.',
            );
        }

        $this->dsn = $dsn;
        $this->schema = 'zephyrus_session_test_' . bin2hex(random_bytes(6));
        $this->database = $this->connect();
        $this->database->pdo()->exec("CREATE SCHEMA {$this->schema}");
        $this->database->pdo()->exec(
            "CREATE TABLE {$this->schema}.session (
                session_id VARCHAR PRIMARY KEY,
                access INTEGER NOT NULL,
                expire INTEGER NOT NULL,
                data TEXT NOT NULL DEFAULT ''
            )",
        );
        $this->database->pdo()->exec("CREATE TABLE {$this->schema}.caller_row (label TEXT NOT NULL)");
    }

    protected function tearDown(): void
    {
        if (!isset($this->database)) {
            return;
        }

        if ($this->database->inTransaction()) {
            $this->database->pdo()->rollBack();
        }

        $this->database->pdo()->exec("DROP SCHEMA IF EXISTS {$this->schema} CASCADE");
        unset($this->database);
    }

    // ── Caller transactions ───────────────────────────────────────────────────

    public function testReadInsideACallerTransactionTakesTheLock(): void
    {
        $id = $this->seedSession('user_id|i:1;');
        $handler = $this->handler();

        $this->database->pdo()->beginTransaction();
        self::assertSame('user_id|i:1;', $handler->read($id));
        self::assertSame(1, $this->advisoryLocksHeld());
        $this->database->pdo()->commit();

        $handler->close();
        self::assertSame(0, $this->advisoryLocksHeld());
    }

    public function testAFailingLockInsideACallerTransactionLeavesItUsable(): void
    {
        $id = $this->seedSession('user_id|i:1;');
        $handler = $this->handler();
        $this->failFunction('pg_try_advisory_lock');

        $this->database->pdo()->beginTransaction();
        $this->insertCallerRow('before');
        self::assertSame('user_id|i:1;', $handler->read($id));
        $this->insertCallerRow('after');
        $this->database->pdo()->commit();

        self::assertSame(2, $this->database->selectInt("SELECT count(*) FROM {$this->schema}.caller_row"));
    }

    public function testAContendedReadInsideACallerTransactionThatGivesUpLeavesItUsable(): void
    {
        $id = $this->seedSession('user_id|i:1;');
        $holder = $this->handler($this->connect());
        $holder->read($id);
        $this->database->pdo()->exec("SET lock_timeout = '42s'");
        $this->database->pdo()->exec("SET statement_timeout = '300ms'");

        $this->database->pdo()->beginTransaction();
        $this->insertCallerRow('before');
        $payload = $this->handler()->read($id);
        $this->insertCallerRow('after');
        $this->database->pdo()->commit();
        $this->database->pdo()->exec('SET statement_timeout = 0');

        self::assertSame('user_id|i:1;', $payload);
        self::assertSame(2, $this->database->selectInt("SELECT count(*) FROM {$this->schema}.caller_row"));
        self::assertSame(0, $this->advisoryLocksHeld());
        self::assertSame('42s', $this->database->selectString("SELECT current_setting('lock_timeout')"));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function sessionOperations(): array
    {
        return [
            'write'           => ['write'],
            'updateTimestamp' => ['updateTimestamp'],
            'destroy'         => ['destroy'],
        ];
    }

    #[DataProvider('sessionOperations')]
    public function testAFailingUnlockCannotAbortTheCallersTransaction(string $operation): void
    {
        $id = $this->seedSession('user_id|i:1;');
        $handler = $this->handler();
        $handler->read($id);
        $this->failFunction('pg_advisory_unlock');

        $this->database->pdo()->beginTransaction();
        $this->insertCallerRow('before');
        $this->runOperation($handler, $operation, $id);
        $handler->close();
        $this->insertCallerRow('after');
        $this->database->pdo()->commit();

        self::assertSame(2, $this->database->selectInt("SELECT count(*) FROM {$this->schema}.caller_row"));
    }

    public function testClosingInsideACallerTransactionLeavesNoLockHeld(): void
    {
        $id = $this->seedSession('user_id|i:1;');
        $handler = $this->handler();
        $handler->read($id);

        $this->database->pdo()->beginTransaction();
        $handler->write($id, 'user_id|i:2;');
        $handler->close();
        self::assertSame(0, $this->advisoryLocksHeld());
        $this->database->pdo()->commit();
    }

    #[RunInSeparateProcess]
    public function testSessionWriteCloseInsideACallerTransactionLeavesNoLockHeld(): void
    {
        $id = $this->seedSession('user_id|i:1;');
        ini_set('session.use_cookies', '0');
        ini_set('session.cache_limiter', '');
        session_set_save_handler($this->handler(), true);
        session_id($id);
        session_start();
        $_SESSION['locale'] = 'fr';

        $this->database->transaction(static function (): void {
            session_write_close();
        });

        self::assertSame(0, $this->advisoryLocksHeld());
        self::assertSame('user_id|i:1;locale|s:2:"fr";', $this->storedPayload($id));
    }

    public function testAnotherRequestDoesNotWaitForASessionClosedInsideATransaction(): void
    {
        $id = $this->seedSession('user_id|i:1;');
        $handler = $this->handler();
        $handler->read($id);
        $this->database->pdo()->beginTransaction();
        $handler->write($id, 'user_id|i:2;');
        $handler->close();
        $this->database->pdo()->commit();

        $started = microtime(true);
        $payload = $this->handler($this->connect())->read($id);

        self::assertSame('user_id|i:2;', $payload);
        self::assertLessThan(1.0, microtime(true) - $started);
    }

    // ── A failed read ─────────────────────────────────────────────────────────

    public function testAFailedReadLeavesNoLockHeld(): void
    {
        $id = $this->seedSession('user_id|i:1;');
        $this->database->pdo()->exec("ALTER TABLE {$this->schema}.session RENAME COLUMN data TO payload");
        $handler = $this->handler();

        try {
            $handler->read($id);
            self::fail('read() was expected to fail');
        } catch (DatabaseException) {
        }

        self::assertSame(0, $this->advisoryLocksHeld());
        self::assertFalse($handler->write($id, ''));
    }

    /** PHP does not call close() after a read() that throws, so read() is the only place to release. */
    public function testAReadThatFailsInsideACallerTransactionLeavesNoLockHeld(): void
    {
        $id = $this->seedSession('user_id|i:1;');
        $this->database->pdo()->exec("ALTER TABLE {$this->schema}.session RENAME COLUMN data TO payload");
        $handler = $this->handler();
        $backend = $this->database->selectInt('SELECT pg_backend_pid()');

        $this->database->pdo()->beginTransaction();
        try {
            $handler->read($id);
            self::fail('read() was expected to fail');
        } catch (DatabaseException) {
        }

        self::assertSame(0, $this->connect()->selectInt(
            "SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = ? AND granted",
            [$backend],
        ));
    }

    public function testAReadThatFindsNoRowLeavesNoLockHeld(): void
    {
        $handler = $this->handler();

        self::assertSame('', $handler->read(bin2hex(random_bytes(16))));
        self::assertSame(0, $this->advisoryLocksHeld());
    }

    // ── What the write methods report ─────────────────────────────────────────

    public function testWritesToASessionDeletedMidRequestReportFailureAndCreateNothing(): void
    {
        $id = $this->seedSession('user_id|i:1;');
        $handler = $this->handler();
        $handler->read($id);
        $this->connect()->execute("DELETE FROM {$this->schema}.session WHERE session_id = ?", [$id]);

        self::assertFalse($handler->updateTimestamp($id, 'user_id|i:1;'));
        self::assertFalse($handler->write($id, 'user_id|i:2;'));
        self::assertNull($this->storedPayload($id));
    }

    public function testWritesToALiveSessionReportSuccess(): void
    {
        $id = $this->seedSession('user_id|i:1;');
        $handler = $this->handler();
        $handler->read($id);

        self::assertTrue($handler->updateTimestamp($id, 'user_id|i:1;'));
        self::assertTrue($handler->write($id, 'user_id|i:2;'));
        self::assertSame('user_id|i:2;', $this->storedPayload($id));
    }

    public function testCreatingASessionReportsSuccess(): void
    {
        $id = bin2hex(random_bytes(16));
        $handler = $this->handler();

        self::assertSame('', $handler->read($id));
        self::assertTrue($handler->write($id, ''));
        self::assertSame('', $this->storedPayload($id));
    }

    // ── Payload storage ───────────────────────────────────────────────────────

    public function testASessionHoldingAnObjectWithPrivateAndProtectedPropertiesRoundTrips(): void
    {
        $cart = new PostgresSessionPayloadObject('Zoë', 3);
        $payload = 'user_id|i:1;cart|' . serialize($cart);
        $id = bin2hex(random_bytes(16));

        $writer = $this->handler();
        self::assertSame('', $writer->read($id));
        self::assertTrue($writer->write($id, $payload));

        $reader = $this->handler();
        $read = $reader->read($id);
        self::assertSame($payload, $read);
        self::assertTrue($reader->write($id, $read));
        $reader->close();

        $roundTripped = $this->handler()->read($id);
        self::assertSame($payload, $roundTripped);
        self::assertEquals($cart, unserialize(substr($roundTripped, strlen('user_id|i:1;cart|'))));
    }

    public function testAPayloadThatIsNotUtf8RoundTrips(): void
    {
        $payload = 'key|s:4:"' . "\xff\xfe\x80\x01" . '";';
        $id = bin2hex(random_bytes(16));
        $writer = $this->handler();

        self::assertSame('', $writer->read($id));
        self::assertTrue($writer->write($id, $payload));

        self::assertSame($payload, $this->handler()->read($id));
    }

    public function testATextPayloadIsStoredVerbatimSoItCanStillBeSearched(): void
    {
        $id = bin2hex(random_bytes(16));
        $writer = $this->handler();

        $writer->read($id);
        $writer->write($id, 'user_id|s:16:"user@example.com";');

        self::assertSame(1, $this->database->count(
            "SELECT COUNT(*) FROM {$this->schema}.session WHERE data LIKE ?",
            ['%user@example.com%'],
        ));
    }

    public function testABinaryDataColumnFailsTheReadAndLeavesNoLockHeld(): void
    {
        $id = $this->seedSession('user_id|i:1;');
        $this->database->pdo()->exec(
            "ALTER TABLE {$this->schema}.session ALTER COLUMN data DROP DEFAULT,
             ALTER COLUMN data TYPE BYTEA USING convert_to(data, 'UTF8')",
        );

        $thrown = null;
        try {
            $this->handler()->read($id);
        } catch (SessionException $exception) {
            $thrown = $exception;
        }

        self::assertInstanceOf(SessionException::class, $thrown);
        self::assertStringContainsString('TEXT', $thrown->getMessage());
        self::assertStringNotContainsString($id, $thrown->getMessage());
        self::assertSame(0, $this->advisoryLocksHeld());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rowsWrittenBeforePayloadsWereEncoded(): iterable
    {
        yield 'php serializer' => ['user_id|i:1;'];
        yield 'empty' => [''];
        yield 'key named like the prefix' => ['base64:|i:1;'];
    }

    #[DataProvider('rowsWrittenBeforePayloadsWereEncoded')]
    public function testARowWrittenBeforePayloadsWereEncodedReadsBackAsStored(string $stored): void
    {
        $id = $this->seedSession($stored);

        self::assertSame($stored, $this->handler()->read($id));
    }

    // ── Waiting for a contended session ───────────────────────────────────────

    public function testAContendedReadWaitsForTheHolderOnOneBlockingStatement(): void
    {
        $id = $this->seedSession('user_id|i:1;');
        $pdo = new StatementCountingPdo($this->dsn);
        $database = new Database($pdo);
        $database->pdo()->exec("SET lock_timeout = '42s'");
        $holder = $this->holdSessionInAnotherProcess($id, 1_000_000);

        $pdo->statements = 0;
        $started = microtime(true);
        $payload = $this->handler($database)->read($id);
        $waited = microtime(true) - $started;
        $statements = $pdo->statements;
        proc_close($holder);

        self::assertSame('user_id|i:1;', $payload);
        self::assertGreaterThan(0.5, $waited, 'read() must wait for the holder');
        self::assertLessThan(4.0, $waited, 'read() must take the lock as soon as the holder releases it');
        self::assertLessThanOrEqual(6, $statements, 'a contended read must wait on the server, not poll it');
        self::assertSame(1, $database->selectInt(
            "SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid() AND granted",
        ));
        self::assertSame('42s', $database->selectString("SELECT current_setting('lock_timeout')"));
    }

    public function testAContendedReadLeavesTheCallersLockTimeoutUntouched(): void
    {
        $id = $this->seedSession('user_id|i:1;');
        $this->database->pdo()->exec("SET lock_timeout = '7s'");
        $holder = $this->holdSessionInAnotherProcess($id, 500_000);

        $this->database->pdo()->beginTransaction();
        $this->database->pdo()->exec("SET LOCAL lock_timeout = '42s'");
        $this->handler()->read($id);
        $insideTransaction = $this->database->selectString("SELECT current_setting('lock_timeout')");
        $this->database->pdo()->commit();
        proc_close($holder);

        self::assertSame(1, $this->advisoryLocksHeld());
        self::assertSame('42s', $insideTransaction);
        self::assertSame('7s', $this->database->selectString("SELECT current_setting('lock_timeout')"));
    }

    public function testAReadThatTimesOutCarriesOnUnlockedAndRestoresLockTimeout(): void
    {
        $id = $this->seedSession('user_id|i:1;');
        $holder = $this->connect();
        $this->handler($holder)->read($id);
        $this->database->pdo()->exec("SET lock_timeout = '42s'");

        $started = microtime(true);
        $payload = $this->handler()->read($id);
        $waited = microtime(true) - $started;

        self::assertSame('user_id|i:1;', $payload);
        self::assertGreaterThan(4.5, $waited);
        self::assertLessThan(8.0, $waited);
        self::assertSame(0, $this->advisoryLocksHeld());
        self::assertSame('42s', $this->database->selectString("SELECT current_setting('lock_timeout')"));
    }

    // ── An unlock on the wrong connection ─────────────────────────────────────

    /**
     * Dropping the lock behind the handler's back leaves its unlock on a
     * connection without the lock, as a transaction-pooling proxy does.
     */
    public function testAnUnlockOnAConnectionWithoutTheLockWarnsAndStopsLocking(): void
    {
        $id = $this->seedSession('user_id|i:1;');
        $handler = $this->handler();
        $handler->read($id);
        $this->database->pdo()->exec('SELECT pg_advisory_unlock_all()');

        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });
        try {
            self::assertTrue($handler->write($id, 'user_id|i:2;'));
            $handler->read($id);
            $locksAfterNextRead = $this->advisoryLocksHeld();
            $handler->close();
        } finally {
            restore_error_handler();
        }

        self::assertCount(1, $warnings);
        self::assertStringContainsString('lockSessions: false', $warnings[0]);
        self::assertSame(0, $locksAfterNextRead);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function connect(): Database
    {
        return new Database(new PDO($this->dsn));
    }

    private function handler(?Database $database = null): DatabaseSessionHandler
    {
        return new DatabaseSessionHandler($database ?? $this->database, "{$this->schema}.session");
    }

    private function seedSession(string $payload): string
    {
        $id = bin2hex(random_bytes(16));
        $this->database->execute(
            "INSERT INTO {$this->schema}.session (session_id, access, expire, data) VALUES (?, ?, ?, ?)",
            [$id, time(), time() + 1440, $payload],
        );

        return $id;
    }

    private function storedPayload(string $id): ?string
    {
        return $this->database->selectString(
            "SELECT data FROM {$this->schema}.session WHERE session_id = ?",
            [$id],
        );
    }

    private function insertCallerRow(string $label): void
    {
        $this->database->execute("INSERT INTO {$this->schema}.caller_row (label) VALUES (?)", [$label]);
    }

    private function runOperation(DatabaseSessionHandler $handler, string $operation, string $id): bool
    {
        return match ($operation) {
            'write'           => $handler->write($id, 'user_id|i:2;'),
            'updateTimestamp' => $handler->updateTimestamp($id, 'user_id|i:1;'),
            'destroy'         => $handler->destroy($id),
        };
    }

    /**
     * Takes the session lock from a separate process and releases it after
     * the given delay. Returns once the lock is held.
     *
     * @return resource
     */
    private function holdSessionInAnotherProcess(string $id, int $holdMicroseconds)
    {
        $script = sprintf(
            'require %s;'
            . '$handler = new Zephyrus\\Session\\DatabaseSessionHandler(new Zephyrus\\Data\\Database(new PDO(%s)), %s);'
            . '$handler->read(%s);'
            . 'echo "locked\\n";'
            . 'usleep(%d);'
            . '$handler->close();',
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export($this->dsn, true),
            var_export("{$this->schema}.session", true),
            var_export($id, true),
            $holdMicroseconds,
        );

        $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $line = fgets($pipes[1]);
        if ($line !== "locked\n") {
            self::fail('the lock holder did not start: ' . stream_get_contents($pipes[2]));
        }

        return $process;
    }

    private function advisoryLocksHeld(): int
    {
        return $this->database->selectInt(
            "SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid() AND granted",
        );
    }

    /**
     * Shadows the pg_catalog function of that name taking (int, int), on this
     * connection only, with one raising what a revoked EXECUTE privilege raises.
     */
    private function failFunction(string $name): void
    {
        $this->database->pdo()->exec(
            "CREATE FUNCTION {$this->schema}.{$name}(integer, integer) RETURNS boolean
             LANGUAGE plpgsql AS \$body\$
             BEGIN
                 RAISE EXCEPTION 'permission denied for function {$name}' USING ERRCODE = '42501';
             END
             \$body\$",
        );
        $this->database->pdo()->exec("SET search_path = {$this->schema}, pg_catalog");
    }
}

/** Counts the statements sent to the server. */
final class StatementCountingPdo extends PDO
{
    public int $statements = 0;

    /**
     * @param array<int, mixed> $options
     */
    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $this->statements++;

        return parent::prepare($query, $options);
    }
}

/** Serialized with NUL bytes around its private and protected property names. */
final class PostgresSessionPayloadObject
{
    public function __construct(
        private readonly string $owner,
        protected int $quantity,
    ) {
    }
}
