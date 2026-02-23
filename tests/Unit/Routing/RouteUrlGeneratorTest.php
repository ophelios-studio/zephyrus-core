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

    public function testGenerateAppendsSortedQueryStringWhenProvided(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/users/{id}', 'UserController@show', name: 'users.show'));

        $generator = new RouteUrlGenerator($routes);

        $path = $generator->generate('users.show', ['id' => 42], ['expand' => 'roles', 'page' => 2]);

        self::assertSame('/users/42?expand=roles&page=2', $path);
    }

    public function testGenerateEncodesArrayAndSpecialCharactersInQuery(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/search', 'SearchController@index', name: 'search.index'));

        $generator = new RouteUrlGenerator($routes);

        $path = $generator->generate('search.index', query: [
            'q' => 'hello world',
            'tags' => ['php', 'zephyrus 2'],
        ]);

        self::assertSame('/search?q=hello%20world&tags%5B0%5D=php&tags%5B1%5D=zephyrus%202', $path);
    }

    public function testGeneratePrependsBaseUrlWhenConfigured(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/users/{id}', 'UserController@show', name: 'users.show'));

        $generator = new RouteUrlGenerator($routes, 'https://example.com');

        $url = $generator->generate('users.show', ['id' => 42], ['expand' => 'roles']);

        self::assertSame('https://example.com/users/42?expand=roles', $url);
    }

    public function testGenerateNormalizesTrailingSlashFromBaseUrl(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show', name: 'health.show'));

        $generator = new RouteUrlGenerator($routes, 'https://example.com/');

        $url = $generator->generate('health.show');

        self::assertSame('https://example.com/health', $url);
    }

    public function testGenerateSignedUsesInjectedSignature(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/users/{id}', 'UserController@show', name: 'users.show'));

        $signature = new \Zephyrus\Routing\RouteSignature('secret-key');
        $generator = new RouteUrlGenerator($routes, 'https://example.com', $signature);

        $signedUrl = $generator->generateSigned('users.show', ['id' => 42], ['expand' => 'roles']);

        self::assertTrue($signature->verify($signedUrl));
    }

    public function testGenerateSignedThrowsWhenSignerIsMissing(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/users/{id}', 'UserController@show', name: 'users.show'));

        $generator = new RouteUrlGenerator($routes);

        $this->expectException(RouteUrlGenerationException::class);
        $this->expectExceptionMessage('Cannot generate signed URL without a RouteSignature instance');

        $generator->generateSigned('users.show', ['id' => 42]);
    }

    public function testGenerateThrowsWhenUnexpectedRouteParameterProvided(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/users/{id}', 'UserController@show', name: 'users.show'));

        $generator = new RouteUrlGenerator($routes);

        $this->expectException(RouteUrlGenerationException::class);
        $this->expectExceptionMessage('Unexpected route parameter "slug" for route "users.show"');

        $generator->generate('users.show', ['id' => 42, 'slug' => 'alice']);
    }

    public function testGenerateThrowsWhenParameterDoesNotSatisfyConstraint(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/users/{id}', 'UserController@show', ['id' => '\\d+'], name: 'users.show'));

        $generator = new RouteUrlGenerator($routes);

        $this->expectException(RouteUrlGenerationException::class);
        $this->expectExceptionMessage('Route parameter "id" value "abc" does not satisfy constraint "\\d+" for route "users.show"');

        $generator->generate('users.show', ['id' => 'abc']);
    }

    public function testGenerateThrowsWhenConstraintPatternIsInvalid(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/users/{id}', 'UserController@show', ['id' => '[0-9+'], name: 'users.show'));

        $generator = new RouteUrlGenerator($routes);

        $this->expectException(RouteUrlGenerationException::class);
        $this->expectExceptionMessage('Invalid constraint pattern "[0-9+" for parameter "id" on route "users.show"');

        $generator->generate('users.show', ['id' => '42']);
    }
}
