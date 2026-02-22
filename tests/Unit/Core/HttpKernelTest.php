<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\HttpKernel;
use Zephyrus\Http\Error\HttpExceptionResponder;
use Zephyrus\Http\MiddlewarePipeline;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Route;
use Zephyrus\Routing\RouteCollection;
use Zephyrus\Routing\RouteDispatcher;
use Zephyrus\Routing\RouteMatch;

final class HttpKernelTest extends TestCase
{
    public function testHandleReturnsDispatcherResponseOnSuccess(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show'));

        $dispatcher = new RouteDispatcher(
            routes: $routes,
            pipeline: new MiddlewarePipeline(),
            resolver: static fn (RouteMatch $match, Request $request): Response => Response::text('ok'),
        );

        $kernel = new HttpKernel($dispatcher, new HttpExceptionResponder());

        $response = $kernel->handle(Request::fromArray('GET', '/health'));

        self::assertSame(200, $response->status);
        self::assertSame('ok', $response->body);
    }

    public function testHandleMapsRouteErrorsViaExceptionResponder(): void
    {
        $routes = new RouteCollection();

        $dispatcher = new RouteDispatcher(
            routes: $routes,
            pipeline: new MiddlewarePipeline(),
            resolver: static fn (RouteMatch $match, Request $request): Response => Response::text('ok'),
        );

        $kernel = new HttpKernel($dispatcher, new HttpExceptionResponder());

        $response = $kernel->handle(Request::fromArray('GET', '/missing'));

        self::assertSame(404, $response->status);
        self::assertSame('Not Found', $response->body);
    }

    public function testHandleReturnsJsonErrorWhenRequestAcceptsJson(): void
    {
        $routes = new RouteCollection();

        $dispatcher = new RouteDispatcher(
            routes: $routes,
            pipeline: new MiddlewarePipeline(),
            resolver: static fn (RouteMatch $match, Request $request): Response => Response::text('ok'),
        );

        $kernel = new HttpKernel($dispatcher, new HttpExceptionResponder());

        $response = $kernel->handle(Request::fromArray(
            method: 'GET',
            uri: '/missing',
            headers: ['Accept' => 'application/json'],
        ));

        self::assertSame(404, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        self::assertSame('{"error":{"status":404,"message":"Not Found"}}', $response->body);
    }
}
