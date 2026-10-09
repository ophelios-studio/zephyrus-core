<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Zephyrus\Data\Database;
use Zephyrus\Data\DatabaseException;

/**
 * Nested transactions against a real PostgreSQL server, which unlike SQLite
 * aborts the whole transaction on the first failed statement and reports a
 * dead connection as still being in a transaction.
 *
 * Runs only when ZEPHYRUS_TEST_PGSQL_DSN holds a PDO DSN carrying its own
 * credentials. Each test works in a throwaway schema it drops afterwards.
 */
final class DatabaseTransactionPostgresTest extends TestCase
{
    private string $dsn;
    private string $schema;
    private PDO $admin;
    private Database $db;

    protected function setUp(): void
    {
        $dsn = getenv('ZEPHYRUS_TEST_PGSQL_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped(
                'Set ZEPHYRUS_TEST_PGSQL_DSN (e.g. pgsql:host=127.0.0.1;port=5432;dbname=postgres;user=postgres;password=postgres) to run the PostgreSQL transaction tests.',
            );
        }

        $this->dsn = $dsn;
        $this->schema = 'zephyrus_tx_test_' . bin2hex(random_bytes(6));
        $this->admin = new PDO($this->dsn);
        $this->admin->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->admin->exec("CREATE SCHEMA {$this->schema}");
        $this->admin->exec("CREATE TABLE {$this->schema}.entry (label TEXT PRIMARY KEY)");

