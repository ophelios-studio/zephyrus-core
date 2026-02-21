<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Zephyrus\Routing\Route;
use Zephyrus\Routing\RouteCollection;

final class RouteCollectionTest extends TestCase
{
    public function testMatchReturnsFirstRouteWithSameMethodAndPath(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/users', 'UserController@index'));
        $collection->add(Route::define('POST', '/users', 'UserController@store'));

        $route = $collection->match('get', '/users');

        self::assertSame('GET', $route->method);
        self::assertSame('UserController@index', $route->handler);
    }

    public function testMatchNormalizesPathInput(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', 'health', 'HealthController@show'));

        $route = $collection->match('GET', 'health');

        self::assertSame('/health', $route->path);
    }

    public function testMatchThrowsRuntimeExceptionWhenNoRouteMatches(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/users', 'UserController@index'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No route matched DELETE /users');

        $collection->match('DELETE', '/users');
    }
}
