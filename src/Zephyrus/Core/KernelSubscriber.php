<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use Zephyrus\Event\EventSubscriberInterface;

/**
 * Base class for subscribers hooking into the HttpKernel lifecycle.
 *
 * Override onRequest() (before routing) and/or onResponse() (after the
 * response is built). Both are no-ops by default. Override requestPriority()
 * or responsePriority() to order listeners: higher values run first (default 0).
 *
 * Example:
 *
 * ```php
 * final class AuthSubscriber extends KernelSubscriber
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
 * $events = new EventDispatcher();
 * $events->addSubscriber(new AuthSubscriber($auth));
 *
 * $kernel = KernelBuilder::create()
 *     ->withEventDispatcher($events)
 *     ->build();
 * ```
 */
abstract class KernelSubscriber implements EventSubscriberInterface
{
    /**
     * Maps RequestEvent and ResponseEvent to onRequest() and onResponse().
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
     * Called before routing. $event->setResponse() short-circuits the request and stops propagation.
     */
    public function onRequest(RequestEvent $event): void {}

    /**
     * Called after the response is built. Inspect or replace it via $event->getResponse() and $event->setResponse().
     */
    public function onResponse(ResponseEvent $event): void {}

    /**
     * Listener priority for RequestEvent. Higher values run first.
     */
    protected static function requestPriority(): int
    {
        return 0;
    }

    /**
     * Listener priority for ResponseEvent. Higher values run first.
     */
    protected static function responsePriority(): int
    {
        return 0;
    }
}
