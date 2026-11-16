<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Data;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @param int|string $key a key that may itself be an actor value
     */
    #[DataProvider('keysThatMayBeAnActorValue')]
    public function testAKeyThatMayBeAnActorValueIsNotShownInTheRefusal(int|string $key, string $type): void
    {
        try {
            $this->actor()->actAs([$key => 'u1'], static fn (): bool => true);
            self::fail('expected a refusal');
        } catch (DatabaseActorException $e) {
            self::assertSame(
                "The actor values hold a key that is not a setting name ({$type} given), not shown since it may be an "
                . "actor value: the keys are the declared names, such as ['actor_id' => \$value] "
                . '(declared: "actor_id", "org_id").',
                $e->getMessage(),
            );
        }
    }

    /**
     * @return iterable<string, array{int|string, string}>
     */
    public static function keysThatMayBeAnActorValue(): iterable
    {
        yield 'integer key' => [42, 'int'];
        yield 'email address' => ['user@example.com', 'string'];
        yield 'uuid' => ['3f2504e0-4f89-11d3-9a0c-0305e82c3301', 'string'];
    }

    public function testAnUndeclaredKeyShapedLikeASettingNameIsStillNamed(): void
    {
        try {
            $this->actor()->actAs(['team_id' => 't1'], static fn (): bool => true);
            self::fail('expected a refusal');
        } catch (DatabaseActorException $e) {
            self::assertSame(
                'The actor values key "team_id" names no declared key (declared: "actor_id", "org_id").',
                $e->getMessage(),
            );
        }
    }

    /**
     * The refusal comes before any SQL, so a connection that only reports the PostgreSQL driver is enough.
     */
    private function actor(): DatabaseActor
    {
        $pdo = new class ('sqlite::memory:') extends PDO {
            public function getAttribute(int $attribute): mixed
            {
                return $attribute === PDO::ATTR_DRIVER_NAME ? 'pgsql' : parent::getAttribute($attribute);
            }
        };

        return new DatabaseActor(new Database($pdo), 'app', ['actor_id', 'org_id']);
    }
}
