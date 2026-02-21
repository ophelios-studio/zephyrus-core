<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\MiddlewarePipeline;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Route;
use Zephyrus\Routing\RouteCollection;
use Zephyrus\Routing\RouteDispatcher;
use Zephyrus\Routing\RouteMatch;

final class RouteDispatcherTest extends TestCase
{
    public function testDispatchResolvesRouteAndRunsPipeline(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/users/{id}', 'UserController@show', ['id' => '\\d+']));

        $pipeline = new MiddlewarePipeline([
            new class implements MiddlewareInterface {
                public function process(Request $request, callable $next): Response
                {
                    return $next($request)->withHeader('X-Pipeline', 'on');
                }
            },
        ]);

        $dispatcher = new RouteDispatcher(
            routes: $routes,
            pipeline: $pipeline,
            resolver: static fn (RouteMatch $match, Request $request): Response => Response::json([
                'handler' => $match->route->handler,
                'id' => $match->parameter('id'),
                'path' => $request->path(),
            ]),
        );

        $response = $dispatcher->dispatch(Request::fromArray('GET', '/users/42?expand=roles'));

        self::assertSame(200, $response->status);
        self::assertSame('on', $response->headers['X-Pipeline']);
        self::assertStringContainsString('"handler":"UserController@show"', $response->body);
        self::assertStringContainsString('"id":"42"', $response->body);
        self::assertStringContainsString('"path":"\\/users\\/42"', $response->body);
    }
}
