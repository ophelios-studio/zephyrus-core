<?php

declare(strict_types=1);

namespace Zephyrus\Event;

/**
 * Synchronous, priority-ordered event dispatcher.
 *
 * Listeners receive events in descending priority order (higher priority =
 * invoked first).  Ties are broken by registration order (first registered,
 * first called).  Any listener may halt propagation by calling
 * $event->stopPropagation().
 *
 * ## Basic usage
 *
 *   $dispatcher = new EventDispatcher();
 *
 *   $dispatcher->addListener(OrderPlacedEvent::class, function (OrderPlacedEvent $e): void {
 *       // handle order
 *   });
 *
 *   $event = $dispatcher->dispatch(new OrderPlacedEvent($order));
 *
 * ## Subscriber usage
 *
 *   $dispatcher->addSubscriber(new NotificationSubscriber());
 *   // All listeners declared in NotificationSubscriber::getSubscribedEvents()
 *   // are registered automatically.
 */
final class EventDispatcher
{
    /**
     * Raw listener registry: eventClass → list of [callable, priority].
     *
     * Entries are stored in registration order; sorting happens on dispatch.
     *
     * @var array<class-string<Event>, list<array{0: callable, 1: int}>>
     */
    private array $listeners = [];

    // -------------------------------------------------------------------------
    // Listener management
    // -------------------------------------------------------------------------

    /**
     * Register a listener callable for the given event class.
     *
     * @param class-string<Event> $eventClass FQCN of the event.
     * @param callable            $listener   Called with the event as its sole argument.
     * @param int                 $priority   Higher values run first.  Default 0.
     */
    public function addListener(string $eventClass, callable $listener, int $priority = 0): void
    {
        $this->listeners[$eventClass][] = [$listener, $priority];
    }

    /**
     * Deregister a specific listener.  No-op when not found.
     *
     * @param class-string<Event> $eventClass
     */
    public function removeListener(string $eventClass, callable $listener): void
    {
        if (!isset($this->listeners[$eventClass])) {
            return;
        }

        $this->listeners[$eventClass] = array_values(
            array_filter(
                $this->listeners[$eventClass],
                static fn(array $entry): bool => $entry[0] !== $listener,
            )
        );

        if (empty($this->listeners[$eventClass])) {
            unset($this->listeners[$eventClass]);
        }
    }

    /**
     * Register all listeners declared by an EventSubscriberInterface instance.
     *
     * Each entry returned by getSubscribedEvents() is converted to an
     * addListener() call on this dispatcher.
     */
    public function addSubscriber(EventSubscriberInterface $subscriber): void
    {
        foreach ($subscriber::getSubscribedEvents() as $eventClass => $spec) {
            if (is_string($spec)) {
                $this->addListener($eventClass, [$subscriber, $spec]);
            } else {
                [$method, $priority] = $spec;
                $this->addListener($eventClass, [$subscriber, $method], $priority);
            }
        }
    }

    /**
     * Deregister all listeners belonging to a subscriber.
     */
    public function removeSubscriber(EventSubscriberInterface $subscriber): void
    {
        foreach ($subscriber::getSubscribedEvents() as $eventClass => $spec) {
            $method = is_string($spec) ? $spec : $spec[0];
            $this->removeListener($eventClass, [$subscriber, $method]);
        }
    }

    // -------------------------------------------------------------------------
    // Dispatch
    // -------------------------------------------------------------------------

    /**
     * Dispatch an event to all registered listeners and return it.
     *
     * Listeners are called in descending priority order.  If
     * $event->isPropagationStopped() is true before or between listeners,
     * the remaining listeners are skipped.
     *
     * @template T of Event
     * @param  T $event
     * @return T
     */
    public function dispatch(Event $event): Event
    {
        $eventClass = $event::class;

        if (!isset($this->listeners[$eventClass])) {
            return $event;
        }

        foreach ($this->sortedListeners($eventClass) as $listener) {
            if ($event->isPropagationStopped()) {
                break;
            }
            $listener($event);
        }

        return $event;
    }

    // -------------------------------------------------------------------------
    // Introspection
    // -------------------------------------------------------------------------

    /**
     * Return true when at least one listener is registered for $eventClass.
     *
     * @param class-string<Event> $eventClass
     */
    public function hasListeners(string $eventClass): bool
    {
        return !empty($this->listeners[$eventClass]);
    }

    /**
     * Return all listeners for $eventClass in dispatch order (highest priority first).
     *
     * @param  class-string<Event> $eventClass
     * @return list<callable>
     */
    public function getListeners(string $eventClass): array
    {
        if (!isset($this->listeners[$eventClass])) {
            return [];
        }

        return $this->sortedListeners($eventClass);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Return the listener callables for $eventClass sorted by descending priority.
     *
     * @param  class-string<Event> $eventClass
     * @return list<callable>
     */
    private function sortedListeners(string $eventClass): array
    {
        $entries = $this->listeners[$eventClass];

        usort($entries, static fn(array $a, array $b): int => $b[1] <=> $a[1]);

        return array_column($entries, 0);
    }
}
