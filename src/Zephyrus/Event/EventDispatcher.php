<?php

declare(strict_types=1);

namespace Zephyrus\Event;

use InvalidArgumentException;
use Zephyrus\Exceptions\MessageValue;

/**
 * Synchronous event dispatcher.
 *
 * Listeners run in descending priority order, ties in registration order.
 * A listener halts the dispatch by calling $event->stopPropagation().
 *
 *   $dispatcher->addListener(OrderPlacedEvent::class, $listener);
 *   $dispatcher->dispatch(new OrderPlacedEvent($order));
 */
final class EventDispatcher
{
    /**
     * @var array<class-string<Event>, list<array{0: callable, 1: int}>>
     */
    private array $listeners = [];

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
     * Register every listener declared by the subscriber's getSubscribedEvents().
     *
     * Every entry is checked before any listener is registered.
     *
     * @throws InvalidArgumentException When an entry is malformed or names a method the subscriber does not expose.
     */
    public function addSubscriber(EventSubscriberInterface $subscriber): void
    {
        $registrations = [];

        foreach ($subscriber::getSubscribedEvents() as $eventClass => $spec) {
            $parsed = self::parseSubscribedSpec($spec);

            if ($parsed === null) {
                throw new InvalidArgumentException(sprintf(
                    'Subscriber %s declares an invalid listener for %s: expected "method", ["method"] or ["method", priority], got %s.',
                    $subscriber::class,
                    $eventClass,
                    self::describeSpec($spec),
                ));
            }

            [$method, $priority] = $parsed;
            $listener = [$subscriber, $method];

            if (!is_callable($listener)) {
                throw new InvalidArgumentException(sprintf(
                    'Subscriber %1$s declares the listener %2$s for %3$s, but %1$s has no public method %2$s.',
                    $subscriber::class,
                    MessageValue::quote($method),
                    $eventClass,
                ));
            }

            $registrations[] = [$eventClass, $listener, $priority];
        }

        foreach ($registrations as [$eventClass, $listener, $priority]) {
            $this->addListener($eventClass, $listener, $priority);
        }
    }

    /**
     * Deregister all listeners belonging to a subscriber.
     */
    public function removeSubscriber(EventSubscriberInterface $subscriber): void
    {
        foreach ($subscriber::getSubscribedEvents() as $eventClass => $spec) {
            $parsed = self::parseSubscribedSpec($spec);
            $listener = $parsed === null ? null : [$subscriber, $parsed[0]];

            if ($listener !== null && is_callable($listener)) {
                $this->removeListener($eventClass, $listener);
            }
        }
    }

    private static function describeSpec(mixed $spec): string
    {
        if (!is_array($spec) || !array_is_list($spec)) {
            return MessageValue::describe($spec);
        }

        return '[' . MessageValue::quoteList(array_slice($spec, 0, 3)) . (count($spec) > 3 ? ', ...' : '') . ']';
    }

    /**
     * @return array{string, int}|null The method and priority, or null when the entry is malformed.
     */
    private static function parseSubscribedSpec(mixed $spec): ?array
    {
        if (is_string($spec)) {
            return [$spec, 0];
        }

        if (!is_array($spec) || !array_is_list($spec) || count($spec) < 1 || count($spec) > 2) {
            return null;
        }

        $priority = count($spec) === 2 ? $spec[1] : 0;

        return is_string($spec[0]) && is_int($priority) ? [$spec[0], $priority] : null;
    }

    /**
     * Dispatch an event to every applicable listener and return it.
     *
     * Applicable listeners are those registered on the event class, its parent
     * classes and its interfaces. Among equal priorities, the exact class runs
     * first, then parents, then interfaces, each in registration order.
     *
     * A throwing listener aborts the dispatch, unless $onListenerError is given:
     * the failure is passed to it and the remaining listeners still run.
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

    /**
     * Return true when listeners are registered on $eventClass itself, not on its ancestors.
     *
     * @param class-string<Event> $eventClass
     */
    public function hasListeners(string $eventClass): bool
    {
        return !empty($this->listeners[$eventClass]);
    }

    /**
     * Return the listeners registered on $eventClass itself, in priority order.
     *
     * Exact class only, so each result can be passed back to removeListener().
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

        // Stable sort: equal priorities keep the exact-class-first order built above.
        usort($entries, static fn(array $a, array $b): int => $b[1] <=> $a[1]);

        return array_column($entries, 0);
    }

    /**
     * Registry keys that apply to $eventClass, most specific first.
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

        foreach (array_values(class_parents($eventClass) ?: []) as $parent) {
            if (isset($this->listeners[$parent])) {
                $keys[] = $parent;
            }
        }

        foreach (array_values(class_implements($eventClass) ?: []) as $interface) {
            if (isset($this->listeners[$interface])) {
                $keys[] = $interface;
            }
        }

        return $keys;
    }

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
