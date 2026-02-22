<?php

declare(strict_types=1);

namespace Zephyrus\Event;

/**
 * Base class for all application events.
 *
 * Extend this class to carry domain-specific data from the publisher to its
 * listeners.  Call stopPropagation() from inside a listener to prevent any
 * remaining (lower-priority) listeners from being invoked.
 *
 * Example:
 *
 *   class UserRegisteredEvent extends Event
 *   {
 *       public function __construct(
 *           public readonly int    $userId,
 *           public readonly string $email,
 *       ) {}
 *   }
 *
 *   $dispatcher->dispatch(new UserRegisteredEvent(42, 'alice@example.com'));
 */
class Event
{
    private bool $propagationStopped = false;

    /**
     * Return true when a listener has halted propagation for this event.
     */
    final public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    /**
     * Halt propagation so that no further listeners are invoked.
     *
     * Calling this from within a listener means any listeners registered with
     * a lower priority will not receive the event.
     */
    final public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }
}
