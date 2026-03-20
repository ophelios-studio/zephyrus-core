<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Session;

use PDO;
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
        $pdo->exec('CREATE TABLE session (id VARCHAR PRIMARY KEY, access INTEGER NOT NULL, data TEXT NOT NULL DEFAULT "")');
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
        self::assertSame('', $this->handler->read('nonexistent'));
    }

    public function testWriteThenReadReturnsData(): void
    {
        $this->handler->write('sess_1', 'foo=bar');

        self::assertSame('foo=bar', $this->handler->read('sess_1'));
    }

    public function testWriteUpdatesExistingRow(): void
    {
        $this->handler->write('sess_1', 'first');
        $this->handler->write('sess_1', 'second');

        self::assertSame('second', $this->handler->read('sess_1'));

        // Ensure only one row exists.
        $count = $this->database->count('SELECT COUNT(*) FROM session WHERE id = ?', ['sess_1']);
        self::assertSame(1, $count);
    }

    public function testDestroyRemovesSession(): void
    {
        $this->handler->write('sess_1', 'data');
        $this->handler->destroy('sess_1');

        self::assertSame('', $this->handler->read('sess_1'));
    }

    public function testDestroyNonexistentSessionDoesNotThrow(): void
    {
        // Should not throw.
        $result = $this->handler->destroy('nonexistent');

        self::assertTrue($result);
    }

    public function testGcRemovesExpiredSessions(): void
    {
        // Insert a row with an old access timestamp.
        $this->database->execute(
            'INSERT INTO session (id, access, data) VALUES (?, ?, ?)',
            ['old_sess', time() - 7200, 'old_data'],
        );
        $this->handler->write('fresh_sess', 'fresh_data');

        // GC with a 1-hour lifetime should remove 'old_sess' but keep 'fresh_sess'.
        $deleted = $this->handler->gc(3600);

        self::assertSame(1, $deleted);
        self::assertSame('', $this->handler->read('old_sess'));
        self::assertSame('fresh_data', $this->handler->read('fresh_sess'));
    }

    public function testWriteReturnsTrueOnInsertAndUpdate(): void
    {
        self::assertTrue($this->handler->write('sess_1', 'data'));
        self::assertTrue($this->handler->write('sess_1', 'updated'));
    }
}
