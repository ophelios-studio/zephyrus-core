<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Zephyrus\Routing\Attribute\Route as RouteAttribute;
use Zephyrus\Routing\Exception\RouteMiddlewareException;
use Zephyrus\Routing\Router;

// ---------------------------------------------------------------------------
// Fixture controllers for Router::controller() tests
// ---------------------------------------------------------------------------

class UserAttributeController
{
    #[RouteAttribute('/users', 'GET', name: 'users.index')]
    public function index(): void {}

    #[RouteAttribute('/users/{id}', 'GET', constraints: ['id' => '\d+'], name: 'users.show')]
    public function show(): void {}

    #[RouteAttribute('/users', 'POST')]
    public function store(): void {}
}

class NoAttributeController
{
    public function index(): void {}
}

class MiddlewareGroupAttributeController
{
    #[RouteAttribute('/dashboard', 'GET', middlewares: ['web'])]
    public function dashboard(): void {}
}

// ---------------------------------------------------------------------------

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

    public function testGroupAppliesPrefixAndSharedMiddlewares(): void
    {
        $router = (new Router())->group('/api/v1', static fn (Router $router): Router => $router
            ->get('/health', 'HealthController@show')
            ->get('/users/{id}', 'UserController@show', ['id' => '\\d+'], ['auth']), ['api']);

        $routes = $router->routes()->all();

        self::assertCount(2, $routes);
        self::assertSame('/api/v1/health', $routes[0]->path);
        self::assertSame(['api'], $routes[0]->middlewares);

        self::assertSame('/api/v1/users/{id}', $routes[1]->path);
        self::assertSame(['api', 'auth'], $routes[1]->middlewares);
    }

    public function testNameAssignsNameToMostRecentlyRegisteredRoute(): void
    {
        $router = (new Router())
            ->get('/health', 'HealthController@show')
            ->name('health.show');

        $route = $router->routes()->findByName('health.show');

        self::assertNotNull($route);
        self::assertSame('/health', $route->path);
    }

    public function testControllerRegistersAttributeDefinedRoutes(): void
    {
        $router = (new Router())->controller(UserAttributeController::class);

        $routes = $router->routes()->all();

        self::assertCount(3, $routes);
    }

    public function testControllerHandlerStringsPointToClass(): void
    {
        $router = (new Router())->controller(UserAttributeController::class);

        $handlers = array_map(fn ($r) => $r->handler, $router->routes()->all());

        self::assertContains(UserAttributeController::class . '@index', $handlers);
        self::assertContains(UserAttributeController::class . '@show', $handlers);
        self::assertContains(UserAttributeController::class . '@store', $handlers);
    }

    public function testControllerPreservesNameForLookup(): void
    {
        $router = (new Router())->controller(UserAttributeController::class);

        $route = $router->routes()->findByName('users.index');

        self::assertNotNull($route);
        self::assertSame('/users', $route->path);
        self::assertSame('GET', $route->method);
    }

    public function testControllerPreservesConstraints(): void
    {
        $router = (new Router())->controller(UserAttributeController::class);

        $route = $router->routes()->findByName('users.show');

        self::assertNotNull($route);
        self::assertSame(['id' => '\d+'], $route->constraints);
    }

    public function testControllerWithNoAttributesYieldsNoRoutes(): void
    {
        $router = (new Router())->controller(NoAttributeController::class);

        self::assertCount(0, $router->routes()->all());
    }

    public function testControllerCanBeCombinedWithFluentRegistration(): void
    {
        $router = (new Router())
            ->get('/health', 'HealthController@show')
            ->controller(UserAttributeController::class);

        self::assertCount(4, $router->routes()->all());
    }

    public function testMiddlewareGroupExpandsOnRouteRegistration(): void
    {
        $router = (new Router())
            ->middlewareGroup('web', ['csrf', 'session'])
            ->get('/profile', 'ProfileController@show', middlewares: ['web', 'auth']);

        $route = $router->routes()->all()[0];

        self::assertSame(['csrf', 'session', 'auth'], $route->middlewares);
    }

    public function testMiddlewareGroupCanReferenceOtherGroups(): void
    {
        $router = (new Router())
            ->middlewareGroup('web', ['csrf', 'session'])
            ->middlewareGroup('secure-web', ['web', 'auth'])
            ->get('/settings', 'SettingsController@index', middlewares: ['secure-web']);

        $route = $router->routes()->all()[0];

        self::assertSame(['csrf', 'session', 'auth'], $route->middlewares);
    }

    public function testMiddlewareGroupExpandsForAttributeDiscoveredRoutes(): void
    {
        $router = (new Router())
            ->middlewareGroup('web', ['csrf', 'session'])
            ->controller(MiddlewareGroupAttributeController::class);

        $route = $router->routes()->all()[0];

        self::assertSame(['csrf', 'session'], $route->middlewares);
    }

    public function testMiddlewareGroupDetectsCircularReferences(): void
    {
        $this->expectException(RouteMiddlewareException::class);
        $this->expectExceptionMessage('Circular middleware group reference detected');

        (new Router())
            ->middlewareGroup('a', ['b'])
            ->middlewareGroup('b', ['a'])
            ->get('/loop', 'LoopController@index', middlewares: ['a']);
    }

    // -----------------------------------------------------------------------
    // group() — route name propagation
    // -----------------------------------------------------------------------

    public function testGroupPreservesRouteNameDefinedInsideGroup(): void
    {
        $router = (new Router())->group('/api/v1', static fn (Router $r): Router => $r
            ->get('/health', 'HealthController@show')->name('health.show'));

        $route = $router->routes()->findByName('health.show');

        self::assertNotNull($route);
        self::assertSame('/api/v1/health', $route->path);
        self::assertSame('GET', $route->method);
    }

    public function testGroupPreservesMultipleNamesDefinedInsideGroup(): void
    {
        $router = (new Router())->group('/api', static fn (Router $r): Router => $r
            ->get('/users', 'UserController@index')->name('users.index')
            ->get('/users/{id}', 'UserController@show', ['id' => '\d+'])->name('users.show'));

        self::assertNotNull($router->routes()->findByName('users.index'));
        self::assertSame('/api/users', $router->routes()->findByName('users.index')->path);

        self::assertNotNull($router->routes()->findByName('users.show'));
        self::assertSame('/api/users/{id}', $router->routes()->findByName('users.show')->path);
    }

    public function testGroupLeavesUnnamedRoutesWithNullName(): void
    {
        $router = (new Router())->group('/api', static fn (Router $r): Router => $r
            ->get('/ping', 'PingController@ping'));

        $route = $router->routes()->all()[0];

        self::assertNull($route->name);
    }

    public function testGroupWithNamePrefixPrependsToNamedRoutes(): void
    {
        $router = (new Router())->group(
            '/api/v1',
            static fn (Router $r): Router => $r
                ->get('/users', 'UserController@index')->name('users.index')
                ->get('/posts', 'PostController@index')->name('posts.index'),
            namePrefix: 'api.',
        );

        self::assertNotNull($router->routes()->findByName('api.users.index'));
        self::assertSame('/api/v1/users', $router->routes()->findByName('api.users.index')->path);

        self::assertNotNull($router->routes()->findByName('api.posts.index'));
        self::assertSame('/api/v1/posts', $router->routes()->findByName('api.posts.index')->path);
    }

    public function testGroupWithNamePrefixDoesNotNameUnnamedRoutes(): void
    {
        $router = (new Router())->group(
            '/api',
            static fn (Router $r): Router => $r->get('/ping', 'PingController@ping'),
            namePrefix: 'api.',
        );

        $route = $router->routes()->all()[0];

        self::assertNull($route->name);
    }

    public function testGroupWithNamePrefixAndSharedMiddlewaresCombineCorrectly(): void
    {
        $router = (new Router())->group(
            '/admin',
            static fn (Router $r): Router => $r
                ->get('/dashboard', 'DashboardController@index')->name('dashboard')
                ->get('/settings', 'SettingsController@index', middlewares: ['audit']),
            middlewares: ['auth'],
            namePrefix: 'admin.',
        );

        $routes = $router->routes()->all();

        self::assertCount(2, $routes);

        // Named route gets prefixed name and shared middleware
        $dashboard = $router->routes()->findByName('admin.dashboard');
        self::assertNotNull($dashboard);
        self::assertSame(['auth'], $dashboard->middlewares);

        // Unnamed route stays unnamed, merged middlewares apply
        self::assertNull($routes[1]->name);
        self::assertSame(['auth', 'audit'], $routes[1]->middlewares);
    }

    public function testAddAcceptsExplicitNameParameter(): void
    {
        $router = (new Router())->add('GET', '/ping', 'PingController@ping', name: 'ping');

        $route = $router->routes()->findByName('ping');

        self::assertNotNull($route);
        self::assertSame('/ping', $route->path);
    }
}
