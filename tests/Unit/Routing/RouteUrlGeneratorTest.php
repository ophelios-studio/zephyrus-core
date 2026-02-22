<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Zephyrus\Routing\Exception\RouteUrlGenerationException;
use Zephyrus\Routing\Route;
use Zephyrus\Routing\RouteCollection;
use Zephyrus\Routing\RouteUrlGenerator;

final class RouteUrlGeneratorTest extends TestCase
{
    public function testGenerateBuildsPathFromNamedRouteAndParameters(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/users/{id}', 'UserController@show', name: 'users.show'));

        $generator = new RouteUrlGenerator($routes);

        $path = $generator->generate('users.show', ['id' => 42]);

        self::assertSame('/users/42', $path);
    }

    public function testGenerateEncodesParameterValues(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/tags/{name}', 'TagController@show', name: 'tags.show'));

        $generator = new RouteUrlGenerator($routes);

        $path = $generator->generate('tags.show', ['name' => 'hello world']);

        self::assertSame('/tags/hello%20world', $path);
    }

    public function testGenerateThrowsWhenRouteNameUnknown(): void
    {
        $generator = new RouteUrlGenerator(new RouteCollection());

        $this->expectException(RouteUrlGenerationException::class);
        $this->expectExceptionMessage('Unknown route name: users.show');

        $generator->generate('users.show');
    }

    public function testGenerateThrowsWhenParameterMissing(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/users/{id}', 'UserController@show', name: 'users.show'));

        $generator = new RouteUrlGenerator($routes);

        $this->expectException(RouteUrlGenerationException::class);
        $this->expectExceptionMessage('Missing route parameter "id" for route "users.show"');

        $generator->generate('users.show');
    }
}
