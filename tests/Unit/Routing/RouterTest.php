<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Zephyrus\Routing\Router;

final class RouterTest extends TestCase
{
    public function testVerbHelpersRegisterRoutesWithExpectedMethods(): void
    {
        $router = (new Router())
            ->get('/health', 'HealthController@show')
            ->post('/users', 'UserController@store')
            ->delete('/users/{id}', 'UserController@delete');

        $routes = $router->routes()->all();

        self::assertCount(3, $routes);
        self::assertSame('GET', $routes[0]->method);
        self::assertSame('POST', $routes[1]->method);
        self::assertSame('DELETE', $routes[2]->method);
    }

    public function testGetSupportsConstraintsAndMiddlewareNames(): void
    {
        $router = (new Router())
            ->get('/users/{id}', 'UserController@show', ['id' => '\\d+'], ['auth']);

        $route = $router->routes()->all()[0];

        self::assertSame(['id' => '\\d+'], $route->constraints);
        self::assertSame(['auth'], $route->middlewares);
    }

    public function testRouterIsFluentAcrossMixedVerbRegistrations(): void
    {
        $router = new Router();

        $result = $router
            ->put('/users/{id}', 'UserController@update', ['id' => '\\d+'])
            ->patch('/users/{id}/status', 'UserController@patchStatus')
            ->add('HEAD', '/health', 'HealthController@head');

        self::assertInstanceOf(Router::class, $result);
        self::assertCount(3, $result->routes()->all());
    }

    public function testAddReturnsNewRouterWithoutMutatingPreviousInstance(): void
    {
        $router = new Router();
        $next = $router->get('/health', 'HealthController@show');

        self::assertCount(0, $router->routes()->all());
        self::assertCount(1, $next->routes()->all());
    }

    public function testResourceRegistersConventionalCrudRoutes(): void
    {
        $router = (new Router())->resource('/users', 'UserController', ['auth']);

        $routes = $router->routes()->all();

        self::assertCount(6, $routes);
        self::assertSame('GET', $routes[0]->method);
        self::assertSame('/users', $routes[0]->path);
        self::assertSame('UserController@index', $routes[0]->handler);

        self::assertSame('GET', $routes[1]->method);
        self::assertSame('/users/{id}', $routes[1]->path);
        self::assertSame('UserController@show', $routes[1]->handler);
        self::assertSame(['id' => '\\d+'], $routes[1]->constraints);
        self::assertSame(['auth'], $routes[1]->middlewares);

        self::assertSame('DELETE', $routes[5]->method);
        self::assertSame('UserController@delete', $routes[5]->handler);
    }
}
