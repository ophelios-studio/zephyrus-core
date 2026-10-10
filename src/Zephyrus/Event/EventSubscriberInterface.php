<?php

declare(strict_types=1);

namespace Zephyrus\Event;

/**
 * Groups several listeners in one class, registered with EventDispatcher::addSubscriber().
 *
 * Each map entry is 'methodName' or ['methodName'] (priority 0), or ['methodName', priority]:
 * higher priorities run first. A method the subscriber does not expose publicly is refused by addSubscriber().
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
     * Return the event-to-listener map of this subscriber.
     *
     * @return array<class-string<Event>, string|array{0: string, 1?: int}>
     */
    public static function getSubscribedEvents(): array;
}
