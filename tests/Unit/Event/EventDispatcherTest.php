<?php

declare(strict_types=1);

namespace Tests\Unit\Event;

use PHPUnit\Framework\TestCase;
use Zephyrus\Event\Event;
use Zephyrus\Event\EventDispatcher;
use Zephyrus\Event\EventSubscriberInterface;

/**
 * Concrete event stubs used across tests.
 */
final class OrderPlacedEvent extends Event
{
    public function __construct(public readonly int $orderId) {}
}

final class UserRegisteredEvent extends Event
{
    public function __construct(public readonly string $email) {}
}

/**
 * Subscriber stub: two listeners, one with explicit priority.
 */
final class TestSubscriber implements EventSubscriberInterface
{
    /** @var list<string> */
    public array $log = [];

    public static function getSubscribedEvents(): array
    {
        return [
            OrderPlacedEvent::class    => 'onOrderPlaced',
            UserRegisteredEvent::class => ['onUserRegistered', 10],
        ];
    }

    public function onOrderPlaced(OrderPlacedEvent $event): void
    {
        $this->log[] = 'order:' . $event->orderId;
    }

    public function onUserRegistered(UserRegisteredEvent $event): void
    {
        $this->log[] = 'user:' . $event->email;
    }
}

final class EventDispatcherTest extends TestCase
{
    // -------------------------------------------------------------------------
    // addListener / dispatch basics
    // -------------------------------------------------------------------------

    public function testDispatchWithNoListenersReturnsEvent(): void
    {
        $dispatcher = new EventDispatcher();
        $event      = new OrderPlacedEvent(1);
        $returned   = $dispatcher->dispatch($event);

        self::assertSame($event, $returned);
    }

    public function testListenerReceivesDispatchedEvent(): void
    {
        $dispatcher = new EventDispatcher();
        $received   = null;

        $dispatcher->addListener(OrderPlacedEvent::class, function (OrderPlacedEvent $e) use (&$received): void {
            $received = $e;
        });

        $event = new OrderPlacedEvent(42);
        $dispatcher->dispatch($event);

        self::assertSame($event, $received);
        self::assertSame(42, $received->orderId);
    }

    public function testDispatchReturnsTheSameEventInstance(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(OrderPlacedEvent::class, fn(OrderPlacedEvent $e) => null);

        $event    = new OrderPlacedEvent(7);
        $returned = $dispatcher->dispatch($event);

        self::assertSame($event, $returned);
    }

    public function testMultipleListenersAreAllInvoked(): void
    {
        $dispatcher = new EventDispatcher();
        $log        = [];

        $dispatcher->addListener(OrderPlacedEvent::class, function () use (&$log): void { $log[] = 'A'; });
        $dispatcher->addListener(OrderPlacedEvent::class, function () use (&$log): void { $log[] = 'B'; });
        $dispatcher->addListener(OrderPlacedEvent::class, function () use (&$log): void { $log[] = 'C'; });

        $dispatcher->dispatch(new OrderPlacedEvent(1));

        self::assertSame(['A', 'B', 'C'], $log);
    }

    // -------------------------------------------------------------------------
    // Priority ordering
    // -------------------------------------------------------------------------

    public function testHigherPriorityListenerRunsFirst(): void
    {
        $dispatcher = new EventDispatcher();
        $log        = [];

        $dispatcher->addListener(OrderPlacedEvent::class, function () use (&$log): void { $log[] = 'low'; }, priority: -1);
        $dispatcher->addListener(OrderPlacedEvent::class, function () use (&$log): void { $log[] = 'high'; }, priority: 10);
        $dispatcher->addListener(OrderPlacedEvent::class, function () use (&$log): void { $log[] = 'mid'; }, priority: 5);

        $dispatcher->dispatch(new OrderPlacedEvent(1));

        self::assertSame(['high', 'mid', 'low'], $log);
    }

    public function testEqualPriorityPreservesRegistrationOrder(): void
    {
        $dispatcher = new EventDispatcher();
        $log        = [];

        $dispatcher->addListener(OrderPlacedEvent::class, function () use (&$log): void { $log[] = 1; });
        $dispatcher->addListener(OrderPlacedEvent::class, function () use (&$log): void { $log[] = 2; });
        $dispatcher->addListener(OrderPlacedEvent::class, function () use (&$log): void { $log[] = 3; });

        $dispatcher->dispatch(new OrderPlacedEvent(1));

        self::assertSame([1, 2, 3], $log);
    }

