<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Zephyrus\Routing\Attribute\Middleware as MiddlewareAttribute;
use Zephyrus\Routing\Attribute\Route as RouteAttribute;
use Zephyrus\Routing\Exception\RouteMiddlewareException;
use Zephyrus\Routing\Exception\RouteNotFoundException;
use Zephyrus\Routing\Exception\RouteSignatureException;
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

#[MiddlewareAttribute('web')]
class MiddlewareLayeredController
{
    #[RouteAttribute('/reports', 'GET')]
    public function reports(): void {}

    #[MiddlewareAttribute('audit')]
    #[RouteAttribute('/reports', 'POST', middlewares: ['auth'])]
    public function generate(): void {}
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

    public function testControllerMergesClassMethodAndRouteMiddlewaresBeforeGroupExpansion(): void
    {
        $router = (new Router())
            ->middlewareGroup('web', ['csrf', 'session'])
            ->controller(MiddlewareLayeredController::class);

        $routes = $router->routes()->all();
        self::assertCount(2, $routes);

        self::assertSame(['csrf', 'session'], $routes[0]->middlewares);
        self::assertSame(['csrf', 'session', 'audit', 'auth'], $routes[1]->middlewares);
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

    public function testRouterIntrospectionHelpersMirrorCollectionState(): void
    {
        $router = (new Router())
            ->get('/health', 'HealthController@show')->name('health.show')
            ->post('/users', 'UserController@store')->name('users.store')
            ->get('/anonymous', 'AnonymousController@index');

        self::assertFalse($router->isEmpty());
        self::assertSame(3, $router->count());
        self::assertTrue($router->hasRouteNamed('health.show'));
        self::assertFalse($router->hasRouteNamed('missing.name'));
        self::assertSame(['GET', 'POST'], $router->routeMethods());
        self::assertSame(['/health', '/users', '/anonymous'], $router->routePaths());
        self::assertSame([
            'HealthController@show',
            'UserController@store',
            'AnonymousController@index',
        ], $router->routeHandlers());
        self::assertSame([
            'GET' => 2,
            'POST' => 1,
        ], $router->routeMethodHistogram());
        self::assertSame([], $router->routeMiddlewareHistogram());
        self::assertSame([], $router->routeUniqueMiddlewares());
        self::assertSame([
            'GET' => ['/health', '/anonymous'],
            'POST' => ['/users'],
        ], $router->routePathsByMethod());
        self::assertSame([], $router->routeParameterHistogram());
        self::assertSame([], $router->routeConstrainedParameterHistogram());
        self::assertSame([
            'AnonymousController' => 1,
            'HealthController' => 1,
            'UserController' => 1,
        ], $router->routeControllerHistogram());
        self::assertSame(3, $router->staticRouteCount());
        self::assertSame(0, $router->parameterizedRouteCount());
        self::assertSame(0, $router->constrainedRouteCount());
        self::assertSame([
            'total' => 3,
            'named' => 2,
            'unnamed' => 1,
            'duplicate_names' => 0,
            'methods' => [
                'GET' => 2,
                'POST' => 1,
            ],
            'middlewares' => [],
            'middleware_count' => 0,
            'paths_by_method' => [
                'GET' => ['/health', '/anonymous'],
                'POST' => ['/users'],
            ],
            'parameters' => [],
            'constrained_parameters' => [],
            'controllers' => [
                'AnonymousController' => 1,
                'HealthController' => 1,
                'UserController' => 1,
            ],
            'controller_count' => 3,
            'static_routes' => 3,
            'parameterized_routes' => 0,
            'constrained_routes' => 0,
        ], $router->routeSummary());
        self::assertSame(['health.show', 'users.store'], $router->routeNames());
        self::assertSame([], $router->duplicateRouteNames());

        $named = $router->namedRoutes();
        self::assertArrayHasKey('health.show', $named);
        self::assertSame('/health', $named['health.show']->path);

        $getRoutes = $router->routesByMethod('get');
        self::assertCount(2, $getRoutes);
        self::assertSame('/health', $getRoutes[0]->path);
        self::assertSame('/anonymous', $getRoutes[1]->path);
    }

    public function testRouterAssertNoDuplicateRouteNamesThrowsWhenDuplicatesExist(): void
    {
        $router = (new Router())
            ->get('/a', 'AController@show')->name('users.show')
            ->get('/b', 'BController@show')->name('users.show');

        $this->expectException(RouteSignatureException::class);
        $this->expectExceptionMessage('Duplicate route names detected: users.show');

        $router->assertNoDuplicateRouteNames();
    }

    public function testRouterNamedRoutesThrowsWhenDuplicatesExist(): void
    {
        $router = (new Router())
            ->get('/a', 'AController@show')->name('users.show')
            ->get('/b', 'BController@show')->name('users.show');

        $this->expectException(RouteSignatureException::class);
        $this->expectExceptionMessage('Duplicate route names detected: users.show');

        $router->namedRoutes();
    }

    public function testRouterAssertNoDuplicateRouteNamesReturnsSelfWhenValid(): void
    {
        $router = (new Router())
            ->get('/a', 'AController@show')->name('users.show')
            ->get('/b', 'BController@show')->name('users.index');

        $result = $router->assertNoDuplicateRouteNames();

        self::assertSame($router, $result);
    }

    public function testRouteMiddlewareHistogramAndSummaryReflectMiddlewareUsage(): void
    {
        $router = (new Router())
            ->get('/health', 'HealthController@show', middlewares: ['auth'])
            ->post('/users/{id}', 'UserController@store', ['id' => '\\d+'], ['auth', 'audit']);

        self::assertSame([
            'audit' => 1,
            'auth' => 2,
        ], $router->routeMiddlewareHistogram());
        self::assertSame(['audit', 'auth'], $router->routeUniqueMiddlewares());
        self::assertSame([
            'GET' => ['/health'],
            'POST' => ['/users/{id}'],
        ], $router->routePathsByMethod());
        self::assertSame([
            'id' => 1,
        ], $router->routeParameterHistogram());
        self::assertSame([
            'id' => 1,
        ], $router->routeConstrainedParameterHistogram());
        self::assertSame([
            'HealthController' => 1,
            'UserController' => 1,
        ], $router->routeControllerHistogram());
        self::assertSame(1, $router->staticRouteCount());
        self::assertSame(1, $router->parameterizedRouteCount());
        self::assertSame(1, $router->constrainedRouteCount());

        self::assertSame([
            'total' => 2,
            'named' => 0,
            'unnamed' => 2,
            'duplicate_names' => 0,
            'methods' => [
                'GET' => 1,
                'POST' => 1,
            ],
            'middlewares' => [
                'audit' => 1,
                'auth' => 2,
            ],
            'middleware_count' => 2,
            'paths_by_method' => [
                'GET' => ['/health'],
                'POST' => ['/users/{id}'],
            ],
            'parameters' => [
                'id' => 1,
            ],
            'constrained_parameters' => [
                'id' => 1,
            ],
            'controllers' => [
                'HealthController' => 1,
                'UserController' => 1,
            ],
            'controller_count' => 2,
            'static_routes' => 1,
            'parameterized_routes' => 1,
            'constrained_routes' => 1,
        ], $router->routeSummary());
    }

    // -----------------------------------------------------------------------
    // joinPath edge cases (both/left/right empty)
    // -----------------------------------------------------------------------

    public function testGroupJoinPathBothSegmentsEmpty(): void
    {
        // prefix '/' and path '/' both trim to '' → joined path must be '/'
        $router = (new Router())->group('/', static fn (Router $r): Router => $r
            ->get('/', 'RootController@index'));

        $route = $router->routes()->all()[0];

        self::assertSame('/', $route->path);
    }

    public function testGroupJoinPathPrefixOnlyEmpty(): void
    {
        // prefix '/' trims to '' but path '/health' has content → '/health'
        $router = (new Router())->group('/', static fn (Router $r): Router => $r
            ->get('/health', 'HealthController@show'));

        $route = $router->routes()->all()[0];

        self::assertSame('/health', $route->path);
    }

    public function testGroupJoinPathSuffixOnlyEmpty(): void
    {
        // prefix '/admin' has content but path '/' trims to '' → '/admin'
        $router = (new Router())->group('/admin', static fn (Router $r): Router => $r
            ->get('/', 'AdminController@index'));

        $route = $router->routes()->all()[0];

        self::assertSame('/admin', $route->path);
    }

    public function testWithTrailingSlashToleranceFalseUsesStrictMatching(): void
    {
        $router = (new Router())
            ->withTrailingSlashTolerance(false)
            ->get('/users', 'UserController@index');

        self::assertFalse($router->isTrailingSlashTolerant());
        self::assertSame('/users', $router->routes()->match('GET', '/users')->route->path);

        $this->expectException(RouteNotFoundException::class);
        $router->routes()->match('GET', '/users/');
    }

    public function testStrictTrailingSlashesIsConvenienceAlias(): void
    {
        $router = (new Router())
            ->strictTrailingSlashes()
            ->get('/health', 'HealthController@show');

        self::assertFalse($router->isTrailingSlashTolerant());

        $this->expectException(RouteNotFoundException::class);
        $router->routes()->match('GET', '/health/');
    }

    public function testWithTrailingSlashToleranceIsImmutableAndDoesNotMutateSourceRouter(): void
    {
        $base = (new Router())->get('/users', 'UserController@index');
        $strict = $base->withTrailingSlashTolerance(false);

        self::assertTrue($base->isTrailingSlashTolerant());
        self::assertFalse($strict->isTrailingSlashTolerant());
        self::assertSame('/users', $strict->routes()->match('GET', '/users')->route->path);
        self::assertSame('/users', $base->routes()->match('GET', '/users/')->route->path);
    }
}
