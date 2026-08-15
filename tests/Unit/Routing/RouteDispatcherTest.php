<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\MiddlewarePipeline;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Exception\MethodNotAllowedException;
use Zephyrus\Routing\Exception\RouteMiddlewareException;
use Zephyrus\Routing\Exception\RouteNotFoundException;
use Zephyrus\Routing\Route;
use Zephyrus\Routing\RouteCollection;
use Zephyrus\Routing\RouteDispatcher;
use Zephyrus\Routing\RouteMatch;

final class RouteDispatcherTest extends TestCase
{
    public function testDispatchResolvesRouteAndRunsPipeline(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/users/{id}', 'UserController@show', ['id' => '\\d+'], ['auth']));

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
                'requestId' => $request->attribute('id'),
                'path' => $request->uri()->path(),
            ]),
            routeMiddlewareResolver: static fn (string $name): MiddlewareInterface => new class($name) implements MiddlewareInterface {
                public function __construct(private string $name)
                {
                }

                public function process(Request $request, callable $next): Response
                {
                    return $next($request)->withHeader('X-Route-Middleware', $this->name);
                }
            },
        );

        $response = $dispatcher->dispatch(Request::fromArray('GET', '/users/42?expand=roles'));

        self::assertSame(200, $response->status);
        self::assertSame('on', $response->headers['x-pipeline']);
        self::assertSame('auth', $response->headers['x-route-middleware']);
        self::assertStringContainsString('"handler":"UserController@show"', $response->body);
        self::assertStringContainsString('"id":"42"', $response->body);
        self::assertStringContainsString('"requestId":"42"', $response->body);
        self::assertStringContainsString('"path":"\\/users\\/42"', $response->body);
    }

    public function testDispatchThrowsTypedExceptionForUnknownNamedRouteMiddleware(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/users', 'UserController@index', middlewares: ['missing']));

        $dispatcher = new RouteDispatcher(
            routes: $routes,
            pipeline: new MiddlewarePipeline(),
            resolver: static fn (RouteMatch $match, Request $request): Response => Response::text('ok'),
        );

        $this->expectException(RouteMiddlewareException::class);
        $this->expectExceptionMessage('Unknown route middleware: missing');

        $dispatcher->dispatch(Request::fromArray('GET', '/users'));
    }

    public function testDispatchWrapsUnexpectedResolverFailuresAsRouteMiddlewareException(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/users', 'UserController@index', middlewares: ['auth']));

        $dispatcher = new RouteDispatcher(
            routes: $routes,
            pipeline: new MiddlewarePipeline(),
            resolver: static fn (RouteMatch $match, Request $request): Response => Response::text('ok'),
            routeMiddlewareResolver: static fn (string $name): MiddlewareInterface => throw new \RuntimeException('container is down'),
        );

        $this->expectException(RouteMiddlewareException::class);
        $this->expectExceptionMessage('Unable to resolve route middleware "auth": container is down');

        $dispatcher->dispatch(Request::fromArray('GET', '/users'));
    }

    public function testMatchResolvesTheRouteWithoutRunningAnything(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/users/{id}', 'UserController@show', ['id' => '\\d+']));

        $handlerRan = false;

        $dispatcher = new RouteDispatcher(
            routes: $routes,
            pipeline: new MiddlewarePipeline(),
            resolver: static function (RouteMatch $match, Request $request) use (&$handlerRan): Response {
                $handlerRan = true;

                return Response::text('ok');
            },
        );

        $match = $dispatcher->match(Request::fromArray('GET', '/users/42'));

        self::assertSame('UserController@show', $match->route->handler);
        self::assertSame('42', $match->parameter('id'));
        self::assertFalse($handlerRan, 'match() must resolve only, never dispatch');
    }

    public function testMatchThrowsRouteNotFoundWhenNothingMatches(): void
    {
        $dispatcher = new RouteDispatcher(
            routes: new RouteCollection(),
            pipeline: new MiddlewarePipeline(),
            resolver: static fn (RouteMatch $match, Request $request): Response => Response::text('ok'),
        );

        $this->expectException(RouteNotFoundException::class);

        $dispatcher->match(Request::fromArray('GET', '/missing'));
    }

    public function testMatchThrowsMethodNotAllowedWhenOnlyTheMethodDiffers(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/users', 'UserController@index'));

        $dispatcher = new RouteDispatcher(
            routes: $routes,
            pipeline: new MiddlewarePipeline(),
            resolver: static fn (RouteMatch $match, Request $request): Response => Response::text('ok'),
        );

        $this->expectException(MethodNotAllowedException::class);

        $dispatcher->match(Request::fromArray('DELETE', '/users'));
    }

    public function testDispatchMatchRunsRouteMiddlewaresOnTopOfTheBasePipeline(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/users', 'UserController@index', middlewares: ['auth']));

        $dispatcher = new RouteDispatcher(
            routes: $routes,
            pipeline: new MiddlewarePipeline([
                new class implements MiddlewareInterface {
                    public function process(Request $request, callable $next): Response
                    {
                        return $next($request)->withHeader('X-Base', 'on');
                    }
                },
            ]),
            resolver: static fn (RouteMatch $match, Request $request): Response => Response::text('ok'),
            routeMiddlewareResolver: static fn (string $name): MiddlewareInterface => new class implements MiddlewareInterface {
                public function process(Request $request, callable $next): Response
                {
                    return $next($request)->withHeader('X-Route', 'on');
                }
            },
        );

        $request = Request::fromArray('GET', '/users');
        $response = $dispatcher->dispatchMatch($dispatcher->match($request), $request);

        self::assertSame('ok', $response->body);
        self::assertSame('on', $response->headers['x-base']);
        self::assertSame('on', $response->headers['x-route']);
    }

    public function testDispatchMatchDoesNotSwallowHandlerExceptions(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/boom', 'BoomController@boom'));

        $dispatcher = new RouteDispatcher(
            routes: $routes,
            pipeline: new MiddlewarePipeline(),
            resolver: static fn (RouteMatch $match, Request $request): Response => throw new \RuntimeException('boom'),
        );

        $request = Request::fromArray('GET', '/boom');
        $match = $dispatcher->match($request);

        // Converting a throwable into a response is HttpKernel's job, because
        // it has to happen inside the global middleware pipeline.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $dispatcher->dispatchMatch($match, $request);
    }

    public function testDispatchResolvesDuplicateMiddlewareNamesOnlyOnce(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/users', 'UserController@index', middlewares: ['auth', 'auth', 'audit']));

        $resolvedNames = [];

        $dispatcher = new RouteDispatcher(
            routes: $routes,
            pipeline: new MiddlewarePipeline(),
            resolver: static fn (RouteMatch $match, Request $request): Response => Response::text('ok'),
            routeMiddlewareResolver: static function (string $name) use (&$resolvedNames): MiddlewareInterface {
                $resolvedNames[] = $name;

                return new class implements MiddlewareInterface {
                    public function process(Request $request, callable $next): Response
                    {
                        return $next($request);
                    }
                };
            },
        );

        $dispatcher->dispatch(Request::fromArray('GET', '/users'));

        self::assertSame(['auth', 'audit'], $resolvedNames);
    }
}
