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
}
