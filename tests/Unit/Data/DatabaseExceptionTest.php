<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Data;

use PDOException;
use PHPUnit\Framework\TestCase;
use Zephyrus\Data\DatabaseException;
use Zephyrus\Exceptions\ZephyrusRuntimeException;

final class DatabaseExceptionTest extends TestCase
{
    public function testIsZephyrusRuntimeException(): void
    {
        $e = DatabaseException::queryFailed('SELECT 1', 'test');
        self::assertInstanceOf(ZephyrusRuntimeException::class, $e);
    }

    public function testConnectionFailedMessage(): void
    {
        $e = DatabaseException::connectionFailed('mysql:host=localhost', 'Access denied');
        self::assertStringContainsString('mysql:host=localhost', $e->getMessage());
        self::assertStringContainsString('Access denied', $e->getMessage());
    }

    public function testQueryFailedMessage(): void
    {
        $e = DatabaseException::queryFailed('SELECT * FROM foo', 'table not found');
        self::assertStringContainsString('SELECT * FROM foo', $e->getMessage());
        self::assertStringContainsString('table not found', $e->getMessage());
    }

    public function testTransactionFailedMessage(): void
    {
        $e = DatabaseException::transactionFailed('begin: server gone');
        self::assertStringContainsString('Transaction failed', $e->getMessage());
        self::assertStringContainsString('server gone', $e->getMessage());
    }

    public function testFromPdoExceptionWithoutContext(): void
    {
        $pdo = new PDOException('Table "users" not found');
        $e = DatabaseException::fromPdoException($pdo);
        self::assertStringContainsString('Table "users" not found', $e->getMessage());
        self::assertSame($pdo, $e->getPrevious());
    }

    public function testFromPdoExceptionWithContext(): void
    {
        $pdo = new PDOException('duplicate key');
        $e = DatabaseException::fromPdoException($pdo, 'UserBroker::insert');
        self::assertStringContainsString('UserBroker::insert', $e->getMessage());
        self::assertStringContainsString('duplicate key', $e->getMessage());
        self::assertSame($pdo, $e->getPrevious());
    }

    // ── query-execution factory: safe by default (finding 5) ────────────────

    protected function tearDown(): void
    {
        // Process-wide switch: never let one test's verbosity leak into the next.
        DatabaseException::enableVerboseMessages(false);
    }

    private function pdoException(): PDOException
    {
        $e = new PDOException(
            'SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type integer: "Jane Roe"'
            . "\nLINE 1: ... VALUES ('1','Jane Roe','a private note', ...",
        );
        $e->errorInfo = ['22P02', 7, 'invalid input syntax for type integer'];

        return $e;
    }

    /**
     * Under ATTR_EMULATE_PREPARES the statement PostgreSQL parsed is the
     * INTERPOLATED one, so the driver echoes real column values back in its error
     * text. That text used to be the exception message verbatim, alongside the raw
     * SQL, and travelled straight into logs, alert emails and debug error pages.
     */
    public function testQueryExecutionFailedWithholdsTheStatementAndDriverTextByDefault(): void
    {
        $e = DatabaseException::queryExecutionFailed(
            'INSERT INTO customer (company_id, full_name, notes) VALUES (?, ?, ?)',
            $this->pdoException(),
        );

        self::assertStringNotContainsString('Jane Roe', $e->getMessage());
        self::assertStringNotContainsString('a private note', $e->getMessage());
        self::assertStringNotContainsString('INSERT INTO customer', $e->getMessage());
        // The SQLSTATE survives, because it is a condition code and never a value.
        self::assertStringContainsString('22P02', $e->getMessage());
    }

    public function testQueryExecutionFailedKeepsTheDetailReachableForAScrubbingCaller(): void
    {
        $e = DatabaseException::queryExecutionFailed(
            'INSERT INTO customer (company_id, full_name, notes) VALUES (?, ?, ?)',
            $this->pdoException(),
        );

        self::assertSame('INSERT INTO customer (company_id, full_name, notes) VALUES (?, ?, ?)', $e->sql());
        self::assertStringContainsString('Jane Roe', (string) $e->driverMessage());
    }

    public function testEnableVerboseMessagesRestoresTheFullMessage(): void
    {
        DatabaseException::enableVerboseMessages();
        self::assertTrue(DatabaseException::verboseMessagesEnabled());

        $pdo = $this->pdoException();
        $e = DatabaseException::queryExecutionFailed('SELECT * FROM customer', $pdo);

        self::assertSame("Query failed [SELECT * FROM customer]: {$pdo->getMessage()}", $e->getMessage());
    }

    public function testQueryExecutionFailedFallsBackWhenTheDriverReportsNoSqlState(): void
    {
        $e = DatabaseException::queryExecutionFailed('SELECT 1', new PDOException('server has gone away'));

        self::assertStringNotContainsString('server has gone away', $e->getMessage());
        self::assertStringContainsString('Query failed', $e->getMessage());
    }

    /**
     * The hand-called factories are unchanged: their arguments are chosen by the
     * caller, not lifted off a driver, so they carry no value the caller did not
     * put there. sql() and driverMessage() are null for them.
     */
    public function testHandCalledFactoriesCarryNoDriverDetail(): void
    {
        $e = DatabaseException::queryFailed('pagination', 'Page must be >= 1');

        self::assertNull($e->sql());
        self::assertNull($e->driverMessage());
    }
}
