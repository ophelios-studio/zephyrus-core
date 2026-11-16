<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Data;

use PDO;
use PHPUnit\Framework\TestCase;
use Zephyrus\Data\Database;
use Zephyrus\Data\DatabaseActor;
use Zephyrus\Data\DatabaseActorException;

final class DatabaseActorTest extends TestCase
{
    public function testAConnectionOtherThanPostgresqlIsRefused(): void
    {
        $db = new Database(new PDO('sqlite::memory:'));

        try {
            new DatabaseActor($db, 'app', ['actor_id']);
            self::fail('expected a refusal');
        } catch (DatabaseActorException $e) {
            self::assertSame(
                'DatabaseActor needs a PostgreSQL connection: set_config() and row level security exist only there.',
                $e->getMessage(),
            );
        }
    }
}