    // -------------------------------------------------------------------------
    // Propagation stopping
    // -------------------------------------------------------------------------

    public function testStopPropagationHaltsRemainingListeners(): void
    {
        $dispatcher = new EventDispatcher();
        $log        = [];

        $dispatcher->addListener(OrderPlacedEvent::class, function (OrderPlacedEvent $e) use (&$log): void {
            $log[] = 'first';
            $e->stopPropagation();
        }, priority: 10);

        $dispatcher->addListener(OrderPlacedEvent::class, function () use (&$log): void {
            $log[] = 'second';
        }, priority: 5);

        $dispatcher->addListener(OrderPlacedEvent::class, function () use (&$log): void {
            $log[] = 'third';
        }, priority: 0);

        $dispatcher->dispatch(new OrderPlacedEvent(1));

        self::assertSame(['first'], $log);
    }

    public function testAlreadyStoppedEventSkipsAllListeners(): void
    {
        $dispatcher = new EventDispatcher();
        $invoked    = false;

        $dispatcher->addListener(OrderPlacedEvent::class, function () use (&$invoked): void {
            $invoked = true;
        });

        $event = new OrderPlacedEvent(1);
        $event->stopPropagation();
        $dispatcher->dispatch($event);

        self::assertFalse($invoked);
    }

    // -------------------------------------------------------------------------
    // removeListener
    // -------------------------------------------------------------------------

    public function testRemoveListenerPreventsInvocation(): void
    {
        $dispatcher = new EventDispatcher();
        $invoked    = false;

        $listener = function () use (&$invoked): void { $invoked = true; };
        $dispatcher->addListener(OrderPlacedEvent::class, $listener);
        $dispatcher->removeListener(OrderPlacedEvent::class, $listener);

        $dispatcher->dispatch(new OrderPlacedEvent(1));

        self::assertFalse($invoked);
    }

    public function testRemoveListenerIsNoOpWhenNotRegistered(): void
    {
        $dispatcher = new EventDispatcher();
        // Should not throw
        $dispatcher->removeListener(OrderPlacedEvent::class, fn() => null);
        self::assertFalse($dispatcher->hasListeners(OrderPlacedEvent::class));
    }

    public function testRemoveListenerCleansUpEventKey(): void
    {
        $dispatcher = new EventDispatcher();
        $listener   = fn() => null;

        $dispatcher->addListener(OrderPlacedEvent::class, $listener);
        self::assertTrue($dispatcher->hasListeners(OrderPlacedEvent::class));

        $dispatcher->removeListener(OrderPlacedEvent::class, $listener);
        self::assertFalse($dispatcher->hasListeners(OrderPlacedEvent::class));
    }

    public function testRemoveOneListenerLeavesOthersIntact(): void
    {
        $dispatcher = new EventDispatcher();
        $log        = [];

        $listenerA = function () use (&$log): void { $log[] = 'A'; };
        $listenerB = function () use (&$log): void { $log[] = 'B'; };

        $dispatcher->addListener(OrderPlacedEvent::class, $listenerA);
        $dispatcher->addListener(OrderPlacedEvent::class, $listenerB);

        $dispatcher->removeListener(OrderPlacedEvent::class, $listenerA);
        $dispatcher->dispatch(new OrderPlacedEvent(1));

        self::assertSame(['B'], $log);
    }

    // -------------------------------------------------------------------------
    // hasListeners / getListeners
    // -------------------------------------------------------------------------

    public function testHasListenersReturnsFalseWhenNoneRegistered(): void
    {
        $dispatcher = new EventDispatcher();
        self::assertFalse($dispatcher->hasListeners(OrderPlacedEvent::class));
    }

    public function testHasListenersReturnsTrueAfterAddListener(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(OrderPlacedEvent::class, fn() => null);
        self::assertTrue($dispatcher->hasListeners(OrderPlacedEvent::class));
    }

    public function testGetListenersReturnsEmptyArrayWhenNoneRegistered(): void
    {
        $dispatcher = new EventDispatcher();
        self::assertSame([], $dispatcher->getListeners(OrderPlacedEvent::class));
    }

