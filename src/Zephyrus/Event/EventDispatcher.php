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
     * Dispatch an event to every applicable listener and return it.
     *
     * ## Applicable means the hierarchy, not the exact class
     *
     * Matching used to be `$event::class` and nothing else, so a listener
     * registered on RequestEvent did not run for a subclass of RequestEvent.
     * Subclassing a framework event is the normal way to carry extra data, and
     * doing it silently disabled every listener already watching the parent --
     * an audit or authorisation listener among them. Listeners registered on
     * any ancestor class or implemented interface now run too.
     *
     * Ordering is unchanged in spirit: descending priority across the whole
     * collected set, ties broken by registration order (PHP sorts are stable),
     * with the exact class contributing its listeners before its ancestors.
     *
     * ## Listener failures
     *
     * By default a throwing listener propagates immediately, exactly as before:
     * a listener that fails is a real failure, and swallowing it by default
     * would be the silent-success trap this framework keeps removing.
     *
     * $onListenerError is the seam for the callers that genuinely must survive
     * one: pass a reporter and each listener is wrapped individually, so the
     * first failure no longer cancels the ones after it. HttpKernel uses it for
     * ExceptionEvent, where it previously wrapped the ENTIRE dispatch in one
     * try/catch and therefore lost every reporter after the first that threw.
     *
     * @template T of Event
     * @param  T $event
     * @param  callable(\Throwable, Event): void|null $onListenerError
     * @return T
     */
    public function dispatch(Event $event, ?callable $onListenerError = null): Event
    {
        $listeners = $this->applicableListeners($event::class);

        if ($listeners === []) {
            return $event;
        }

        foreach ($listeners as $listener) {
            if ($event->isPropagationStopped()) {
                break;
            }

            if ($onListenerError === null) {
                $listener($event);
                continue;
            }

            try {
                $listener($event);
            } catch (\Throwable $listenerFailure) {
                $onListenerError($listenerFailure, $event);
            }
        }

        return $event;
    }

    // -------------------------------------------------------------------------
    // Introspection
    // -------------------------------------------------------------------------

    /**
     * Return true when at least one listener is registered ON $eventClass itself.
     *
     * Deliberately exact, like getListeners() and removeListener(): this is the
     * registry view. Use applicableListeners() to ask what a dispatch would
     * actually run.
     *
     * @param class-string<Event> $eventClass
     */
    public function hasListeners(string $eventClass): bool
    {
        return !empty($this->listeners[$eventClass]);
    }

    /**
     * Return the listeners registered ON $eventClass, in dispatch order.
     *
     * Exact-class only, so it stays the mirror of addListener() and
     * removeListener(): a caller enumerating listeners in order to remove them
     * must not be handed listeners that belong to a parent class and that
     * removeListener($eventClass, ...) could never remove.
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

    /**
     * Return every listener a dispatch of $eventClass would invoke, in order.
     *
     * This is the honest answer to "what will run": the exact class plus every
     * ancestor class and implemented interface that carries listeners.
     *
     * @param  class-string<Event>|string $eventClass
     * @return list<callable>
     */
    public function applicableListeners(string $eventClass): array
    {
        $entries = [];

        foreach ($this->matchingRegistryKeys($eventClass) as $key) {
            foreach ($this->listeners[$key] as $entry) {
                $entries[] = $entry;
            }
        }

        if ($entries === []) {
            return [];
        }

        // PHP's sort is stable, so equal priorities keep the order built above:
        // the exact class first, then ancestors, each in registration order.
        usort($entries, static fn(array $a, array $b): int => $b[1] <=> $a[1]);

        return array_column($entries, 0);
    }

    /**
     * Registry keys that apply to $eventClass, most specific first.
     *
     * Only keys that actually carry listeners are returned, so the common case
     * (no inheritance in play) costs one array lookup plus nothing.
     *
     * @return list<string>
     */
    private function matchingRegistryKeys(string $eventClass): array
    {
        $keys = [];

        if (isset($this->listeners[$eventClass])) {
            $keys[] = $eventClass;
        }

        if (!class_exists($eventClass, autoload: false)) {
            return $keys;
        }

        foreach (array_values((array) class_parents($eventClass)) as $parent) {
            if (isset($this->listeners[$parent])) {
                $keys[] = $parent;
            }
        }

        foreach (array_values((array) class_implements($eventClass)) as $interface) {
            if (isset($this->listeners[$interface])) {
                $keys[] = $interface;
            }
        }

        return $keys;
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
