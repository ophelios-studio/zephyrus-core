<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Event;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Zephyrus\Event\Event;
use Zephyrus\Event\EventDispatcher;

interface AuditableEvent
{
}

class BaseOrderEvent extends Event
{
}

class SpecialisedOrderEvent extends BaseOrderEvent implements AuditableEvent
{
}

/**
 * Dispatch keyed strictly on $event::class, so a subclass of a framework event
 * ran NONE of the parent's listeners.
 *
 * Subclassing a framework event to carry extra data is the normal thing to do,
 * and doing it silently disabled every listener already watching the parent.
 * An authorisation or audit listener registered on RequestEvent simply stopped
 * running the day somebody introduced ApiRequestEvent extends RequestEvent, and
 * nothing anywhere reported it.
 */
final class EventDispatcherInheritanceTest extends TestCase
{
    public function testAParentListenerRunsForASubclassEvent(): void
    {
        $seen = [];

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(BaseOrderEvent::class, static function () use (&$seen): void {
            $seen[] = 'parent';
        });

        $dispatcher->dispatch(new SpecialisedOrderEvent());

        self::assertSame(['parent'], $seen);
    }

    public function testAnInterfaceListenerRunsForAnImplementingEvent(): void
    {
        $seen = [];

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(AuditableEvent::class, static function () use (&$seen): void {
            $seen[] = 'audit';
        });

        $dispatcher->dispatch(new SpecialisedOrderEvent());

        self::assertSame(['audit'], $seen);
    }

    public function testAListenerOnTheEventBaseClassSeesEverything(): void
    {
        $seen = 0;

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(Event::class, static function () use (&$seen): void {
            $seen++;
        });

        $dispatcher->dispatch(new BaseOrderEvent());
        $dispatcher->dispatch(new SpecialisedOrderEvent());

        self::assertSame(2, $seen);
    }

    public function testTheExactClassAndItsAncestorsBothRun(): void
    {
        $seen = [];

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(BaseOrderEvent::class, static function () use (&$seen): void {
            $seen[] = 'parent';
        });
        $dispatcher->addListener(SpecialisedOrderEvent::class, static function () use (&$seen): void {
            $seen[] = 'exact';
        });

        $dispatcher->dispatch(new SpecialisedOrderEvent());

        // Equal priority: the exact class contributes first.
        self::assertSame(['exact', 'parent'], $seen);
    }

    public function testPriorityStillWinsAcrossTheHierarchy(): void
    {
        $seen = [];

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(SpecialisedOrderEvent::class, static function () use (&$seen): void {
            $seen[] = 'exact-low';
        }, priority: -5);
        $dispatcher->addListener(BaseOrderEvent::class, static function () use (&$seen): void {
            $seen[] = 'parent-high';
        }, priority: 10);

        $dispatcher->dispatch(new SpecialisedOrderEvent());

        self::assertSame(['parent-high', 'exact-low'], $seen);
    }

    public function testStopPropagationStillHaltsAcrossTheHierarchy(): void
    {
        $seen = [];

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(SpecialisedOrderEvent::class, static function (Event $e) use (&$seen): void {
            $seen[] = 'exact';
            $e->stopPropagation();
        });
        $dispatcher->addListener(BaseOrderEvent::class, static function () use (&$seen): void {
            $seen[] = 'parent';
        });

        $dispatcher->dispatch(new SpecialisedOrderEvent());

        self::assertSame(['exact'], $seen);
    }

    public function testAParentEventDoesNotRunItsSubclassListeners(): void
    {
        $seen = [];

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(SpecialisedOrderEvent::class, static function () use (&$seen): void {
            $seen[] = 'child';
        });

        $dispatcher->dispatch(new BaseOrderEvent());

        self::assertSame([], $seen);
    }

    // -- introspection -------------------------------------------------------

    public function testGetListenersStaysExactSoItMirrorsRemoveListener(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(BaseOrderEvent::class, static fn(): null => null);

        // A caller enumerating listeners in order to remove them must not be
        // handed listeners removeListener(SpecialisedOrderEvent::class, ...)
        // could never remove.
        self::assertCount(0, $dispatcher->getListeners(SpecialisedOrderEvent::class));
        self::assertFalse($dispatcher->hasListeners(SpecialisedOrderEvent::class));
    }

    public function testApplicableListenersIsTheHonestAnswerToWhatWillRun(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(BaseOrderEvent::class, static fn(): null => null);
        $dispatcher->addListener(SpecialisedOrderEvent::class, static fn(): null => null);

        self::assertCount(2, $dispatcher->applicableListeners(SpecialisedOrderEvent::class));
        self::assertCount(1, $dispatcher->applicableListeners(BaseOrderEvent::class));
    }

    // -- per-listener isolation ---------------------------------------------

    /**
     * The default is unchanged on purpose: a throwing listener propagates, and
     * a failure stays a failure. What is new is the seam a reporting caller can
     * pass so one broken reporter no longer cancels the others.
     */
    public function testAThrowingListenerStillPropagatesByDefault(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(BaseOrderEvent::class, static function (): void {
            throw new RuntimeException('broken');
        });

        $this->expectException(RuntimeException::class);
        $dispatcher->dispatch(new BaseOrderEvent());
    }

    public function testAnErrorHandlerIsolatesEachListenerFromTheNext(): void
    {
        $seen = [];
        $failures = [];

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(BaseOrderEvent::class, static function (): void {
            throw new RuntimeException('reporter one is broken');
        }, priority: 10);
        $dispatcher->addListener(BaseOrderEvent::class, static function () use (&$seen): void {
            $seen[] = 'reporter-two';
        }, priority: 0);

        $dispatcher->dispatch(
            new BaseOrderEvent(),
            static function (\Throwable $failure) use (&$failures): void {
                $failures[] = $failure->getMessage();
            },
        );

        self::assertSame(['reporter-two'], $seen);
        self::assertSame(['reporter one is broken'], $failures);
    }

    public function testTheErrorHandlerReceivesTheEventItWasDispatchedFor(): void
    {
        $received = null;
        $event = new BaseOrderEvent();

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(BaseOrderEvent::class, static function (): void {
            throw new RuntimeException('boom');
        });

        $dispatcher->dispatch($event, static function (\Throwable $t, Event $e) use (&$received): void {
            $received = $e;
        });

        self::assertSame($event, $received);
    }
}