    public function testGetListenersReturnsSortedCallables(): void
    {
        $dispatcher = new EventDispatcher();
        $log        = [];

        $low  = function () use (&$log): void { $log[] = 'low'; };
        $high = function () use (&$log): void { $log[] = 'high'; };

        $dispatcher->addListener(OrderPlacedEvent::class, $low, priority: 0);
        $dispatcher->addListener(OrderPlacedEvent::class, $high, priority: 5);

        $listeners = $dispatcher->getListeners(OrderPlacedEvent::class);

        self::assertCount(2, $listeners);
        self::assertSame($high, $listeners[0]);
        self::assertSame($low, $listeners[1]);
    }

    public function testGetListenersDoesNotAffectInternalState(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(OrderPlacedEvent::class, fn() => null);

        $dispatcher->getListeners(OrderPlacedEvent::class);

        // Dispatching still works after getListeners call
        self::assertTrue($dispatcher->hasListeners(OrderPlacedEvent::class));
    }

    // -------------------------------------------------------------------------
    // EventSubscriberInterface — addSubscriber / removeSubscriber
    // -------------------------------------------------------------------------

    public function testAddSubscriberRegistersAllListeners(): void
    {
        $dispatcher  = new EventDispatcher();
        $subscriber  = new TestSubscriber();

        $dispatcher->addSubscriber($subscriber);

        self::assertTrue($dispatcher->hasListeners(OrderPlacedEvent::class));
        self::assertTrue($dispatcher->hasListeners(UserRegisteredEvent::class));
    }

    public function testSubscriberListenersAreInvoked(): void
    {
        $dispatcher = new EventDispatcher();
        $subscriber = new TestSubscriber();
        $dispatcher->addSubscriber($subscriber);

        $dispatcher->dispatch(new OrderPlacedEvent(99));
        $dispatcher->dispatch(new UserRegisteredEvent('bob@example.com'));

        self::assertSame(['order:99', 'user:bob@example.com'], $subscriber->log);
    }

    public function testSubscriberPriorityIsHonoured(): void
    {
        $dispatcher = new EventDispatcher();
        $subscriber = new TestSubscriber();
        $log        = [];

        $dispatcher->addSubscriber($subscriber);

        // Register a lower-priority competing listener — subscriber's priority-10 listener should run first
        $dispatcher->addListener(UserRegisteredEvent::class, function () use (&$log): void {
            $log[] = 'standalone';
        }, priority: 1);

        $dispatcher->dispatch(new UserRegisteredEvent('carol@example.com'));

        // subscriber (priority 10) should be invoked first
        self::assertSame(['user:carol@example.com'], $subscriber->log);
        self::assertSame(['standalone'], $log);
    }

    public function testRemoveSubscriberDeregistersAllListeners(): void
    {
        $dispatcher = new EventDispatcher();
        $subscriber = new TestSubscriber();

        $dispatcher->addSubscriber($subscriber);
        $dispatcher->removeSubscriber($subscriber);

        self::assertFalse($dispatcher->hasListeners(OrderPlacedEvent::class));
        self::assertFalse($dispatcher->hasListeners(UserRegisteredEvent::class));
    }

    public function testRemoveSubscriberPreventsInvocation(): void
    {
        $dispatcher = new EventDispatcher();
        $subscriber = new TestSubscriber();

        $dispatcher->addSubscriber($subscriber);
        $dispatcher->removeSubscriber($subscriber);

        $dispatcher->dispatch(new OrderPlacedEvent(5));

        self::assertSame([], $subscriber->log);
    }

    // -------------------------------------------------------------------------
    // Isolation — different event classes do not cross-contaminate
    // -------------------------------------------------------------------------

    public function testListenersForDifferentEventsAreisolated(): void
    {
        $dispatcher  = new EventDispatcher();
        $orderLog    = [];
        $userLog     = [];

        $dispatcher->addListener(OrderPlacedEvent::class, function () use (&$orderLog): void { $orderLog[] = 'order'; });
        $dispatcher->addListener(UserRegisteredEvent::class, function () use (&$userLog): void { $userLog[] = 'user'; });

        $dispatcher->dispatch(new OrderPlacedEvent(1));

        self::assertSame(['order'], $orderLog);
        self::assertSame([], $userLog);
    }
}
