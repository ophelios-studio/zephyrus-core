<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Data;

use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;
use Zephyrus\Data\Broker;
use Zephyrus\Data\Database;
use Zephyrus\Data\DatabaseException;

/**
 * Debug renderers print the arguments of every frame, so bound values and the
 * driver text must not be readable there.
 */
final class DatabaseSensitiveTraceTest extends TestCase
{
    private string|false $ignoreArgs;

    protected function setUp(): void
    {
        $this->ignoreArgs = ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', (string) $this->ignoreArgs);
    }

    public function testAFailedQueryKeepsItsValuesAndTheDriverTextOutOfTheTrace(): void
    {
        $db = new Database(new PDO('sqlite::memory:'));

        $e = $this->thrownBy(fn () => $db->selectValue('SELECT * FROM missing_table WHERE email = ?', ['jane@example.com']));

        self::assertInstanceOf(DatabaseException::class, $e);
        $this->assertFramesDoNotContain($e, ['jane@example.com', 'no such table']);
    }

    public function testARefusedValueStaysOutOfTheTrace(): void
    {
        $db = new Database(new PDO('sqlite::memory:'));

        $e = $this->thrownBy(fn () => $db->execute('INSERT INTO t VALUES (?)', [['jane@example.com']]));

        self::assertInstanceOf(\InvalidArgumentException::class, $e);
        $this->assertFramesDoNotContain($e, ['jane@example.com']);
    }

    public function testAFailedCommitKeepsTheDriverTextOutOfTheTrace(): void
    {
        $db = new Database(new ScriptedTransactionPdo(['commit' => DatabaseTransactionTest::driverError('23505')]));

        $e = $this->thrownBy(fn () => $db->transaction(fn (): bool => true));

        self::assertInstanceOf(DatabaseException::class, $e);
        $this->assertFramesDoNotContain($e, ['jane@example.com']);
    }

    public function testABrokerKeepsItsBoundValuesOutOfTheTrace(): void
    {
        $broker = new class (new Database(new PDO('sqlite::memory:'))) extends Broker {
            public function find(string $email): mixed
            {
                return $this->selectValue('SELECT * FROM missing_table WHERE email = ?', [$email]);
            }
        };

        $e = $this->thrownBy(fn () => $broker->find('jane@example.com'));

        self::assertInstanceOf(DatabaseException::class, $e);
        $this->assertFramesDoNotContain($e, ['jane@example.com']);
    }

    private function thrownBy(callable $call): Throwable
    {
        try {
            $call();
        } catch (Throwable $e) {
            return $e;
        }

        self::fail('expected an exception');
    }

    /**
     * Only the framework's own frames are inspected: the caller's frames hold
     * whatever the caller passed.
     *
     * @param list<string> $needles
     */
    private function assertFramesDoNotContain(Throwable $e, array $needles): void
    {
        $frames = 0;

        foreach ($e->getTrace() as $frame) {
            $class = $frame['class'] ?? '';

            if (!str_starts_with($class, 'Zephyrus\\Data\\') || str_contains($class, '@anonymous')) {
                continue;
            }

            $frames++;
            $rendered = self::render($frame['args'] ?? []);

            foreach ($needles as $needle) {
                self::assertFalse(
                    str_contains($rendered, $needle),
                    sprintf('%s::%s() exposes "%s" in its arguments', $class, $frame['function'], $needle),
                );
            }
        }

        self::assertGreaterThan(0, $frames);
    }

    private static function render(mixed $value): string
    {
        return match (true) {
            is_array($value) => implode(' ', array_map(self::render(...), $value)),
            $value instanceof Throwable => $value->getMessage(),
            is_object($value) => $value::class,
            is_scalar($value) => (string) $value,
            default => get_debug_type($value),
        };
    }
}
