<?php

declare(strict_types=1);

namespace Zephyrus\Event;

/**
 * Marker interface for event subscribers.
 *
 * A subscriber groups multiple event listeners into a single class.
 * Pass an instance to EventDispatcher::addSubscriber() and the dispatcher
 * will automatically register all listeners declared by getSubscribedEvents().
 *
 * Each map entry may be one of:
 *
 *   EventClass::class => 'methodName'
 *       Register $this->methodName at priority 0.
 *
 *   EventClass::class => ['methodName', 10]
 *       Register $this->methodName at priority 10.
 *       Higher priority values are called before lower ones.
 *
 * Example:
 *
 *   class NotificationSubscriber implements EventSubscriberInterface
 *   {
 *       public static function getSubscribedEvents(): array
 *       {
 *           return [
 *               UserRegisteredEvent::class => 'onUserRegistered',
 *               UserLoginEvent::class      => ['onUserLogin', 5],
 *           ];
 *       }
 *
 *       public function onUserRegistered(UserRegisteredEvent $event): void { ... }
 *       public function onUserLogin(UserLoginEvent $event): void { ... }
 *   }
 *
 *   $dispatcher->addSubscriber(new NotificationSubscriber());
 */
interface EventSubscriberInterface
{
    /**
     * Return the event-to-listener map for this subscriber.
     *
     * Keys are fully-qualified event class names.
     * Values are either a method name string (priority 0) or a two-element
     * array of [methodName, priority].
     *
     * @return array<class-string<Event>, string|array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array;
}
