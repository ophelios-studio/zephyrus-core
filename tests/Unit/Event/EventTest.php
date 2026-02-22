<?php

declare(strict_types=1);

namespace Tests\Unit\Event;

use PHPUnit\Framework\TestCase;
use Zephyrus\Event\Event;

final class EventTest extends TestCase
{
    public function testPropagationNotStoppedByDefault(): void
    {
        $event = new Event();
        self::assertFalse($event->isPropagationStopped());
    }

    public function testStopPropagationSetsFlagTrue(): void
    {
        $event = new Event();
        $event->stopPropagation();
        self::assertTrue($event->isPropagationStopped());
    }

    public function testStopPropagationIsIdempotent(): void
    {
        $event = new Event();
        $event->stopPropagation();
        $event->stopPropagation();
        self::assertTrue($event->isPropagationStopped());
    }

    public function testSubclassInheritsPropagationBehaviour(): void
    {
        $event = new class extends Event {
            public string $payload = 'hello';
        };

        self::assertFalse($event->isPropagationStopped());
        $event->stopPropagation();
        self::assertTrue($event->isPropagationStopped());
        self::assertSame('hello', $event->payload);
    }
}
