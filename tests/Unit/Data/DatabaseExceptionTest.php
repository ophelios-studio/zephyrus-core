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

    // ── driver-error factories ──────────────────────────────────────────────

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
     * The fixture's driver text carries both column values and the statement.
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

    /**
     * Log search and alert rules key on the `[SQLSTATE nnnnn]` token.
     */
    public function testQueryExecutionFailedMessageCarriesTheSqlStateTokenAndNoDriverText(): void
    {
        $e = DatabaseException::queryExecutionFailed('SELECT * FROM customer', $this->pdoException());

        self::assertStringContainsString('[SQLSTATE 22P02]', $e->getMessage());
        self::assertStringNotContainsString('invalid input syntax', $e->getMessage());
        self::assertStringNotContainsString('SELECT * FROM customer', $e->getMessage());
    }

    public function testQueryExecutionFailedFallsBackWhenTheDriverReportsNoSqlState(): void
    {
        $e = DatabaseException::queryExecutionFailed('SELECT 1', new PDOException('server has gone away'));

        self::assertStringNotContainsString('server has gone away', $e->getMessage());
        self::assertStringContainsString('Query failed', $e->getMessage());
    }

    public function testSqlStateIsExposedAsAValueAndStaysInTheMessage(): void
    {
        $e = DatabaseException::queryExecutionFailed('INSERT INTO customer (id) VALUES (?)', $this->uniqueViolation());

        self::assertSame('23505', $e->sqlState());
        self::assertStringContainsString('[SQLSTATE 23505]', $e->getMessage());
    }

    public function testSqlStateFallsBackToHy000WhenTheDriverReportsNoCode(): void
    {
        $e = DatabaseException::queryExecutionFailed('SELECT 1', new PDOException('server has gone away'));

        self::assertSame('HY000', $e->sqlState());
    }

    public function testSqlStateIgnoresAnIntegerCodeThatIsNotAnSqlState(): void
    {
        $e = DatabaseException::queryExecutionFailed('SELECT 1', new PDOException('boom', 7));

        self::assertSame('HY000', $e->sqlState());
    }

    public function testSqlStateIsNullForFactoriesWithoutADriverError(): void
    {
        self::assertNull(DatabaseException::queryFailed('SELECT 1', 'nope')->sqlState());
        self::assertNull(DatabaseException::connectionFailed('mysql:host=x', 'nope')->sqlState());
        self::assertNull(DatabaseException::transactionFailed('commit: nope')->sqlState());
    }

    private function uniqueViolation(): PDOException
    {
        $e = new PDOException(
            'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "customer_email_key"'
            . "\nDETAIL:  Key (email)=(jane@example.com) already exists.",
        );
        $e->errorInfo = ['23505', 7, 'ERROR:  duplicate key value violates unique constraint "customer_email_key"'];

        return $e;
    }

    public function testHandCalledFactoriesCarryNoDriverDetail(): void
    {
        $e = DatabaseException::queryFailed('pagination', 'Page must be >= 1');

        self::assertNull($e->sql());
        self::assertNull($e->driverMessage());
    }

    public function testTransactionExecutionFailedKeepsTheDriverTextOffTheMessage(): void
    {
        $e = DatabaseException::transactionExecutionFailed('commit', $this->uniqueViolation());

        self::assertStringContainsString('[SQLSTATE 23505]', $e->getMessage());
        self::assertStringContainsString('commit', $e->getMessage());
        self::assertStringNotContainsString('jane@example.com', $e->getMessage());
        self::assertStringNotContainsString('duplicate key', $e->getMessage());
    }

    public function testTransactionExecutionFailedKeepsTheDetailReachable(): void
    {
        $e = DatabaseException::transactionExecutionFailed('release savepoint', $this->uniqueViolation());

        self::assertSame('23505', $e->sqlState());
        self::assertStringContainsString('Key (email)=(jane@example.com)', (string) $e->driverMessage());
        self::assertNull($e->sql());
        self::assertNull($e->getPrevious());
    }

    public function testTransactionExecutionFailedFallsBackToHy000WhenTheDriverReportsNoCode(): void
    {
        $e = DatabaseException::transactionExecutionFailed('begin', new PDOException('server has gone away'));

        self::assertSame('HY000', $e->sqlState());
        self::assertStringContainsString('[SQLSTATE HY000]', $e->getMessage());
        self::assertStringNotContainsString('server has gone away', $e->getMessage());
    }
}
