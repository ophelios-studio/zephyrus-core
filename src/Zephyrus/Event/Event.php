<?php

declare(strict_types=1);

namespace Zephyrus\Event;

/**
 * Base class for application events, carrying data from the publisher to its listeners.
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
     * Return true when a listener has stopped propagation.
     */
    final public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    /**
     * Skip every remaining listener, whatever its priority.
     *
     * The flag is never reset: dispatch a new event instance rather than re-dispatching this one.
     */
    final public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }
}
