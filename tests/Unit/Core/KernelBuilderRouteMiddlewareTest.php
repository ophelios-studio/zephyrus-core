<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\KernelBuilder;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Exception\RouteMiddlewareException;
use Zephyrus\Routing\Route;
use Zephyrus\Routing\Router;

final class KernelBuilderRouteMiddlewareTest extends TestCase
{
    public function testBuildRefusesAnUnregisteredRouteMiddleware(): void
    {
        $router = (new Router())->get('/admin', KernelBuilderRouteMiddlewareController::class . '@ping', middlewares: ['auht']);

        $builder = KernelBuilder::create()
            ->withRouter($router)
            ->registerMiddleware('admin', $this->makeMiddleware());

        try {
            $builder->build();
            self::fail('expected a refusal');
        } catch (RouteMiddlewareException $e) {
            self::assertSame(
                'Route middleware is not registered: "auht" on GET /admin; registered names: "admin". '
                . 'Register it with registerMiddleware() before build(), or, for a group, define it with '
                . 'Router::middlewareGroup() before the routes that name it.',
                $e->getMessage(),
            );
        }
    }

    public function testBuildGroupsUnknownNamesByNameInFirstSeenOrder(): void
    {
        $router = (new Router())
            ->get('/a', KernelBuilderRouteMiddlewareController::class . '@ping', middlewares: ['csrf-api'])
            ->post('/x', KernelBuilderRouteMiddlewareController::class . '@ping', middlewares: ['auth'])
            ->get('/b', KernelBuilderRouteMiddlewareController::class . '@ping', middlewares: ['csrf-api']);

        $builder = KernelBuilder::create()
            ->withRouter($router)
            ->registerMiddleware('admin', $this->makeMiddleware());

        try {
            $builder->build();
            self::fail('expected a refusal');
        } catch (RouteMiddlewareException $e) {
            self::assertSame(
                'Route middlewares are not registered: "csrf-api" on 2 routes (GET /a, GET /b), "auth" on POST /x; '
                . 'registered names: "admin". Register each with registerMiddleware() before build(), or, for a group, '
                . 'define it with Router::middlewareGroup() before the routes that name it.',
                $e->getMessage(),
            );
        }
    }

    public function testBuildListsExactlyThreeRoutesOfAnUnknownNameWithoutEllipsis(): void
    {
        $router = (new Router())
            ->get('/a', KernelBuilderRouteMiddlewareController::class . '@ping', middlewares: ['auth'])
            ->get('/b', KernelBuilderRouteMiddlewareController::class . '@ping', middlewares: ['auth'])
            ->get('/c', KernelBuilderRouteMiddlewareController::class . '@ping', middlewares: ['auth']);

        try {
            KernelBuilder::create()
                ->withRouter($router)
                ->registerMiddleware('admin', $this->makeMiddleware())
                ->build();
            self::fail('expected a refusal');
        } catch (RouteMiddlewareException $e) {
            self::assertSame(
                'Route middleware is not registered: "auth" on 3 routes (GET /a, GET /b, GET /c); '
                . 'registered names: "admin". Register it with registerMiddleware() before build(), or, for a group, '
                . 'define it with Router::middlewareGroup() before the routes that name it.',
                $e->getMessage(),
            );
        }
    }

    public function testBuildAbbreviatesAnUnknownNameOnFourRoutesToTheFirstThree(): void
    {
        $router = (new Router())
            ->get('/a', KernelBuilderRouteMiddlewareController::class . '@ping', middlewares: ['auth'])
            ->get('/b', KernelBuilderRouteMiddlewareController::class . '@ping', middlewares: ['auth'])
            ->get('/c', KernelBuilderRouteMiddlewareController::class . '@ping', middlewares: ['auth'])
            ->get('/d', KernelBuilderRouteMiddlewareController::class . '@ping', middlewares: ['auth']);

        try {
            KernelBuilder::create()
                ->withRouter($router)
                ->registerMiddleware('admin', $this->makeMiddleware())
                ->build();
            self::fail('expected a refusal');
        } catch (RouteMiddlewareException $e) {
            self::assertSame(
                'Route middleware is not registered: "auth" on 4 routes (GET /a, GET /b, GET /c, ...); '
                . 'registered names: "admin". Register it with registerMiddleware() before build(), or, for a group, '
                . 'define it with Router::middlewareGroup() before the routes that name it.',
                $e->getMessage(),
            );
        }
    }

