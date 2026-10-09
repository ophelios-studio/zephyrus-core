<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Data;

use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Zephyrus\Data\Database;
use Zephyrus\Data\DatabaseException;

final class DatabaseTransactionTest extends TestCase
{
    public function testANestedFailureLeavesNoneOfItsRowsOnceTheOuterCommits(): void
    {
        $db = $this->database(new PDO('sqlite::memory:'));

        $db->transaction(function (Database $db): void {
            try {
                $db->transaction(function (Database $db): void {
                    $db->execute('INSERT INTO entry (label) VALUES (?)', ['nested']);
                    throw new RuntimeException('nested failed');
                });
            } catch (RuntimeException) {
            }
        });

        self::assertSame([], $this->labels($db));
    }

    public function testTheOuterKeepsItsOwnWritesAfterCatchingANestedFailure(): void
    {
        $db = $this->database(new PDO('sqlite::memory:'));

        $db->transaction(function (Database $db): void {
            $db->execute('INSERT INTO entry (label) VALUES (?)', ['before']);

            try {
                $db->transaction(function (Database $db): void {
                    $db->execute('INSERT INTO entry (label) VALUES (?)', ['nested']);
                    throw new RuntimeException('nested failed');
                });
            } catch (RuntimeException) {
            }

            $db->execute('INSERT INTO entry (label) VALUES (?)', ['after']);
        });

        self::assertSame(['after', 'before'], $this->labels($db));
    }

    public function testANestedFailureRethrowsTheWorkException(): void
    {
        $db = $this->database(new PDO('sqlite::memory:'));
        $thrown = new RuntimeException('nested failed');

        try {
            $db->transaction(function (Database $db) use ($thrown): void {
                $db->transaction(function () use ($thrown): void {
                    throw $thrown;
                });
            });
            self::fail('expected the nested exception to propagate');
        } catch (RuntimeException $e) {
            self::assertSame($thrown, $e);
        }

        self::assertFalse($db->inTransaction());
    }

    public function testANestedSuccessIsUndoneWhenTheOuterFailsAfterIt(): void
    {
        $db = $this->database(new PDO('sqlite::memory:'));

        try {
            $db->transaction(function (Database $db): void {
                $db->transaction(function (Database $db): void {
                    $db->execute('INSERT INTO entry (label) VALUES (?)', ['nested']);
                });

                throw new RuntimeException('outer failed');
            });
        } catch (RuntimeException) {
        }

        self::assertSame([], $this->labels($db));
    }