        $this->db = new Database(new PDO($this->dsn));
        $this->db->pdo()->exec("SET search_path TO {$this->schema}");
    }

    protected function tearDown(): void
    {
        if (!isset($this->admin)) {
            return;
        }

        unset($this->db);
        $this->admin->exec("DROP SCHEMA IF EXISTS {$this->schema} CASCADE");
    }

    public function testANestedFailureLeavesNoRowsOnceTheOuterCommits(): void
    {
        $this->db->transaction(function (Database $db): void {
            try {
                $db->transaction(function (Database $db): void {
                    $db->execute('INSERT INTO entry (label) VALUES (?)', ['nested']);
                    throw new RuntimeException('nested failed');
                });
            } catch (RuntimeException) {
            }
        });

        self::assertSame([], $this->committedLabels());
    }

    public function testANestedSuccessCommitsWithTheOuter(): void
    {
        $this->db->transaction(function (Database $db): void {
            $db->execute('INSERT INTO entry (label) VALUES (?)', ['outer']);
            $db->transaction(function (Database $db): void {
                $db->execute('INSERT INTO entry (label) VALUES (?)', ['nested']);
            });
        });

        self::assertSame(['nested', 'outer'], $this->committedLabels());
    }

    public function testAMiddleLevelFailureUndoesItselfAndTheLevelsBelowItOnly(): void
    {
        $this->db->transaction(function (Database $db): void {
            $db->execute('INSERT INTO entry (label) VALUES (?)', ['outer']);

            try {
                $db->transaction(function (Database $db): void {
                    $db->execute('INSERT INTO entry (label) VALUES (?)', ['middle']);
                    $db->transaction(function (Database $db): void {
                        $db->execute('INSERT INTO entry (label) VALUES (?)', ['inner']);
                    });

                    throw new RuntimeException('middle failed');
                });
            } catch (RuntimeException) {
            }
        });

        self::assertSame(['outer'], $this->committedLabels());
    }

    public function testTheOuterCanCarryOnAndCommitAfterANestedStatementFails(): void
    {
        $this->db->transaction(function (Database $db): void {
            $db->execute('INSERT INTO entry (label) VALUES (?)', ['outer']);

            try {
                $db->transaction(function (Database $db): void {
                    $db->execute('INSERT INTO entry (label) VALUES (?)', ['outer']);
                });
            } catch (DatabaseException) {
            }

            $db->execute('INSERT INTO entry (label) VALUES (?)', ['after']);
        });

        self::assertSame(['after', 'outer'], $this->committedLabels());
    }

    public function testANestedCallThatSwallowsAFailedStatementIsRolledBackToItsSavepoint(): void
    {
        $this->db->transaction(function (Database $db): void {
            $db->execute('INSERT INTO entry (label) VALUES (?)', ['outer']);

            try {
                $db->transaction(function (Database $db): void {
                    $db->execute('INSERT INTO entry (label) VALUES (?)', ['nested']);

                    try {
                        $db->execute('INSERT INTO entry (label) VALUES (?)', ['outer']);
                    } catch (DatabaseException) {
                    }
                });
                self::fail('expected the savepoint release to fail on an aborted transaction');
            } catch (DatabaseException $e) {
                self::assertSame('25P02', $e->sqlState());
                self::assertStringContainsString('[SQLSTATE 25P02]', $e->getMessage());
                self::assertStringContainsString('release savepoint', $e->getMessage());
                self::assertStringContainsString('caught', $e->getMessage());
            }

            $db->execute('INSERT INTO entry (label) VALUES (?)', ['after']);
        });

        self::assertSame(['after', 'outer'], $this->committedLabels());
    }

    public function testAWorkThatSwallowsAFailedStatementIsNotReportedAsCommitted(): void
    {
        try {
            $this->db->transaction(function (Database $db): void {
                $db->execute('INSERT INTO entry (label) VALUES (?)', ['outer']);

                try {
                    $db->execute('INSERT INTO entry (label) VALUES (?)', ['outer']);
                } catch (DatabaseException) {
                }
            });
            self::fail('expected the commit of an aborted transaction to be refused');
        } catch (DatabaseException $e) {
            self::assertSame('25P02', $e->sqlState());
            self::assertStringContainsString('commit', $e->getMessage());
            self::assertStringContainsString('caught', $e->getMessage());
        }

        self::assertFalse($this->db->inTransaction());
        self::assertSame([], $this->committedLabels());
    }

    public function testAFailureTheWorkRepairsWithItsOwnSavepointStillCommits(): void
    {
        $result = $this->db->transaction(function (Database $db): string {
            $db->execute('INSERT INTO entry (label) VALUES (?)', ['outer']);
            $db->pdo()->exec('SAVEPOINT caller_guard');

            try {
                $db->execute('INSERT INTO entry (label) VALUES (?)', ['outer']);
            } catch (DatabaseException) {
                $db->pdo()->exec('ROLLBACK TO SAVEPOINT caller_guard');

                return 'refused';
            }

            return 'inserted';
        });

        self::assertSame('refused', $result);
        self::assertSame(['outer'], $this->committedLabels());
    }

    public function testANestedFailureTheWorkRepairsWithItsOwnSavepointIsReleased(): void
    {
        $this->db->transaction(function (Database $db): void {
            $db->execute('INSERT INTO entry (label) VALUES (?)', ['outer']);

            $db->transaction(function (Database $db): void {
                $db->execute('INSERT INTO entry (label) VALUES (?)', ['nested']);
                $db->pdo()->exec('SAVEPOINT caller_guard');

                try {
                    $db->execute('INSERT INTO entry (label) VALUES (?)', ['outer']);
                } catch (DatabaseException) {
                    $db->pdo()->exec('ROLLBACK TO SAVEPOINT caller_guard');
                }
            });
        });

        self::assertSame(['nested', 'outer'], $this->committedLabels());
    }

    public function testADeferredViolationAtCommitKeepsTheValueOffTheMessage(): void
    {
        $this->admin->exec("CREATE TABLE {$this->schema}.account (email TEXT UNIQUE DEFERRABLE INITIALLY DEFERRED)");
        $this->admin->exec("INSERT INTO {$this->schema}.account (email) VALUES ('jane@example.com')");

        try {
            $this->db->transaction(function (Database $db): void {
                $db->execute('INSERT INTO account (email) VALUES (?)', ['jane@example.com']);
            });
            self::fail('expected the commit to fail on the deferred unique constraint');
        } catch (DatabaseException $e) {
            self::assertStringNotContainsString('jane@example.com', $e->getMessage());
            self::assertStringContainsString('[SQLSTATE 23505]', $e->getMessage());
            self::assertStringContainsString('commit', $e->getMessage());
            self::assertSame('23505', $e->sqlState());
            self::assertStringContainsString('Key (email)=(jane@example.com)', (string) $e->driverMessage());
        }

        self::assertFalse($this->db->inTransaction());
        self::assertSame(1, $this->db->count('SELECT count(*) FROM account'));
    }

    public function testARollbackOnAConnectionThatDiedSurfacesTheWorkException(): void
    {
        $thrown = new RuntimeException('work failed');

        try {
            $this->db->transaction(function (Database $db) use ($thrown): void {
                $db->execute('INSERT INTO entry (label) VALUES (?)', ['outer']);
                $this->terminateBackendOf($db);
                throw $thrown;
            });
            self::fail('expected the work exception to propagate');
        } catch (RuntimeException $e) {
            self::assertSame($thrown, $e);
        }

        self::assertSame([], $this->committedLabels());
    }

    public function testASavepointRollbackOnAConnectionThatDiedSurfacesTheWorkException(): void
    {
        $thrown = new RuntimeException('nested failed');

        try {
            $this->db->transaction(function (Database $db) use ($thrown): void {
                $db->execute('INSERT INTO entry (label) VALUES (?)', ['outer']);
                $db->transaction(function (Database $db) use ($thrown): void {
                    $db->execute('INSERT INTO entry (label) VALUES (?)', ['nested']);
                    $this->terminateBackendOf($db);
                    throw $thrown;
                });
            });
            self::fail('expected the nested exception to propagate');
        } catch (RuntimeException $e) {
            self::assertSame($thrown, $e);
        }

        self::assertSame([], $this->committedLabels());
    }

    /**
     * @return list<string>
     */
    private function committedLabels(): array
    {
        $labels = $this->admin->query("SELECT label FROM {$this->schema}.entry ORDER BY label")->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_map('strval', $labels));
    }

    private function terminateBackendOf(Database $db): void
    {
        $pid = $db->selectInt('SELECT pg_backend_pid()');
        $this->admin->prepare('SELECT pg_terminate_backend(?)')->execute([$pid]);

        $alive = $this->admin->prepare('SELECT count(*) FROM pg_stat_activity WHERE pid = ?');

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $alive->execute([$pid]);

            if ((int) $alive->fetchColumn() === 0) {
                return;
            }

            usleep(20_000);
        }

        self::fail('the backend did not terminate');
    }
}
