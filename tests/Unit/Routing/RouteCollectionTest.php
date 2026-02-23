<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Zephyrus\Routing\Exception\MethodNotAllowedException;
use Zephyrus\Routing\Exception\RouteNotFoundException;
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

    public function testMatchThrowsRouteNotFoundExceptionWhenConstraintFails(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/users/{id}', 'UserController@show', ['id' => '\\d+']));

        $this->expectException(RouteNotFoundException::class);
        $this->expectExceptionMessage('No route matched GET /users/abc');

        $collection->match('GET', '/users/abc');
    }

    public function testMatchThrowsMethodNotAllowedExceptionWhenPathMatchesWithDifferentMethod(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/users', 'UserController@index'));
        $collection->add(Route::define('POST', '/users', 'UserController@store'));

        $this->expectException(MethodNotAllowedException::class);
        $this->expectExceptionMessage('Method not allowed for /users. Allowed: GET, POST');

        $collection->match('DELETE', '/users');
    }

    public function testMatchThrowsRouteNotFoundExceptionWhenNoRouteMatches(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/users', 'UserController@index'));

        $this->expectException(RouteNotFoundException::class);
        $this->expectExceptionMessage('No route matched GET /projects');

        $collection->match('GET', '/projects');
    }

    public function testMatchIgnoresTrailingSlashAndQueryString(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/users/{id}', 'UserController@show'));

        $match = $collection->match('GET', '/users/42/?expand=roles');

        self::assertSame('42', $match->parameter('id'));
    }

    public function testMatchDecodesEncodedPathSegmentsBeforeConstraintChecks(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/tags/{name}', 'TagController@show', ['name' => '[a-z ]+']));

        $match = $collection->match('GET', '/tags/hello%20world');

        self::assertSame('hello world', $match->parameter('name'));
    }

    public function testFindByNameReturnsNamedRouteWhenPresent(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/users', 'UserController@index', name: 'users.index'));

        $route = $collection->findByName('users.index');

        self::assertNotNull($route);
        self::assertSame('/users', $route->path);
    }

    public function testMatchTreatsEncodedSlashAsPartOfSingleSegment(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/files/{path}', 'FileController@show', ['path' => '.+']));

        $match = $collection->match('GET', '/files/a%2Fb');

        self::assertSame('a/b', $match->parameter('path'));
    }

    public function testMatchKeepsPlusCharacterLiteralInPathSegments(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/tags/{name}', 'TagController@show', ['name' => '[a-z+]+' ]));

        $match = $collection->match('GET', '/tags/c++');

        self::assertSame('c++', $match->parameter('name'));
    }
}