    public function testAMiddleLevelFailureUndoesItselfAndTheLevelsBelowItOnly(): void
    {
        $db = $this->database(new PDO('sqlite::memory:'));

        $db->transaction(function (Database $db): void {
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

        self::assertSame(['outer'], $this->labels($db));
    }

    public function testAnInnerFailureCaughtByTheMiddleKeepsTheMiddleWrites(): void
    {
        $db = $this->database(new PDO('sqlite::memory:'));

        $db->transaction(function (Database $db): void {
            $db->transaction(function (Database $db): void {
                $db->execute('INSERT INTO entry (label) VALUES (?)', ['middle']);

                try {
                    $db->transaction(function (Database $db): void {
                        $db->execute('INSERT INTO entry (label) VALUES (?)', ['inner']);
                        throw new RuntimeException('inner failed');
                    });
                } catch (RuntimeException) {
                }
            });
        });

        self::assertSame(['middle'], $this->labels($db));
    }

    public function testEachNestingLevelGetsItsOwnSavepointName(): void
    {
        $pdo = new StatementRecordingPdo();
        $db = $this->database($pdo);

        $db->transaction(function (Database $db): void {
            try {
                $db->transaction(function (): void {
                    throw new RuntimeException('nested failed');
                });
            } catch (RuntimeException) {
            }

            $db->transaction(function (Database $db): void {
                $db->transaction(fn (): null => null);
            });
        });

        self::assertSame([
            'SAVEPOINT zephyrus_tx_1',
            'ROLLBACK TO SAVEPOINT zephyrus_tx_1',
            'RELEASE SAVEPOINT zephyrus_tx_1',
            'SAVEPOINT zephyrus_tx_1',
            'SAVEPOINT zephyrus_tx_2',
            'RELEASE SAVEPOINT zephyrus_tx_2',
            'RELEASE SAVEPOINT zephyrus_tx_1',
        ], $pdo->savepointStatements());
    }

    public function testAFailedSavepointRollbackStillSurfacesTheWorkException(): void
    {
        $pdo = new StatementRecordingPdo();
        $pdo->failOn = 'ROLLBACK TO SAVEPOINT';
        $db = $this->database($pdo);
        $thrown = new RuntimeException('nested failed');

        try {
            $db->transaction(function (Database $db) use ($thrown): void {
                $db->execute('INSERT INTO entry (label) VALUES (?)', ['outer']);
                $db->transaction(function () use ($thrown): void {
                    throw $thrown;
                });
            });
            self::fail('expected the nested exception to propagate');
        } catch (RuntimeException $e) {
            self::assertSame($thrown, $e);
        }

        self::assertFalse($db->inTransaction());
        self::assertSame([], $this->labels($db));
    }

    public function testAFailedSavepointRollbackIsNotFollowedByARelease(): void
    {
        $pdo = new StatementRecordingPdo();
        $pdo->failOn = 'ROLLBACK TO SAVEPOINT';
        $db = $this->database($pdo);

        try {
            $db->transaction(function (Database $db): void {
                $db->transaction(function (): void {
                    throw new RuntimeException('nested failed');
                });
            });
        } catch (RuntimeException) {
        }

        self::assertNotContains('RELEASE SAVEPOINT zephyrus_tx_1', $pdo->savepointStatements());
    }

    public function testAFailedOuterRollbackStillSurfacesTheWorkException(): void
    {
        $db = new Database(new ScriptedTransactionPdo(['rollBack' => self::driverError('08006')]));
        $thrown = new RuntimeException('work failed');

        try {
            $db->transaction(function () use ($thrown): void {
                throw $thrown;
            });
            self::fail('expected the work exception to propagate');
        } catch (RuntimeException $e) {
            self::assertSame($thrown, $e);
        }
    }

    public function testAFailedBeginKeepsTheDriverTextOffTheMessage(): void
    {
        $db = new Database(new ScriptedTransactionPdo(['beginTransaction' => self::driverError('08006')]));
        $ran = false;

        try {
            $db->transaction(function () use (&$ran): void {
                $ran = true;
            });
            self::fail('expected a DatabaseException');
        } catch (DatabaseException $e) {
            self::assertTerseTransactionFailure($e, 'begin', '08006');
        }

        self::assertFalse($ran);
    }

    public function testAFailedCommitKeepsTheDriverTextOffTheMessage(): void
    {
        $db = new Database(new ScriptedTransactionPdo(['commit' => self::driverError('23505')]));

        try {
            $db->transaction(fn (): string => 'ok');
            self::fail('expected a DatabaseException');
        } catch (DatabaseException $e) {
            self::assertTerseTransactionFailure($e, 'commit', '23505');
        }
    }

    public function testAFailedCommitIsReportedEvenWhenTheRollbackAfterItFails(): void
    {
        $db = new Database(new ScriptedTransactionPdo([
            'commit' => self::driverError('40001'),
            'rollBack' => self::driverError('08006', 'rollback detail'),
        ]));

        try {
            $db->transaction(fn (): string => 'ok');
            self::fail('expected a DatabaseException');
        } catch (DatabaseException $e) {
            self::assertTerseTransactionFailure($e, 'commit', '40001');
            self::assertStringNotContainsString('rollback detail', (string) $e->driverMessage());
        }
    }

    public function testAFailedSavepointKeepsTheDriverTextOffTheMessage(): void
    {
        $pdo = new StatementRecordingPdo();
        $pdo->failOn = 'SAVEPOINT';
        $db = $this->database($pdo);
        $ran = false;

        try {
            $db->transaction(function (Database $db) use (&$ran): void {
                $db->transaction(function () use (&$ran): void {
                    $ran = true;
                });
            });
            self::fail('expected a DatabaseException');
        } catch (DatabaseException $e) {
            self::assertTerseTransactionFailure($e, 'savepoint', '25P02');
        }

        self::assertFalse($ran);
    }

    public function testAFailedReleaseRollsTheNestedWorkBackAndKeepsTheDriverTextOffTheMessage(): void
    {
        $pdo = new StatementRecordingPdo();
        $pdo->failOn = 'RELEASE SAVEPOINT';
        $db = $this->database($pdo);

        $db->transaction(function (Database $db): void {
            $db->execute('INSERT INTO entry (label) VALUES (?)', ['outer']);

            try {
                $db->transaction(function (Database $db): void {
                    $db->execute('INSERT INTO entry (label) VALUES (?)', ['nested']);
                });
                self::fail('expected a DatabaseException');
            } catch (DatabaseException $e) {
                self::assertTerseTransactionFailure($e, 'release savepoint', '25P02');
            }
        });

        self::assertSame(['outer'], $this->labels($db));
        self::assertContains('ROLLBACK TO SAVEPOINT zephyrus_tx_1', $pdo->savepointStatements());
    }

    public function testAFailedProbeAfterACaughtFailureRefusesTheReleaseOfTheNestedWork(): void
    {
        $pdo = new ProbeFailingPdo();
        $db = $this->database($pdo);

        $db->transaction(function (Database $db) use ($pdo): void {
            $db->execute('INSERT INTO entry (label) VALUES (?)', ['outer']);

            try {
                $db->transaction(function (Database $db) use ($pdo): void {
                    $db->execute('INSERT INTO entry (label) VALUES (?)', ['nested']);
                    $this->swallowAFailedStatement($db);
                    $pdo->breakProbe();
                });
                self::fail('expected the release to be refused');
            } catch (DatabaseException $e) {
                self::assertSame('08006', $e->sqlState());
                self::assertStringContainsString('release savepoint', $e->getMessage());
                self::assertStringNotContainsString('caught', $e->getMessage());
            }

            $db->execute('INSERT INTO entry (label) VALUES (?)', ['after']);
        });

        self::assertSame(['after', 'outer'], $this->labels($db));
    }

    public function testAProbeFailureThatIsNot25P02KeepsItsSqlStateAndCause(): void
    {
        $pdo = new ProbeFailingPdo();
        $db = $this->database($pdo);

        $db->transaction(function (Database $db) use ($pdo): void {
            $db->execute('INSERT INTO entry (label) VALUES (?)', ['outer']);

            try {
                $db->transaction(function (Database $db) use ($pdo): void {
                    $db->execute('INSERT INTO entry (label) VALUES (?)', ['nested']);
                    $this->swallowAFailedStatement($db);
                    $pdo->breakProbe('57P01');
                });
                self::fail('expected the release to be refused');
            } catch (DatabaseException $e) {
                self::assertSame('57P01', $e->sqlState());
                self::assertStringContainsString('[SQLSTATE 57P01]', $e->getMessage());
                self::assertStringContainsString('release savepoint', $e->getMessage());
                self::assertStringNotContainsString('caught', $e->getMessage());
                self::assertStringContainsString('connection lost', (string) $e->driverMessage());
            }

            $db->execute('INSERT INTO entry (label) VALUES (?)', ['after']);
        });

        self::assertSame(['after', 'outer'], $this->labels($db));
    }

    public function testALastInsertIdFailureIsReportedAsATransactionFailure(): void
    {
        $db = $this->database(new LastInsertIdFailingPdo());

        try {
            $db->insertGetId('INSERT INTO entry (label) VALUES (?)', ['written']);
            self::fail('expected the last insert id to be refused');
        } catch (DatabaseException $e) {
            self::assertSame('55000', $e->sqlState());
            self::assertStringContainsString('last insert id', $e->getMessage());
            self::assertStringContainsString('lastval is not yet defined', (string) $e->driverMessage());
            self::assertNull($e->getPrevious());
        }
    }

    /**
     * A driver error whose text carries a column value, as PostgreSQL's DETAIL line does.
     */
    public static function driverError(string $sqlState, string $detail = 'Key (email)=(jane@example.com) already exists.'): PDOException
    {
        $e = new PDOException("SQLSTATE[{$sqlState}]: 7 ERROR:  driver failure\nDETAIL:  {$detail}");
        $e->errorInfo = [$sqlState, 7, "ERROR:  driver failure\nDETAIL:  {$detail}"];

        return $e;
    }

    private static function assertTerseTransactionFailure(DatabaseException $e, string $stage, string $sqlState): void
    {
        self::assertStringContainsString("[SQLSTATE {$sqlState}]", $e->getMessage());
        self::assertStringContainsString($stage, $e->getMessage());
        self::assertStringNotContainsString('jane@example.com', $e->getMessage());
        self::assertStringNotContainsString('driver failure', $e->getMessage());
        self::assertSame($sqlState, $e->sqlState());
        self::assertStringContainsString('Key (email)=(jane@example.com)', (string) $e->driverMessage());
        self::assertNull($e->sql());
        self::assertNull($e->getPrevious());
    }

    public function testACaughtStatementFailureStopsTheCommitOfAnAbortedTransaction(): void
    {
        $pdo = new AbortingPdo();
        $db = $this->database($pdo);

        try {
            $db->transaction(function (Database $db) use ($pdo): void {
                $db->execute('INSERT INTO entry (label) VALUES (?)', ['written']);
                $this->swallowAFailedStatement($db);
                $pdo->abort();
            });
            self::fail('expected the commit to be refused');
        } catch (DatabaseException $e) {
            self::assertSame('25P02', $e->sqlState());
            self::assertStringContainsString('commit', $e->getMessage());
            self::assertStringContainsString('caught', $e->getMessage());
        }

        self::assertFalse($db->inTransaction());
        self::assertSame([], $this->labels($db));
    }

    public function testACaughtStatementFailureStopsTheReleaseOfAnAbortedTransactionAndTheOuterCanCarryOn(): void
    {
        $pdo = new AbortingPdo();
        $db = $this->database($pdo);

        $db->transaction(function (Database $db) use ($pdo): void {
            $db->execute('INSERT INTO entry (label) VALUES (?)', ['outer']);

            try {
                $db->transaction(function (Database $db) use ($pdo): void {
                    $db->execute('INSERT INTO entry (label) VALUES (?)', ['nested']);
                    $this->swallowAFailedStatement($db);
                    $pdo->abort();
                });
                self::fail('expected the release to be refused');
            } catch (DatabaseException $e) {
                self::assertSame('25P02', $e->sqlState());
                self::assertStringContainsString('release savepoint', $e->getMessage());
                self::assertStringContainsString('caught', $e->getMessage());
            }

            $db->execute('INSERT INTO entry (label) VALUES (?)', ['after']);
        });

        self::assertSame(['after', 'outer'], $this->labels($db));
    }

    public function testACaughtStatementFailureCommitsOnADriverThatKeepsTheTransactionUsable(): void
    {
        $db = $this->database(new PDO('sqlite::memory:'));

        $db->transaction(function (Database $db): void {
            $db->execute('INSERT INTO entry (label) VALUES (?)', ['written']);
            $this->swallowAFailedStatement($db);
        });

        self::assertSame(['written'], $this->labels($db));
    }

    public function testAStatementFailureLeftToPropagateOutOfANestedCallDoesNotStopTheOuter(): void
    {
        $db = $this->database(new PDO('sqlite::memory:'));

        $db->transaction(function (Database $db): void {
            $db->execute('INSERT INTO entry (label) VALUES (?)', ['outer']);

            try {
                $db->transaction(function (Database $db): void {
                    $db->execute('INSERT INTO entry (label) VALUES (?)', [null]);
                });
            } catch (DatabaseException) {
            }
        });

        self::assertSame(['outer'], $this->labels($db));
    }

    public function testAFailureInAnEarlierTransactionDoesNotStopTheNextOne(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $db = $this->database($pdo);

        try {
            $db->transaction(function (Database $db): void {
                $db->execute('INSERT INTO entry (label) VALUES (?)', [null]);
            });
        } catch (DatabaseException) {
        }

        $pdo->beginTransaction();
        $this->swallowAFailedStatement($db);
        $pdo->rollBack();

        $db->transaction(function (Database $db): void {
            $db->execute('INSERT INTO entry (label) VALUES (?)', ['next']);
        });

        self::assertSame(['next'], $this->labels($db));
    }

    public function testAnOuterTransactionOpenedOnThePdoIsTreatedAsTheOuterLevel(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $db = $this->database($pdo);
        $pdo->beginTransaction();

        $db->execute('INSERT INTO entry (label) VALUES (?)', ['outer']);

        try {
            $db->transaction(function (Database $db): void {
                $db->execute('INSERT INTO entry (label) VALUES (?)', ['nested']);
                throw new RuntimeException('nested failed');
            });
        } catch (RuntimeException) {
        }

        self::assertTrue($pdo->inTransaction());
        $pdo->commit();
        self::assertSame(['outer'], $this->labels($db));
    }

    private function swallowAFailedStatement(Database $db): void
    {
        try {
            $db->execute('INSERT INTO entry (label) VALUES (?)', [null]);
            self::fail('expected the NOT NULL constraint to fail');
        } catch (DatabaseException) {
        }
    }

    private function database(PDO $pdo): Database
    {
        $db = new Database($pdo);
        $db->execute('CREATE TABLE entry (label TEXT NOT NULL)');

        return $db;
    }

    /**
     * @return list<string>
     */
    private function labels(Database $db): array
    {
        return array_map(
            static fn (\stdClass $row): string => $row->label,
            $db->select('SELECT label FROM entry ORDER BY label'),
        );
    }
}

/**
 * A real SQLite connection that records the savepoint statements it is sent
 * and can be told to fail the first statement starting with a given prefix.
 */
final class StatementRecordingPdo extends PDO
{
    public ?string $failOn = null;

    /** @var list<string> */
    private array $statements = [];

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    public function exec(string $statement): int|false
    {
        $this->statements[] = $statement;

        if ($this->failOn !== null && str_starts_with($statement, $this->failOn)) {
            $this->failOn = null;

            throw DatabaseTransactionTest::driverError('25P02');
        }

        return parent::exec($statement);
    }

    /**
     * @return list<string>
     */
    public function savepointStatements(): array
    {
        return array_values(array_filter(
            $this->statements,
            static fn (string $statement): bool => str_contains($statement, 'SAVEPOINT'),
        ));
    }
}

/**
 * Tracks its own transaction state and throws the given error from the methods
 * it is told to fail. Like a dead connection, it still reports an open
 * transaction after a failed commit or rollback.
 */
final class ScriptedTransactionPdo extends PDO
{
    private bool $inTransaction = false;

    /**
     * @param array<'beginTransaction'|'commit'|'rollBack', PDOException> $failures
     */
    public function __construct(private readonly array $failures)
    {
        parent::__construct('sqlite::memory:');
    }

    public function beginTransaction(): bool
    {
        $this->failIfScripted('beginTransaction');
        $this->inTransaction = true;

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function commit(): bool
    {
        $this->failIfScripted('commit');
        $this->inTransaction = false;

        return true;
    }

    public function rollBack(): bool
    {
        $this->failIfScripted('rollBack');
        $this->inTransaction = false;

        return true;
    }

    private function failIfScripted(string $method): void
    {
        if (isset($this->failures[$method])) {
            throw $this->failures[$method];
        }
    }
}

/**
 * A real SQLite connection whose transaction-state probe fails with the given
 * SQLSTATE, as a dropped connection or a server restart would.
 */
final class ProbeFailingPdo extends PDO
{
    private ?string $probeSqlState = null;

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    public function breakProbe(string $sqlState = '08006'): void
    {
        $this->probeSqlState = $sqlState;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($this->probeSqlState !== null) {
            throw DatabaseTransactionTest::driverError($this->probeSqlState, 'connection lost');
        }

        return parent::query($query);
    }
}

/**
 * A real SQLite connection whose lastInsertId() fails as it does on PostgreSQL
 * for a table without a sequence.
 */
final class LastInsertIdFailingPdo extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    public function lastInsertId(?string $name = null): string|false
    {
        throw DatabaseTransactionTest::driverError('55000', 'lastval is not yet defined in this session');
    }
}

/**
 * A real SQLite connection that can be put in the state PostgreSQL enters after
 * a failed statement: every later statement fails with 25P02 until a rollback.
 */
final class AbortingPdo extends PDO
{
    private bool $aborted = false;

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    public function abort(): void
    {
        $this->aborted = true;
    }

    /**
     * @param array<int, mixed> $options
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->failIfAborted();

        return parent::prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->failIfAborted();

        return parent::query($query);
    }

    public function exec(string $statement): int|false
    {
        if (str_starts_with($statement, 'ROLLBACK TO SAVEPOINT')) {
            $this->aborted = false;
        } else {
            $this->failIfAborted();
        }

        return parent::exec($statement);
    }

    public function rollBack(): bool
    {
        $this->aborted = false;

        return parent::rollBack();
    }

    private function failIfAborted(): void
    {
        if ($this->aborted) {
            throw DatabaseTransactionTest::driverError('25P02', 'current transaction is aborted');
        }
    }
}
