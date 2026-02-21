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

        $match = $collection->match('get', '/users');

        self::assertSame('GET', $match->route->method);
        self::assertSame('UserController@index', $match->route->handler);
        self::assertSame([], $match->parameters);
    }

    public function testMatchNormalizesPathInput(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', 'health', 'HealthController@show'));

        $match = $collection->match('GET', 'health');

        self::assertSame('/health', $match->route->path);
    }

    public function testMatchExtractsPathParameters(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/users/{id}', 'UserController@show'));

        $match = $collection->match('GET', '/users/42');

        self::assertSame('42', $match->parameter('id'));
    }

    public function testMatchAppliesParameterConstraints(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/users/{id}', 'UserController@show', ['id' => '\\d+']));

        $match = $collection->match('GET', '/users/1337');

        self::assertSame('1337', $match->parameter('id'));
    }

    public function testMatchThrowsRuntimeExceptionWhenConstraintFails(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/users/{id}', 'UserController@show', ['id' => '\\d+']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No route matched GET /users/abc');

        $collection->match('GET', '/users/abc');
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
