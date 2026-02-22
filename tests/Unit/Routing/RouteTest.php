<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Zephyrus\Routing\Route;

final class RouteTest extends TestCase
{
    public function testDefineNormalizesMethodAndPath(): void
    {
        $route = Route::define('get', 'users', 'UserController@index');

        self::assertSame('GET', $route->method);
        self::assertSame('/users', $route->path);
        self::assertSame('UserController@index', $route->handler);
    }

    public function testDefineKeepsRootPathAsSlash(): void
    {
        $route = Route::define('post', '/', 'HealthController@ping');

        self::assertSame('/', $route->path);
    }

    public function testMatchesMethodIsCaseInsensitive(): void
    {
        $route = Route::define('delete', '/users/{id}', 'UserController@delete');

        self::assertTrue($route->matchesMethod('DELETE'));
        self::assertTrue($route->matchesMethod('delete'));
        self::assertFalse($route->matchesMethod('PATCH'));
    }

    public function testDefineAcceptsRouteMiddlewareNames(): void
    {
        $route = Route::define('GET', '/users', 'UserController@index', [], ['auth', 'audit']);

        self::assertSame(['auth', 'audit'], $route->middlewares);
    }

    public function testWithNameReturnsNamedClone(): void
    {
        $route = Route::define('GET', '/users', 'UserController@index');
        $named = $route->withName('users.index');

        self::assertNull($route->name);
        self::assertSame('users.index', $named->name);
    }
}
