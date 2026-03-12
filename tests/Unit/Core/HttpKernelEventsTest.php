<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\HttpKernel;
use Zephyrus\Core\KernelBuilder;
use Zephyrus\Core\RequestEvent;
use Zephyrus\Core\ResponseEvent;
use Zephyrus\Event\EventDispatcher;
use Zephyrus\Http\Error\HttpExceptionResponder;
use Zephyrus\Http\MiddlewarePipeline;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Route;
use Zephyrus\Routing\RouteCollection;
use Zephyrus\Routing\RouteDispatcher;
use Zephyrus\Routing\RouteMatch;
use Zephyrus\Routing\Router;

final class HttpKernelEventsTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Baseline: no event dispatcher
    // -------------------------------------------------------------------------

    public function testKernelWithoutEventsHandlesNormally(): void
    {
        $kernel = $this->makeKernelWithRoute('/ping', fn (): Response => Response::text('pong'));

        $response = $kernel->handle(Request::fromArray('GET', '/ping'));

        self::assertSame(200, $response->status);
        self::assertSame('pong', $response->body);
    }

    // -------------------------------------------------------------------------
    // RequestEvent — no short-circuit
    // -------------------------------------------------------------------------

    public function testRequestEventListenerReceivesEvent(): void
    {
        $received = null;
        $events = new EventDispatcher();
        $events->addListener(RequestEvent::class, function (RequestEvent $e) use (&$received): void {
            $received = $e->getRequest()->uri()->full();
        });

        $kernel = $this->makeKernelWithRoute('/hello', fn (): Response => Response::text('hi'), $events);
        $kernel->handle(Request::fromArray('GET', '/hello'));

        self::assertSame('/hello', $received);
    }

    public function testRequestEventWithoutShortCircuitRoutesNormally(): void
    {
        $events = new EventDispatcher();
        $events->addListener(RequestEvent::class, function (RequestEvent $e): void {
            // Inspect but do not set a response → routing proceeds as normal.
        });

        $kernel = $this->makeKernelWithRoute('/resource', fn (): Response => Response::text('data'), $events);

        $response = $kernel->handle(Request::fromArray('GET', '/resource'));

        self::assertSame(200, $response->status);
        self::assertSame('data', $response->body);
    }

    // -------------------------------------------------------------------------
    // RequestEvent — short-circuit
    // -------------------------------------------------------------------------

    public function testRequestEventShortCircuitBypasesRouting(): void
    {
        $events = new EventDispatcher();
        $events->addListener(RequestEvent::class, function (RequestEvent $e): void {
            $e->setResponse(Response::text('maintenance', status: 503));
        });

        // Even though a matching route exists, the short-circuit fires first.
        $kernel = $this->makeKernelWithRoute('/ping', fn (): Response => Response::text('pong'), $events);

        $response = $kernel->handle(Request::fromArray('GET', '/ping'));

        self::assertSame(503, $response->status);
        self::assertSame('maintenance', $response->body);
    }

    public function testRequestEventShortCircuitStopsOtherRequestListeners(): void
    {
        $secondCalled = false;
        $events = new EventDispatcher();

        // High-priority listener short-circuits.
        $events->addListener(RequestEvent::class, function (RequestEvent $e): void {
            $e->setResponse(Response::text('blocked', status: 403));
        }, priority: 10);

        // Lower-priority listener must NOT be called.
        $events->addListener(RequestEvent::class, function (RequestEvent $e) use (&$secondCalled): void {
            $secondCalled = true;
        }, priority: 0);

        $kernel = $this->makeKernelWithRoute('/secret', fn (): Response => Response::text('secret'), $events);
        $kernel->handle(Request::fromArray('GET', '/secret'));

        self::assertFalse($secondCalled, 'Second listener should not run after short-circuit');
    }

    // -------------------------------------------------------------------------
    // ResponseEvent
    // -------------------------------------------------------------------------

    public function testResponseEventListenerReceivesBuiltResponse(): void
    {
        $capturedStatus = null;
        $events = new EventDispatcher();
        $events->addListener(ResponseEvent::class, function (ResponseEvent $e) use (&$capturedStatus): void {
            $capturedStatus = $e->getResponse()->status;
        });

        $kernel = $this->makeKernelWithRoute('/ok', fn (): Response => Response::text('ok'), $events);
        $kernel->handle(Request::fromArray('GET', '/ok'));

        self::assertSame(200, $capturedStatus);
    }

    public function testResponseEventCanAddHeader(): void
    {
        $events = new EventDispatcher();
        $events->addListener(ResponseEvent::class, function (ResponseEvent $e): void {
            $e->setResponse($e->getResponse()->withHeader('X-Powered-By', 'Zephyrus'));
        });

        $kernel = $this->makeKernelWithRoute('/ping', fn (): Response => Response::text('pong'), $events);
        $response = $kernel->handle(Request::fromArray('GET', '/ping'));

        self::assertSame('Zephyrus', $response->headers['x-powered-by']);
        self::assertSame('pong', $response->body);
    }

    public function testResponseEventCanReplaceBodyCompletely(): void
    {
        $events = new EventDispatcher();
        $events->addListener(ResponseEvent::class, function (ResponseEvent $e): void {
            $e->setResponse(Response::text('transformed'));
        });

        $kernel = $this->makeKernelWithRoute('/orig', fn (): Response => Response::text('original'), $events);
        $response = $kernel->handle(Request::fromArray('GET', '/orig'));

        self::assertSame('transformed', $response->body);
    }

    public function testResponseEventAlsoFiresOnShortCircuitPath(): void
    {
        $responseListenerCalled = false;
        $events = new EventDispatcher();

        $events->addListener(RequestEvent::class, function (RequestEvent $e): void {
            $e->setResponse(Response::text('early', status: 200));
        });

        $events->addListener(ResponseEvent::class, function (ResponseEvent $e) use (&$responseListenerCalled): void {
            $responseListenerCalled = true;
        });

        $kernel = $this->makeKernelWithRoute('/ping', fn (): Response => Response::text('pong'), $events);
        $kernel->handle(Request::fromArray('GET', '/ping'));

        self::assertTrue($responseListenerCalled, 'ResponseEvent must fire even on short-circuit path');
    }

    public function testResponseEventFiredForErrorResponses(): void
    {
        $capturedStatus = null;
        $events = new EventDispatcher();
        $events->addListener(ResponseEvent::class, function (ResponseEvent $e) use (&$capturedStatus): void {
            $capturedStatus = $e->getResponse()->status;
        });

        // Empty route collection → 404.
        $routes = new RouteCollection();
        $dispatcher = new RouteDispatcher(
            routes: $routes,
            pipeline: new MiddlewarePipeline(),
            resolver: static fn (RouteMatch $m, Request $r): Response => Response::text('ok'),
        );

        $kernel = new HttpKernel($dispatcher, new HttpExceptionResponder(), $events);
        $kernel->handle(Request::fromArray('GET', '/missing'));

        self::assertSame(404, $capturedStatus);
    }

    // -------------------------------------------------------------------------
    // KernelBuilder integration
    // -------------------------------------------------------------------------

    public function testWithEventDispatcherReturnsNewBuilderInstance(): void
    {
        $builder = KernelBuilder::create();
        $modified = $builder->withEventDispatcher(new EventDispatcher());

        self::assertNotSame($builder, $modified);
    }

    public function testBuilderWithEventDispatcherWiresEventsIntoKernel(): void
    {
        $requestListenerCalled = false;
        $responseListenerCalled = false;

        $events = new EventDispatcher();
        $events->addListener(RequestEvent::class, function (RequestEvent $e) use (&$requestListenerCalled): void {
            $requestListenerCalled = true;
        });
        $events->addListener(ResponseEvent::class, function (ResponseEvent $e) use (&$responseListenerCalled): void {
            $responseListenerCalled = true;
        });

        $router = (new Router())->get('/ping', HttpKernelEventsFixtureController::class . '@ping');

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withEventDispatcher($events)
            ->build();

        $kernel->handle(Request::fromArray('GET', '/ping'));

        self::assertTrue($requestListenerCalled);
        self::assertTrue($responseListenerCalled);
    }

    public function testBuilderWithoutEventDispatcherPreservesOriginalBehaviour(): void
    {
        $router = (new Router())->get('/ping', HttpKernelEventsFixtureController::class . '@ping');

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/ping'));

        self::assertSame(200, $response->status);
        self::assertSame('pong', $response->body);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeKernelWithRoute(
        string $path,
        callable $handler,
        ?EventDispatcher $events = null,
    ): HttpKernel {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', $path, 'Fixture@handle'));

        $dispatcher = new RouteDispatcher(
            routes: $routes,
            pipeline: new MiddlewarePipeline(),
            resolver: static fn (RouteMatch $match, Request $request): Response => $handler(),
        );

        return new HttpKernel($dispatcher, new HttpExceptionResponder(), $events);
    }
}

// ---------------------------------------------------------------------------
// Fixture controller — minimal, no base class required
// ---------------------------------------------------------------------------

final class HttpKernelEventsFixtureController
{
    public function ping(): Response
    {
        return Response::text('pong');
    }
}
