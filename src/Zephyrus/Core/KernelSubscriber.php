<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use Zephyrus\Event\EventSubscriberInterface;

/**
 * Convenience base class for subscribers that hook into the HttpKernel lifecycle.
 *
 * Extend this class and override onRequest() and/or onResponse() to react to
 * the two kernel lifecycle events without writing any getSubscribedEvents()
 * boilerplate.
 *
 * Both hook methods are no-ops by default, so you only override what you need.
 * Adjust execution order relative to other listeners by overriding the static
 * priority methods.
 *
 * ## Example
 *
 * ```php
 * class AuthSubscriber extends KernelSubscriber
 * {
 *     public function __construct(private readonly AuthGuard $auth) {}
 *
 *     public function onRequest(RequestEvent $event): void
 *     {
 *         if (!$this->auth->check($event->getRequest())) {
 *             $event->setResponse(Response::text('Unauthorized', status: 401));
 *         }
 *     }
 * }
 *
 * // Registration:
 * $events = new EventDispatcher();
 * $events->addSubscriber(new AuthSubscriber($auth));
 *
 * $kernel = KernelBuilder::create()
 *     ->withEventDispatcher($events)
 *     ->build();
 * ```
 *
 * ## Priority
 *
 * Override requestPriority() or responsePriority() to control ordering when
 * multiple subscribers are registered for the same kernel event.
 * Higher priority values run first (default 0).
 *
 * ```php
 * class HighPriorityGuard extends KernelSubscriber
 * {
 *     protected static function requestPriority(): int { return 100; }
 *
 *     public function onRequest(RequestEvent $event): void { ... }
 * }
 * ```
 */
abstract class KernelSubscriber implements EventSubscriberInterface
{
    /**
     * Auto-wires RequestEvent and ResponseEvent to the typed hook methods.
     *
     * This method is declared final so that the event-to-method wiring cannot
     * be accidentally broken by a subclass.  Adjust ordering via the priority
     * helpers instead.
     *
     * @return array<class-string, array{0: string, 1: int}>
     */
    final public static function getSubscribedEvents(): array
    {
        return [
            RequestEvent::class  => ['onRequest',  static::requestPriority()],
            ResponseEvent::class => ['onResponse', static::responsePriority()],
        ];
    }

    /**
     * Called by the kernel BEFORE route dispatching.
     *
     * Call $event->setResponse() to short-circuit routing and return an early
     * response (propagation will be stopped automatically).
     */
    public function onRequest(RequestEvent $event): void {}

    /**
     * Called by the kernel AFTER a response has been produced.
     *
     * Inspect or replace the outgoing response via $event->getResponse() /
     * $event->setResponse().
     */
    public function onResponse(ResponseEvent $event): void {}

    /**
     * Listener priority for RequestEvent.
     *
     * Override in a subclass to control execution order relative to other
     * subscribers.  Higher values run first.
     */
    protected static function requestPriority(): int
    {
        return 0;
    }

    /**
     * Listener priority for ResponseEvent.
     *
     * Override in a subclass to control execution order relative to other
     * subscribers.  Higher values run first.
     */
    protected static function responsePriority(): int
    {
        return 0;
    }
}
