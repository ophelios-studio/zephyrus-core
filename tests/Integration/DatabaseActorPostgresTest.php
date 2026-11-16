<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Zephyrus\Data\Database;
use Zephyrus\Data\DatabaseActor;
use Zephyrus\Data\DatabaseActorException;

/**
 * An actor published by DatabaseActor lives exactly as long as its transaction.
 *
 * Skipped unless ZEPHYRUS_TEST_PGSQL_DSN holds a full pdo_pgsql DSN with its credentials.
 */
final class DatabaseActorPostgresTest extends TestCase
{
    private const array NO_ACTOR = ['actor_id' => null, 'org_id' => null];

    private const string ALREADY_PUBLISHED = 'An actor is already published on this connection (one of '
        . '"app.actor_id", "app.org_id" is set): an actor changes only by ending its transaction, and a value set '
        . 'for the whole session is refused.';

    private const string NAME_RULE = 'use 1 to 63 lowercase letters, digits and underscores, not starting with a digit.';

    private Database $db;

    protected function setUp(): void
    {
        $dsn = getenv('ZEPHYRUS_TEST_PGSQL_DSN');

        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('ZEPHYRUS_TEST_PGSQL_DSN is not set.');
        }

        $this->db = new Database(new PDO($dsn));
    }

    public function testAnActorPublishedByActAsEndsWithItsTransaction(): void
    {
        $actorId = "o'brien\\42";

        $seen = self::actor($this->db)->actAs(
            ['actor_id' => $actorId, 'org_id' => 'org-7'],
            static fn (Database $db): array => self::published($db),
        );

        self::assertSame(['actor_id' => $actorId, 'org_id' => 'org-7'], $seen);
        self::assertSame(self::NO_ACTOR, self::published($this->db));
    }

    public function testTwoSuccessiveActAsOnTheSameDatabaseEachPublishTheirOwnActor(): void
    {
        $actor = self::actor($this->db);
        $read = static fn (Database $db): array => self::published($db);

        $first = $actor->actAs(['actor_id' => 'first'], $read);
        $second = $actor->actAs(['actor_id' => 'second'], $read);

        self::assertSame(['actor_id' => 'first', 'org_id' => null], $first);
        self::assertSame(['actor_id' => 'second', 'org_id' => null], $second);
    }

    public function testNoActorRemainsAfterAWorkThatThrows(): void
    {
        $failure = new RuntimeException();

        try {
            self::actor($this->db)->actAs(['actor_id' => 'u1'], static function () use ($failure): never {
                throw $failure;
            });
            self::fail('expected the work to throw');
        } catch (RuntimeException $e) {
            self::assertSame($failure, $e);
        }

        self::assertFalse($this->db->inTransaction());
        self::assertSame(self::NO_ACTOR, self::published($this->db));
    }

    public function testASecondActorInsideActAsIsRefusedAndTheFirstStays(): void
    {
        $actor = self::actor($this->db);

        $seen = $actor->actAs(['actor_id' => 'first'], static function (Database $db) use ($actor): array {
            self::assertRefused(self::ALREADY_PUBLISHED, static fn () => $actor->publish(['actor_id' => 'second']));

            return self::rawSettings($db);
        });

        self::assertSame(['actor_id' => 'first', 'org_id' => null], $seen);
    }

    /**
     * @param array{actor_id: ?string, org_id: ?string} $expected
     */
    #[DataProvider('sessionLeftovers')]
    public function testAnActorLeftForTheSessionMakesActAsRefuseAndStaysAsItWas(string $leftKey, array $expected): void
    {
        $this->db->query('SELECT set_config(?, ?, false)', ['app.' . $leftKey, 'leftover']);
        $ran = false;

        self::assertRefused(
            self::ALREADY_PUBLISHED,
            fn () => self::actor($this->db)->actAs(['actor_id' => 'u1'], static function () use (&$ran): void {
                $ran = true;
            }),
        );

        self::assertFalse($ran);
        self::assertFalse($this->db->inTransaction());
        self::assertSame($expected, self::rawSettings($this->db));
    }

    /**
     * @return iterable<string, array{string, array{actor_id: ?string, org_id: ?string}}>
     */
    public static function sessionLeftovers(): iterable
    {
        yield 'on the key being published' => ['actor_id', ['actor_id' => 'leftover', 'org_id' => null]];
        yield 'only on the other key' => ['org_id', ['actor_id' => null, 'org_id' => 'leftover']];
    }

    public function testAnActorDeclaringOneKeyNamesThatKeyWhenAnActorIsAlreadyPublished(): void
    {
        $this->db->query("SELECT set_config('app.actor_id', 'leftover', false)");

        self::assertRefused(
            'An actor is already published on this connection ("app.actor_id" is set): an actor changes only by '
            . 'ending its transaction, and a value set for the whole session is refused.',
            fn () => (new DatabaseActor($this->db, 'app', ['actor_id']))->actAs(['actor_id' => 'u1'], static fn (): bool => true),
        );
    }

    public function testActAsInsideAnOpenTransactionIsRefused(): void
    {
        $seen = $this->db->transaction(static function (Database $db): array {
            self::assertRefused(
                'DatabaseActor::actAs() opens its own transaction and refuses one already open, where the actor '
                . 'would outlive the callback until that transaction ends: call actAs() outside any transaction, '
                . 'or publish() inside the open one.',
                static fn () => self::actor($db)->actAs(['actor_id' => 'u1'], static fn (): bool => true),
            );

            return self::rawSettings($db);
        });

        self::assertSame(self::NO_ACTOR, $seen);
    }

    public function testPublishOutsideATransactionIsRefused(): void
    {
        self::assertRefused(
            'DatabaseActor::publish() needs an open transaction: outside one, PostgreSQL drops the actor before the '
            . 'next statement. Use actAs(), or call publish() inside Database::transaction().',
            fn () => self::actor($this->db)->publish(['actor_id' => 'u1']),
        );

        self::assertSame(self::NO_ACTOR, self::rawSettings($this->db));
    }

    public function testAPublicationInAFailedSavepointIsGoneForTheRestOfTheTransaction(): void
    {
        $failure = new RuntimeException();

        $seen = $this->db->transaction(static function (Database $db) use ($failure): array {
            try {
                $db->transaction(static function (Database $db) use ($failure): never {
                    self::actor($db)->publish(['actor_id' => 'u1']);

                    throw $failure;
                });
            } catch (RuntimeException $e) {
                self::assertSame($failure, $e);
            }

            return self::published($db);
        });

        self::assertSame(self::NO_ACTOR, $seen);
    }

    public function testAPublicationInASavepointLastsUntilTheOuterTransactionEnds(): void
    {
        $seen = $this->db->transaction(static function (Database $db): array {
            $db->transaction(static fn (Database $db) => self::actor($db)->publish(['actor_id' => 'u1']));

            return self::published($db);
        });

        self::assertSame(['actor_id' => 'u1', 'org_id' => null], $seen);
        self::assertSame(self::NO_ACTOR, self::published($this->db));
    }

    /**
     * @param list<mixed> $keys
     */
    #[DataProvider('invalidDeclarations')]
    public function testAnInvalidDeclarationIsRefused(string $prefix, array $keys, string $message): void
    {
        self::assertRefused($message, fn () => new DatabaseActor($this->db, $prefix, $keys));
    }

    /**
     * @return iterable<string, array{string, list<mixed>, string}>
     */
    public static function invalidDeclarations(): iterable
    {
        $tooLong = str_repeat('a', 64);

        yield 'uppercase prefix' => ['App', ['actor_id'], 'The actor prefix "App" is not a valid setting namespace: ' . self::NAME_RULE];
        yield 'empty prefix' => ['', ['actor_id'], 'The actor prefix "" is not a valid setting namespace: ' . self::NAME_RULE];
        yield 'dotted key' => ['app', ['actor.id'], 'The actor key "actor.id" is not a valid setting name: ' . self::NAME_RULE];
        yield 'hyphenated key' => ['app', ['actor-id'], 'The actor key "actor-id" is not a valid setting name: ' . self::NAME_RULE];
        yield 'key starting with a digit' => ['app', ['1actor'], 'The actor key "1actor" is not a valid setting name: ' . self::NAME_RULE];
        yield 'key of 64 characters' => ['app', [$tooLong], "The actor key \"{$tooLong}\" is not a valid setting name: " . self::NAME_RULE];
        yield 'key declared twice' => ['app', ['actor_id', 'org_id', 'actor_id'], 'The actor key "actor_id" is declared twice.'];
        yield 'key ending with a newline' => ['app', ["actor_id\n"], 'The actor key "actor_id\\n" is not a valid setting name: ' . self::NAME_RULE];
        yield 'prefix ending with a newline' => ["app\n", ['actor_id'], 'The actor prefix "app\\n" is not a valid setting namespace: ' . self::NAME_RULE];
        yield 'key that is not a string' => ['app', [42], 'The actor key 42 is not a valid setting name: ' . self::NAME_RULE];
        yield 'no key' => ['app', [], 'A DatabaseActor needs at least one key.'];
    }

    public function testAPrefixAndAKeyOf63CharactersArePublished(): void
    {
        $prefix = str_repeat('p', 63);
        $key = str_repeat('k', 63);

        $seen = (new DatabaseActor($this->db, $prefix, [$key]))->actAs(
            [$key => 'u1'],
            static fn (Database $db): mixed => $db->selectValue('SELECT current_setting(?)', ["{$prefix}.{$key}"]),
        );

        self::assertSame('u1', $seen);
    }

    /**
     * @param array<mixed> $values
     */
    #[DataProvider('invalidPublications')]
    public function testAnInvalidPublicationIsRefusedAndPublishesNothing(array $values, string $message): void
    {
        /** @var array<string, string> $values */
        self::assertRefused($message, fn () => self::actor($this->db)->actAs($values, static fn (): bool => true));

        self::assertFalse($this->db->inTransaction());
        self::assertSame(self::NO_ACTOR, self::rawSettings($this->db));
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalidPublications(): iterable
    {
        $castAdvice = 'The actor value for "org_id" must be a string, %s given: cast it first, such as (string) $userId.';

        yield 'no value' => [[], 'An actor publication needs at least one value.'];
        yield 'empty string' => [
            ['actor_id' => 'u1', 'org_id' => ''],
            'The actor value for "org_id" must be a non-empty string, an empty string given: an empty value reads as '
            . 'no actor, so leave the key out instead.',
        ];
        yield 'null' => [
            ['actor_id' => 'u1', 'org_id' => null],
            'The actor value for "org_id" must be a string, null given: leave the key out to publish no value for it.',
        ];
        yield 'boolean' => [
            ['actor_id' => 'u1', 'org_id' => true],
            'The actor value for "org_id" must be a string, bool given.',
        ];
        yield 'integer' => [['actor_id' => 'u1', 'org_id' => 42], sprintf($castAdvice, 'int')];
        yield 'float' => [['actor_id' => 'u1', 'org_id' => 1.5], sprintf($castAdvice, 'float')];
        yield 'undeclared key' => [
            ['actor_id' => 'u1', 'team_id' => 't1'],
            'The actor values key "team_id" names no declared key (declared: "actor_id", "org_id").',
        ];
    }

    public function testAPublishedValueStaysOutOfTheArgumentsOfTheTrace(): void
    {
        $this->db->query("SELECT set_config('app.org_id', 'leftover', false)");

        self::assertSecretStaysOutOfTheTrace(
            'value-kept-out-of-traces',
            fn () => self::actor($this->db)->actAs(['actor_id' => 'value-kept-out-of-traces'], static fn (): bool => true),
        );
    }

    public function testAValueRefusedBesideAValidOneStaysOutOfTheArgumentsOfTheTrace(): void
    {
        self::assertSecretStaysOutOfTheTrace(
            'valid-secret-beside-the-refusal',
            fn () => self::actor($this->db)->actAs(
                ['actor_id' => 'valid-secret-beside-the-refusal', 'org_id' => ''],
                static fn (): bool => true,
            ),
        );
    }

    public function testASecondPublicationStaysOutOfTheArgumentsOfTheTrace(): void
    {
        $this->db->transaction(function (Database $db): void {
            $actor = self::actor($db);
            $actor->publish(['actor_id' => 'first']);

            self::assertSecretStaysOutOfTheTrace(
                'second-secret-kept-out-of-traces',
                static fn () => $actor->publish(['actor_id' => 'second-secret-kept-out-of-traces']),
            );
        });
    }

    private static function assertSecretStaysOutOfTheTrace(string $secret, callable $call): void
    {
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');

        try {
            $call();
            self::fail('expected a refusal');
        } catch (DatabaseActorException $e) {
            $frames = array_filter(
                $e->getTrace(),
                static fn (array $frame): bool => str_starts_with($frame['class'] ?? '', 'Zephyrus\\Data\\'),
            );
            self::assertNotSame([], $frames);
            self::assertStringNotContainsString($secret, print_r(array_column($frames, 'args'), true));
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
        }
    }

    private static function actor(Database $db): DatabaseActor
    {
        return new DatabaseActor($db, 'app', ['actor_id', 'org_id']);
    }

    private static function assertRefused(string $message, callable $call): void
    {
        try {
            $call();
            self::fail('expected a refusal');
        } catch (DatabaseActorException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    /**
     * The actor as a policy reads it.
     *
     * @return array<string, mixed>
     */
    private static function published(Database $db): array
    {
        return (array) $db->selectOne(
            "SELECT nullif(current_setting('app.actor_id', true), '') AS actor_id,
                    nullif(current_setting('app.org_id', true), '') AS org_id",
        );
    }

    /**
     * The settings as they are: NULL until something sets them on the connection.
     *
     * @return array<string, mixed>
     */
    private static function rawSettings(Database $db): array
    {
        return (array) $db->selectOne(
            "SELECT current_setting('app.actor_id', true) AS actor_id, current_setting('app.org_id', true) AS org_id",
        );
    }
}
