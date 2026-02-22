<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\KernelSubscriber;
use Zephyrus\Core\RequestEvent;
use Zephyrus\Core\ResponseEvent;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

final class KernelSubscriberTest extends TestCase
{
    public function testSubscribedEventsMapToTypedHooksWithDefaultPriority(): void
    {
        self::assertSame(
            [
                RequestEvent::class => ['onRequest', 0],
                ResponseEvent::class => ['onResponse', 0],
            ],
            DefaultSubscriber::getSubscribedEvents()
        );
    }

    public function testSubscribedEventsUseOverriddenPriorities(): void
    {
        self::assertSame(
            [
                RequestEvent::class => ['onRequest', 150],
                ResponseEvent::class => ['onResponse', -20],
            ],
            PrioritySubscriber::getSubscribedEvents()
        );
    }

    public function testDefaultHookMethodsAreNoOps(): void
    {
        $subscriber = new DefaultSubscriber();

        $requestEvent = new RequestEvent(Request::fromArray('GET', '/'));
        $responseEvent = new ResponseEvent(
            Request::fromArray('GET', '/'),
            Response::text('ok')
        );

        $subscriber->onRequest($requestEvent);
        $subscriber->onResponse($responseEvent);

        self::assertNull($requestEvent->getResponse());
        self::assertSame('ok', $responseEvent->getResponse()->body);
    }
}

final class DefaultSubscriber extends KernelSubscriber {}

final class PrioritySubscriber extends KernelSubscriber
{
    protected static function requestPriority(): int
    {
        return 150;
    }

    protected static function responsePriority(): int
    {
        return -20;
    }
}
