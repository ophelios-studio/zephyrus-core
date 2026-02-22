<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\KernelEvent;
use Zephyrus\Core\RequestEvent;
use Zephyrus\Core\ResponseEvent;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

final class KernelEventTest extends TestCase
{
    // -------------------------------------------------------------------------
    // KernelEvent base
    // -------------------------------------------------------------------------

    public function testKernelEventExposesRequest(): void
    {
        $request = Request::fromArray('GET', '/test');
        $event = new RequestEvent($request);

        self::assertSame($request, $event->getRequest());
    }

    // -------------------------------------------------------------------------
    // RequestEvent
    // -------------------------------------------------------------------------

    public function testRequestEventHasNoResponseByDefault(): void
    {
        $event = new RequestEvent(Request::fromArray('GET', '/'));

        self::assertFalse($event->hasResponse());
        self::assertNull($event->getResponse());
    }

    public function testRequestEventSetResponseStoresThatResponse(): void
    {
        $event = new RequestEvent(Request::fromArray('GET', '/'));
        $response = Response::text('short-circuit');

        $event->setResponse($response);

        self::assertTrue($event->hasResponse());
        self::assertSame($response, $event->getResponse());
    }

    public function testRequestEventSetResponseStopsPropagation(): void
    {
        $event = new RequestEvent(Request::fromArray('GET', '/'));

        self::assertFalse($event->isPropagationStopped());

        $event->setResponse(Response::text('halt'));

        self::assertTrue($event->isPropagationStopped());
    }

    public function testRequestEventWithoutSetResponseDoesNotStopPropagation(): void
    {
        $event = new RequestEvent(Request::fromArray('GET', '/'));

        self::assertFalse($event->isPropagationStopped());
    }

    // -------------------------------------------------------------------------
    // ResponseEvent
    // -------------------------------------------------------------------------

    public function testResponseEventExposesInitialResponse(): void
    {
        $request = Request::fromArray('GET', '/');
        $response = Response::text('hello');

        $event = new ResponseEvent($request, $response);

        self::assertSame($request, $event->getRequest());
        self::assertSame($response, $event->getResponse());
    }

    public function testResponseEventSetResponseReplacesIt(): void
    {
        $event = new ResponseEvent(
            Request::fromArray('GET', '/'),
            Response::text('original'),
        );

        $replacement = Response::text('replaced', status: 201);
        $event->setResponse($replacement);

        self::assertSame($replacement, $event->getResponse());
    }

    public function testResponseEventDoesNotStopPropagationOnSetResponse(): void
    {
        $event = new ResponseEvent(
            Request::fromArray('GET', '/'),
            Response::text('original'),
        );

        $event->setResponse(Response::text('new'));

        self::assertFalse($event->isPropagationStopped());
    }

    public function testResponseEventSetResponseCanBeCalledMultipleTimes(): void
    {
        $event = new ResponseEvent(
            Request::fromArray('GET', '/'),
            Response::text('first'),
        );

        $event->setResponse(Response::text('second'));
        $event->setResponse(Response::text('third'));

        self::assertSame('third', $event->getResponse()->body);
    }
}