    public function testBuildRefusesWhenNothingIsRegistered(): void
    {
        $router = (new Router())->get('/admin', KernelBuilderRouteMiddlewareController::class . '@ping', middlewares: ['auth']);

        try {
            KernelBuilder::create()->withRouter($router)->build();
            self::fail('expected a refusal');
        } catch (RouteMiddlewareException $e) {
            self::assertSame(
                'Route middleware is not registered: "auth" on GET /admin; no middleware is registered. '
                . 'Register it with registerMiddleware() before build(), or, for a group, define it with '
                . 'Router::middlewareGroup() before the routes that name it.',
                $e->getMessage(),
            );
        }
    }

    public function testBuildRefusesAnUnknownNameEscapingControlBytes(): void
    {
        $router = (new Router())->get('/admin', KernelBuilderRouteMiddlewareController::class . '@ping', middlewares: ["au\x1bth"]);

        try {
            KernelBuilder::create()
                ->withRouter($router)
                ->registerMiddleware('auth', $this->makeMiddleware())
                ->build();
            self::fail('expected a refusal');
        } catch (RouteMiddlewareException $e) {
            self::assertSame(
                'Route middleware is not registered: "au\\u001bth" on GET /admin; registered names: "auth". '
                . 'Register it with registerMiddleware() before build(), or, for a group, define it with '
                . 'Router::middlewareGroup() before the routes that name it.',
                $e->getMessage(),
            );
        }
    }

    public function testRequestToARouteNamingAnUnregisteredMiddlewareIsAServerError(): void
    {
        $router = (new Router())
            ->get('/admin', KernelBuilderRouteMiddlewareController::class . '@ping', middlewares: ['admin']);

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->registerMiddleware('admin', $this->makeMiddleware())
            ->build();

        // The collection is live: a route added after build() bypasses the build-time check.
        $router->routes()->add(Route::define(
            'GET',
            '/ghost',
            KernelBuilderRouteMiddlewareController::class . '@ping',
            [],
            ['ghost'],
        ));

        self::assertSame(200, $kernel->handle(Request::fromArray('GET', '/admin'))->status);

        $response = $kernel->handle(Request::fromArray('GET', '/ghost'));

        self::assertSame(500, $response->status);
        self::assertSame('Internal Server Error', $response->body);
        self::assertStringNotContainsString('ghost', $response->body);
    }

    public function testBuildAcceptsAGroupAliasExpandingToRegisteredNames(): void
    {
        $router = (new Router())
            ->middlewareGroup('web', ['auth', 'admin'])
            ->get('/admin', KernelBuilderRouteMiddlewareController::class . '@ping', middlewares: ['web']);

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->registerMiddleware('admin', $this->makeMiddleware())
            ->registerMiddleware('auth', $this->makeMiddleware())
            ->build();

        self::assertSame(200, $kernel->handle(Request::fromArray('GET', '/admin'))->status);
    }

    public function testBuildAcceptsARegisteredRouteMiddleware(): void
    {
        $router = (new Router())->get('/admin', KernelBuilderRouteMiddlewareController::class . '@ping', middlewares: ['auth']);

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->registerMiddleware('auth', $this->makeMiddleware())
            ->build();

        self::assertSame(200, $kernel->handle(Request::fromArray('GET', '/admin'))->status);
    }

    private function makeMiddleware(): MiddlewareInterface
    {
        return new class implements MiddlewareInterface {
            public function process(Request $request, callable $next): Response
            {
                return $next($request);
            }
        };
    }
}

final class KernelBuilderRouteMiddlewareController
{
    public function ping(): Response
    {
        return Response::text('pong');
    }
}
