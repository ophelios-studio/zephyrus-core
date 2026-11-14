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

final class DatabasePersistentConnectionTest extends TestCase
{
    public function testAPersistentConfigAsksTheFactoryForTheFrameworkPoolWithExceptions(): void
    {
        $options = [];

        Database::fromConfig(self::config(persistent: true), static function (
            string $dsn,
            string $username,
            string $password,
            array $given,
        ) use (&$options): PDO {
            $options = $given;

            return new ReusedSessionPdo();
        });

        self::assertSame('zephyrus', $options[PDO::ATTR_PERSISTENT]);
        self::assertSame(PDO::ERRMODE_EXCEPTION, $options[PDO::ATTR_ERRMODE]);
    }

    public function testAPersistentHandleStillHeldInTheProcessIsNeverHandedOutAgain(): void
    {
        $requested = [];
        $factory = static function (string $dsn, string $username, string $password, array $options) use (&$requested): PDO {
            $requested[] = $options[PDO::ATTR_PERSISTENT];

            return $options[PDO::ATTR_PERSISTENT] === false ? new PDO('sqlite::memory:') : new ReusedSessionPdo();
        };

        $held = Database::fromConfig(self::config(persistent: true), $factory);
        Database::fromConfig(self::config(persistent: true), $factory);
        unset($held);
        gc_collect_cycles();
        Database::fromConfig(self::config(persistent: true), $factory);

        self::assertSame(['zephyrus', false, 'zephyrus'], $requested);
    }

    public function testAReusedSessionIsRolledBackThenDiscardedBeforeAnyOtherStatement(): void
    {
        $pdo = new ReusedSessionPdo(openTransaction: true);

        Database::fromConfig(self::config(persistent: true), static fn (): PDO => $pdo);

        self::assertSame(['ROLLBACK', 'DISCARD ALL', "SET client_encoding TO 'utf8'"], $pdo->statements);
    }

    public function testAnIdleReusedSessionIsDiscardedWithoutARollback(): void
    {
        $pdo = new ReusedSessionPdo();

        Database::fromConfig(self::config(persistent: true), static fn (): PDO => $pdo);

        self::assertSame(['DISCARD ALL', "SET client_encoding TO 'utf8'"], $pdo->statements);
    }

    public function testAttributesSharedByTheReusedHandleArePinnedBack(): void
    {
        $pdo = new ReusedSessionPdo();
        $pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_UPPER);
        $pdo->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_TO_STRING);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_NUM);

        $row = Database::fromConfig(self::config(persistent: true), static fn (): PDO => $pdo)
            ->selectOne('SELECT 1 AS probe, NULL AS missing');

        self::assertSame(['probe' => 1, 'missing' => null], (array) $row);
    }

    public function testASilentErrorModeLeftOnTheHandleCannotHideAFailedReset(): void
    {
        $factory = static function (): PDO {
            $pdo = new ReusedSessionPdo(failingReset: true);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

            return $pdo;
        };

        try {
            Database::fromConfig(self::config(persistent: true), $factory);

            self::fail('A failed reset was ignored.');
        } catch (DatabaseException $e) {
            self::assertSame('57P01', $e->sqlState());
        }
    }

    public function testAFailedResetEndsTheBackendAndRetriesOnceOnANewHandle(): void
    {
        $handles = [new ReusedSessionPdo(failingReset: true), new ReusedSessionPdo()];
        $calls = 0;

        $db = Database::fromConfig(self::config(persistent: true), static function () use (&$handles, &$calls): PDO {
            return $handles[$calls++];
        });

        self::assertSame(2, $calls);
        self::assertSame($handles[1], $db->pdo());
        self::assertSame(['DISCARD ALL', 'SELECT pg_terminate_backend(pg_backend_pid())'], $handles[0]->statements);
        self::assertSame(['DISCARD ALL', "SET client_encoding TO 'utf8'"], $handles[1]->statements);
    }

    public function testTheHandleThatFailedItsResetIsReleasedBeforeTheRetry(): void
    {
        $first = null;
        $releasedBeforeRetry = null;

        Database::fromConfig(self::config(persistent: true), static function () use (&$first, &$releasedBeforeRetry): PDO {
            if ($first === null) {
                $handle = new ReusedSessionPdo(failingReset: true);
                $first = \WeakReference::create($handle);

                return $handle;
            }

            $releasedBeforeRetry = $first->get() === null;

            return new ReusedSessionPdo();
        });

        self::assertTrue($releasedBeforeRetry);
    }

    public function testASecondFailedResetRefusesBeforeAnyQueryIsSent(): void
    {
        $handles = [new ReusedSessionPdo(failingReset: true), new ReusedSessionPdo(failingReset: true)];
        $calls = 0;

        try {
            Database::fromConfig(self::config(persistent: true), static function () use (&$handles, &$calls): PDO {
                return $handles[$calls++];
            });

            self::fail('A session that could not be reset was handed out.');
        } catch (DatabaseException $e) {
            self::assertSame(
                'Persistent connection reset failed after one retry [SQLSTATE 57P01]: driver detail withheld, '
                . 'read driverMessage() to inspect it',
                $e->getMessage(),
            );
            self::assertSame('57P01', $e->sqlState());
        }

        self::assertSame(2, $calls);
        self::assertSame(['DISCARD ALL', 'SELECT pg_terminate_backend(pg_backend_pid())'], $handles[1]->statements);
    }

    private static function config(bool $persistent): DatabaseConfig
    {
        return DatabaseConfig::fromArray(['database' => 'zephyrus', 'username' => 'app', 'persistent' => $persistent]);
    }
}

/**
 * A SQLite connection that reports itself persistent and records the session statements sent to it, so the
 * reset on reuse runs without a server. A failing reset answers as a backend ended by the server does.
 */
final class ReusedSessionPdo extends PDO
{
    /** @var list<string> */
    public array $statements = [];

    public function __construct(private readonly bool $failingReset = false, private bool $openTransaction = false)
    {
        parent::__construct('sqlite::memory:');
    }

    public function getAttribute(int $attribute): mixed
    {
        return $attribute === PDO::ATTR_PERSISTENT ? true : parent::getAttribute($attribute);
    }

    public function inTransaction(): bool
    {
        return $this->openTransaction;
    }

    public function exec(string $statement): int|false
    {
        $this->statements[] = $statement;

        if ($this->failingReset) {
            if (parent::getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
                return false;
            }

            $e = new PDOException('SQLSTATE[57P01]: terminating connection due to administrator command');
            $e->errorInfo = ['57P01', 7, 'terminating connection due to administrator command'];

            throw $e;
        }

        if ($statement === 'ROLLBACK') {
            $this->openTransaction = false;
        }

        return 0;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->statements[] = $query;

        return parent::prepare($query, $options);
    }
}
