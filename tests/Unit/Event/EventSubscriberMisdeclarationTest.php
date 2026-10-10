<?php

declare(strict_types=1);

namespace Tests\Unit\Event;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Event\Event;
use Zephyrus\Event\EventDispatcher;
use Zephyrus\Event\EventSubscriberInterface;

final class MisdeclaredEvent extends Event
{
}

final class MisdeclaredOtherEvent extends Event
{
}

final class ConfigurableSubscriber implements EventSubscriberInterface
{
    /** @var array<string, mixed> */
    public static array $events = [];

    public static function getSubscribedEvents(): array
    {
        return self::$events;
    }

    public function onEvent(Event $event): void
    {
    }

    public static function onStatic(Event $event): void
    {
    }

    private function hidden(Event $event): void
    {
    }
}

final class MagicCallSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [MisdeclaredEvent::class => 'anythingHandledByCall'];
    }

    /**
     * @param array<int, mixed> $arguments
     */
    public function __call(string $name, array $arguments): void
    {
    }
}

final class EventSubscriberMisdeclarationTest extends TestCase
{
    private const SUBSCRIBER = ConfigurableSubscriber::class;

    /**
     * @param array<array-key, mixed> $events
     */
    private function subscribe(array $events, ?EventDispatcher $dispatcher = null): EventDispatcher
    {
        ConfigurableSubscriber::$events = $events;
        $dispatcher ??= new EventDispatcher();
        $dispatcher->addSubscriber(new ConfigurableSubscriber());

        return $dispatcher;
    }

    /**
     * @param array<array-key, mixed> $events
     */
    private function refusalOf(array $events): string
    {
        try {
            $this->subscribe($events);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        self::fail('Expected an InvalidArgumentException.');
    }

    public function testAMissingMethodIsRefusedWithItsFullMessage(): void
    {
        self::assertSame(
            'Subscriber ' . self::SUBSCRIBER . ' declares the listener "ghost" for ' . MisdeclaredEvent::class
            . ', but ' . self::SUBSCRIBER . ' has no public method "ghost".',
            $this->refusalOf([MisdeclaredEvent::class => 'ghost']),
        );
    }

    public function testAPrivateMethodReadsAsMissing(): void
    {
        self::assertSame(
            'Subscriber ' . self::SUBSCRIBER . ' declares the listener "hidden" for ' . MisdeclaredEvent::class
            . ', but ' . self::SUBSCRIBER . ' has no public method "hidden".',
            $this->refusalOf([MisdeclaredEvent::class => ['hidden', 3]]),
        );
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function malformedSpecs(): array
    {
        return [
            'int method' => [[123, 0], '[123, 0]'],
            'null method' => [[null, 0], '[null, 0]'],
            'empty array' => [[], '[]'],
            'three items' => [['onEvent', 0, 1], '["onEvent", 0, 1]'],
            'four items' => [['a', 1, 'x', 'y'], '["a", 1, "x", ...]'],
            'string priority' => [['onEvent', 'high'], '["onEvent", "high"]'],
            'null priority' => [['onEvent', null], '["onEvent", null]'],
            'null spec' => [null, 'null'],
            'bool spec' => [true, 'true'],
            'int spec' => [5, '5'],
            'keyed array' => [['method' => 'onEvent'], 'array'],
            'nested list' => [[['onEvent', 1], ['onEvent', 2]], '[array, array]'],
        ];
    }

    #[DataProvider('malformedSpecs')]
    public function testAMalformedEntryIsRefusedNamingTheSubscriberAndTheEvent(mixed $spec, string $shown): void
    {
        self::assertSame(
            'Subscriber ' . self::SUBSCRIBER . ' declares an invalid listener for ' . MisdeclaredEvent::class
            . ': expected "method", ["method"] or ["method", priority], got ' . $shown . '.',
            $this->refusalOf([MisdeclaredEvent::class => $spec]),
        );
    }

    public function testNothingIsRegisteredWhenALaterEntryIsRefused(): void
    {
        $dispatcher = new EventDispatcher();

        try {
            $this->subscribe([
                MisdeclaredEvent::class => 'onEvent',
                MisdeclaredOtherEvent::class => 'ghost',
            ], $dispatcher);
            self::fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException) {
        }

        self::assertFalse($dispatcher->hasListeners(MisdeclaredEvent::class));
        self::assertFalse($dispatcher->hasListeners(MisdeclaredOtherEvent::class));
    }

    public function testAMethodWithoutPriorityRegistersAtPriorityZero(): void
    {
        $dispatcher = new EventDispatcher();
        $higher = static function (): void {
        };
        $lower = static function (): void {
        };
        $dispatcher->addListener(MisdeclaredEvent::class, $lower, -1);
        $this->subscribe([MisdeclaredEvent::class => ['onEvent']], $dispatcher);
        $dispatcher->addListener(MisdeclaredEvent::class, $higher, 1);

        $listeners = $dispatcher->getListeners(MisdeclaredEvent::class);

        self::assertCount(3, $listeners);
        self::assertSame($higher, $listeners[0]);
        self::assertIsArray($listeners[1]);
        self::assertSame('onEvent', $listeners[1][1]);
        self::assertSame($lower, $listeners[2]);
    }

    public function testEveryAcceptedShapeRegisters(): void
    {
        $dispatcher = $this->subscribe([
            MisdeclaredEvent::class => 'onEvent',
            MisdeclaredOtherEvent::class => ['onEvent', 5],
        ]);

        self::assertTrue($dispatcher->hasListeners(MisdeclaredEvent::class));
        self::assertTrue($dispatcher->hasListeners(MisdeclaredOtherEvent::class));
    }

    public function testAStaticMethodStillRegisters(): void
    {
        $dispatcher = $this->subscribe([MisdeclaredEvent::class => 'onStatic']);

        self::assertTrue($dispatcher->hasListeners(MisdeclaredEvent::class));
    }

    public function testAMagicCallMethodStillRegisters(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new MagicCallSubscriber());

        self::assertTrue($dispatcher->hasListeners(MisdeclaredEvent::class));
    }

    public function testRemoveSubscriberStaysQuietOnRefusedEntries(): void
    {
        ConfigurableSubscriber::$events = [
            MisdeclaredEvent::class => 'ghost',
            MisdeclaredOtherEvent::class => [123, 0],
        ];
        $dispatcher = new EventDispatcher();
        $dispatcher->removeSubscriber(new ConfigurableSubscriber());

        self::assertFalse($dispatcher->hasListeners(MisdeclaredEvent::class));
    }

    public function testRemoveSubscriberRemovesAMethodWithoutPriority(): void
    {
        $subscriber = new ConfigurableSubscriber();
        ConfigurableSubscriber::$events = [MisdeclaredEvent::class => ['onEvent']];
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($subscriber);
        $dispatcher->removeSubscriber($subscriber);

        self::assertFalse($dispatcher->hasListeners(MisdeclaredEvent::class));
    }
}
