<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Zephyrus\Routing\Exception\MethodNotAllowedException;
use Zephyrus\Routing\Exception\RouteNotFoundException;
use Zephyrus\Routing\Exception\RouteSignatureException;
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
        $this->expectExceptionMessage('Method not allowed for /users. Allowed: GET, HEAD, POST');

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

    public function testMatchAllowsHeadRequestsAgainstGetRoutes(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/health', 'HealthController@show'));

        $match = $collection->match('HEAD', '/health');

        self::assertSame('GET', $match->route->method);
        self::assertSame('/health', $match->route->path);
    }

    public function testMethodNotAllowedIncludesHeadWhenGetRouteExists(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/health', 'HealthController@show'));

        try {
            $collection->match('DELETE', '/health');
            self::fail('Expected MethodNotAllowedException to be thrown');
        } catch (MethodNotAllowedException $exception) {
            self::assertSame(['GET', 'HEAD'], $exception->allowedMethods);
        }
    }

    public function testMatchThrowsRouteSignatureExceptionForInvalidConstraintPattern(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/users/{id}', 'UserController@show', ['id' => '[0-9+']));

        $this->expectException(RouteSignatureException::class);
        $this->expectExceptionMessage('Invalid route constraint pattern for parameter "id" on route "/users/{id}"');

        $collection->match('GET', '/users/42');
    }

    public function testWithLastRouteNameOnEmptyCollectionReturnsUnchanged(): void
    {
        $collection = new RouteCollection();
        $result = $collection->withLastRouteName('orphan');

        self::assertSame([], $result->all());
    }

    public function testFindByNameReturnsNullWhenNameNotFound(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/health', 'HealthController@show', name: 'health'));

        self::assertNull($collection->findByName('nonexistent'));
    }

    /**
     * Segment-count mismatch between route and path triggers the early null
     * return in extractParameters (L105).
     */
    public function testMatchSkipsRouteWhenSegmentCountsDiffer(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/users', 'UserController@index'));

        $this->expectException(RouteNotFoundException::class);
        $this->expectExceptionMessage('No route matched GET /users/extra');

        $collection->match('GET', '/users/extra');
    }

    /** Root path '/' produces an empty segments array (L143). */
    public function testMatchRootPathReturnsEmptyParameters(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/', 'HomeController@index'));

        $match = $collection->match('GET', '/');

        self::assertSame('/', $match->route->path);
        self::assertSame([], $match->parameters);
    }

    public function testCollectionCountAndIsEmptyReflectRouteMembership(): void
    {
        $collection = new RouteCollection();

        self::assertTrue($collection->isEmpty());
        self::assertSame(0, $collection->count());

        $collection->add(Route::define('GET', '/health', 'HealthController@show'));

        self::assertFalse($collection->isEmpty());
        self::assertSame(1, $collection->count());
    }

    public function testNamesAndHasNamedExposeNamedRouteState(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/health', 'HealthController@show', name: 'health.show'));
        $collection->add(Route::define('POST', '/users', 'UserController@store', name: 'users.store'));
        $collection->add(Route::define('GET', '/anonymous', 'AnonymousController@index'));

        self::assertSame(['health.show', 'users.store'], $collection->names());
        self::assertTrue($collection->hasNamed('health.show'));
        self::assertFalse($collection->hasNamed('missing.name'));
    }

    public function testDuplicateRouteNamesReturnsSortedUniqueDuplicates(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/a', 'AController@show', name: 'dup.alpha'));
        $collection->add(Route::define('GET', '/b', 'BController@show', name: 'dup.beta'));
        $collection->add(Route::define('GET', '/c', 'CController@show', name: 'dup.alpha'));
        $collection->add(Route::define('GET', '/d', 'DController@show', name: 'dup.beta'));
        $collection->add(Route::define('GET', '/e', 'EController@show', name: 'dup.alpha'));

        self::assertSame(['dup.alpha', 'dup.beta'], $collection->duplicateRouteNames());
    }

    public function testAssertNoDuplicateRouteNamesThrowsWhenDuplicatesExist(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/a', 'AController@show', name: 'users.show'));
        $collection->add(Route::define('GET', '/b', 'BController@show', name: 'users.show'));

        $this->expectException(RouteSignatureException::class);
        $this->expectExceptionMessage('Duplicate route names detected: users.show');

        $collection->assertNoDuplicateRouteNames();
    }

    public function testAssertNoDuplicateRouteNamesSucceedsWhenNamesUnique(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/a', 'AController@show', name: 'users.show'));
        $collection->add(Route::define('GET', '/b', 'BController@show', name: 'users.index'));

        $collection->assertNoDuplicateRouteNames();

        self::assertTrue(true);
    }

    public function testMethodsReturnsSortedUniqueMethods(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('POST', '/users', 'UserController@store'));
        $collection->add(Route::define('GET', '/users', 'UserController@index'));
        $collection->add(Route::define('GET', '/health', 'HealthController@show'));

        self::assertSame(['GET', 'POST'], $collection->methods());
    }

    public function testPathsReturnsRegisteredPathsInOrder(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/health', 'HealthController@show'));
        $collection->add(Route::define('POST', '/users', 'UserController@store'));

        self::assertSame(['/health', '/users'], $collection->paths());
    }

    public function testNamedRoutesReturnsNameIndexedMap(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/health', 'HealthController@show', name: 'health.show'));
        $collection->add(Route::define('POST', '/users', 'UserController@store', name: 'users.store'));

        $named = $collection->namedRoutes();

        self::assertArrayHasKey('health.show', $named);
        self::assertArrayHasKey('users.store', $named);
        self::assertSame('/health', $named['health.show']->path);
    }

    public function testNamedRoutesThrowsOnDuplicates(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/a', 'AController@show', name: 'dup'));
        $collection->add(Route::define('GET', '/b', 'BController@show', name: 'dup'));

        $this->expectException(RouteSignatureException::class);
        $this->expectExceptionMessage('Duplicate route names detected: dup');

        $collection->namedRoutes();
    }

    public function testRoutesByMethodFiltersCaseInsensitively(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/health', 'HealthController@show'));
        $collection->add(Route::define('POST', '/users', 'UserController@store'));
        $collection->add(Route::define('GET', '/users', 'UserController@index'));

        $getRoutes = $collection->routesByMethod('get');

        self::assertCount(2, $getRoutes);
        self::assertSame('/health', $getRoutes[0]->path);
        self::assertSame('/users', $getRoutes[1]->path);
    }

    public function testHandlersReturnsRegisteredHandlersInOrder(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/health', 'HealthController@show'));
        $collection->add(Route::define('POST', '/users', 'UserController@store'));

        self::assertSame([
            'HealthController@show',
            'UserController@store',
        ], $collection->handlers());
    }

    public function testMethodHistogramAggregatesCountsByMethod(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/health', 'HealthController@show'));
        $collection->add(Route::define('GET', '/users', 'UserController@index'));
        $collection->add(Route::define('POST', '/users', 'UserController@store'));
        $collection->add(Route::define('DELETE', '/users/{id}', 'UserController@delete'));

        self::assertSame([
            'DELETE' => 1,
            'GET' => 2,
            'POST' => 1,
        ], $collection->methodHistogram());
    }

    public function testMiddlewareHistogramAggregatesUsageAcrossRoutes(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/health', 'HealthController@show', middlewares: ['auth']));
        $collection->add(Route::define('GET', '/users', 'UserController@index', middlewares: ['auth', 'audit']));
        $collection->add(Route::define('POST', '/users', 'UserController@store', middlewares: ['audit']));

        self::assertSame([
            'audit' => 2,
            'auth' => 2,
        ], $collection->middlewareHistogram());
    }

    public function testUniqueMiddlewaresReturnsSortedNames(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/health', 'HealthController@show', middlewares: ['auth']));
        $collection->add(Route::define('POST', '/users', 'UserController@store', middlewares: ['audit', 'auth']));

        self::assertSame(['audit', 'auth'], $collection->uniqueMiddlewares());
    }

    public function testPathsByMethodGroupsPathsByHttpMethod(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('POST', '/users', 'UserController@store'));
        $collection->add(Route::define('GET', '/health', 'HealthController@show'));
        $collection->add(Route::define('GET', '/users', 'UserController@index'));

        self::assertSame([
            'GET' => ['/health', '/users'],
            'POST' => ['/users'],
        ], $collection->pathsByMethod());
    }

    public function testParameterHistogramAggregatesPathParameterUsage(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/users/{id}', 'UserController@show'));
        $collection->add(Route::define('GET', '/teams/{team}/users/{id}', 'TeamUserController@show'));
        $collection->add(Route::define('POST', '/teams/{team}/users', 'TeamUserController@store'));

        self::assertSame([
            'id' => 2,
            'team' => 2,
        ], $collection->parameterHistogram());
    }

    public function testConstrainedParameterHistogramCountsConstraintUsage(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/users/{id}', 'UserController@show', ['id' => '\\d+']));
        $collection->add(Route::define('GET', '/teams/{team}/users/{id}', 'TeamUserController@show', [
            'team' => '[a-z]+',
            'id' => '\\d+',
        ]));

        self::assertSame([
            'id' => 2,
            'team' => 1,
        ], $collection->constrainedParameterHistogram());
    }

    public function testSummaryReturnsRouteRegistryMetrics(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/health', 'HealthController@show', middlewares: ['auth'], name: 'health.show'));
        $collection->add(Route::define('POST', '/users/{id}', 'UserController@store', ['id' => '\\d+'], ['auth', 'audit'], 'users.store'));
        $collection->add(Route::define('GET', '/users/{id}/posts/{postId}', 'UserController@posts', ['postId' => '\\d+'], ['audit']));

        self::assertSame([
            'total' => 3,
            'named' => 2,
            'unnamed' => 1,
            'duplicate_names' => 0,
            'methods' => [
                'GET' => 2,
                'POST' => 1,
            ],
            'middlewares' => [
                'audit' => 2,
                'auth' => 2,
            ],
            'middleware_count' => 2,
            'paths_by_method' => [
                'GET' => ['/health', '/users/{id}/posts/{postId}'],
                'POST' => ['/users/{id}'],
            ],
            'parameters' => [
                'id' => 2,
                'postId' => 1,
            ],
            'constrained_parameters' => [
                'id' => 1,
                'postId' => 1,
            ],
        ], $collection->summary());
    }
}
