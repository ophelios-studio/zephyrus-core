<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Zephyrus\Data\Database;
use Zephyrus\Session\DatabaseSessionHandler;

/**
 * DatabaseSessionHandler driven by PHP's session module with session.lazy_write on, over SQLite and, when
 * ZEPHYRUS_TEST_PGSQL_DSN is set, PostgreSQL.
 *
 * Each test runs in its own process, because a PHP session lasts as long as the process.
 */
final class DatabaseSessionHandlerRealSessionTest extends TestCase
{
    private Database $database;
    private string $table;
    private string $errorLog;
    private bool $capturingErrors = false;

    /** @var list<int> The severity of each PHP error raised since the handler was built. */
    private array $errors = [];

    /**
     * @return iterable<string, array{string}>
     */
    public static function drivers(): iterable
    {
        yield 'sqlite' => ['sqlite'];

        $dsn = getenv('ZEPHYRUS_TEST_PGSQL_DSN');
        if (is_string($dsn) && $dsn !== '') {
            yield 'pgsql' => ['pgsql'];
        }
    }

    protected function tearDown(): void
    {
        if ($this->capturingErrors) {
            restore_error_handler();
        }

        if (isset($this->database)) {
            $this->database->pdo()->exec("DROP TABLE IF EXISTS {$this->table}");
        }

        if (isset($this->errorLog)) {
            unlink($this->errorLog);
        }
    }

    #[DataProvider('drivers')]
    #[RunInSeparateProcess]
    public function testANewVisitorWhoseSessionStaysEmptyIsNotStoredAndRaisesNothing(string $driver): void
    {
        $this->connect($driver);
        $this->startSession();

        $closed = $this->closeSession();

        self::assertTrue($closed);
        self::assertSame([], $this->errors);
        self::assertStringEqualsFile($this->errorLog, '');
        self::assertSame(0, $this->database->selectInt("SELECT count(*) FROM {$this->table}"));
    }

    #[DataProvider('drivers')]
    #[RunInSeparateProcess]
    public function testASessionEmptiedDuringTheRequestIsStoredEmpty(string $driver): void
    {
        $this->connect($driver);
        $id = $this->seedSession('user_id|i:1;', time());
        $this->startSession($id);
        $_SESSION = [];

        $closed = $this->closeSession();

        self::assertTrue($closed);
        self::assertSame([], $this->errors);
        self::assertStringEqualsFile($this->errorLog, '');
        self::assertSame(
            '',
            $this->database->selectString("SELECT data FROM {$this->table} WHERE session_id = ?", [$id]),
        );
    }

    #[DataProvider('drivers')]
    #[RunInSeparateProcess]
    public function testAStoredEmptySessionLeftEmptyIsKeptAlive(string $driver): void
    {
        $this->connect($driver);
        $lastAccess = time() - 600;
        $id = $this->seedSession('', $lastAccess);
        $this->startSession($id);

        $closed = $this->closeSession();

        self::assertTrue($closed);
        self::assertSame([], $this->errors);
        self::assertStringEqualsFile($this->errorLog, '');
        self::assertGreaterThan($lastAccess, $this->database->selectInt(
            "SELECT access FROM {$this->table} WHERE session_id = ? AND data = ''",
            [$id],
        ));
    }

    #[DataProvider('drivers')]
    #[RunInSeparateProcess]
    public function testAStoredEmptySessionDeletedDuringTheRequestIsNotRecreated(string $driver): void
    {
        $this->connect($driver);
        $id = $this->seedSession('', time());
        $this->startSession($id);
        $this->database->execute("DELETE FROM {$this->table} WHERE session_id = ?", [$id]);

        $this->closeSession();

        self::assertSame([E_WARNING], $this->errors, 'PHP reports the refused write');
        self::assertSame(0, $this->database->selectInt("SELECT count(*) FROM {$this->table}"));
    }

    private function connect(string $driver): void
    {
        $dsn = $driver === 'pgsql' ? (string) getenv('ZEPHYRUS_TEST_PGSQL_DSN') : 'sqlite::memory:';
        $this->database = new Database(new PDO($dsn));
        $this->table = 'zephyrus_session_test_' . bin2hex(random_bytes(6));
        $this->database->pdo()->exec(
            "CREATE TABLE {$this->table} (
                session_id VARCHAR PRIMARY KEY,
                access INTEGER NOT NULL,
                expire INTEGER NOT NULL,
                data TEXT NOT NULL DEFAULT ''
            )",
        );
    }

    private function seedSession(string $payload, int $access): string
    {
        $id = bin2hex(random_bytes(16));
        $this->database->execute(
            "INSERT INTO {$this->table} (session_id, access, expire, data) VALUES (?, ?, ?, ?)",
            [$id, $access, $access + 1440, $payload],
        );

        return $id;
    }

    private function startSession(?string $id = null): void
    {
        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'zephyrus-session-log');
        ini_set('error_log', $this->errorLog);
        ini_set('session.use_cookies', '0');
        ini_set('session.cache_limiter', '');
        ini_set('session.lazy_write', '1');
        ini_set('session.gc_maxlifetime', '1440');
        ini_set('session.gc_probability', '0');

        $this->capturingErrors = true;
        set_error_handler(function (int $severity): bool {
            $this->errors[] = $severity;

            return true;
        });

        session_set_save_handler(new DatabaseSessionHandler($this->database, $this->table), true);
        if ($id !== null) {
            session_id($id);
        }
        session_start();
    }

    private function closeSession(): bool
    {
        $closed = session_write_close();
        restore_error_handler();
        $this->capturingErrors = false;

        return $closed;
    }
}
