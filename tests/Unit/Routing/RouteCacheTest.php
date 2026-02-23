<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Zephyrus\Routing\Exception\RouteCacheException;
use Zephyrus\Routing\Route;
use Zephyrus\Routing\RouteCache;
use Zephyrus\Routing\RouteCollection;

final class RouteCacheTest extends TestCase
{
    private string $cacheFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheFile = sys_get_temp_dir() . '/zephyrus2-route-cache-' . uniqid('', true) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->cacheFile)) {
            @unlink($this->cacheFile);
        }

        parent::tearDown();
    }

    public function testSaveAndLoadRoundTrip(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/users/{id}', 'UserController@show', ['id' => '\\d+'], ['auth'], 'users.show'));
        $routes->add(Route::define('POST', '/users', 'UserController@store'));

        $cache = new RouteCache($this->cacheFile);
        $cache->save($routes);

        $loaded = $cache->load();

        self::assertCount(2, $loaded->all());
        self::assertSame('users.show', $loaded->all()[0]->name);
        self::assertSame(['auth'], $loaded->all()[0]->middlewares);
        self::assertSame('POST', $loaded->all()[1]->method);
    }

    public function testLoadThrowsWhenCacheFileMissing(): void
    {
        $cache = new RouteCache($this->cacheFile);

        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage('Route cache file does not exist');

        $cache->load();
    }

    public function testLoadThrowsOnInvalidJsonPayload(): void
    {
        file_put_contents($this->cacheFile, '{not-json}');

        $cache = new RouteCache($this->cacheFile);

        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage('Unable to decode route cache payload');

        $cache->load();
    }

    public function testLoadThrowsWhenRoutesSectionMissing(): void
    {
        file_put_contents($this->cacheFile, json_encode(['meta' => ['version' => 1]], JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage('Route cache payload missing routes section');

        $cache->load();
    }

    public function testLoadThrowsOnHashMismatch(): void
    {
        $payload = [
            'meta' => [
                'version' => 1,
                'routes_hash' => str_repeat('a', 64),
            ],
            'routes' => [
                [
                    'method' => 'GET',
                    'path' => '/health',
                    'handler' => 'HealthController@show',
                    'constraints' => [],
                    'middlewares' => [],
                    'name' => 'health.show',
                ],
            ],
        ];

        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage('Route cache payload hash mismatch');

        $cache->load();
    }

    public function testLoadThrowsOnUnsupportedMetadataVersion(): void
    {
        $payload = [
            'meta' => [
                'version' => 2,
                'routes_hash' => hash('sha256', json_encode([], JSON_THROW_ON_ERROR)),
            ],
            'routes' => [],
        ];

        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage('Route cache payload contains unsupported metadata version');

        $cache->load();
    }

    public function testSaveCreatesMissingCacheDirectory(): void
    {
        $cacheDirectory = sys_get_temp_dir() . '/zephyrus2-route-cache-' . uniqid('', true);
        $cacheFile = $cacheDirectory . '/routes/cache.json';

        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show'));

        $cache = new RouteCache($cacheFile);
        $cache->save($routes);

        self::assertFileExists($cacheFile);

        @unlink($cacheFile);
        @rmdir(dirname($cacheFile));
        @rmdir($cacheDirectory);
    }

    public function testLoadThrowsOnInvalidMetadataHashFormat(): void
    {
        $payload = [
            'meta' => [
                'version' => 1,
                'routes_hash' => 'not-a-sha256',
            ],
            'routes' => [],
        ];

        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage('Route cache payload contains invalid metadata hash format');

        $cache->load();
    }

    public function testLoadThrowsWhenConstraintMapContainsNonStringValues(): void
    {
        $routes = [[
            'method' => 'GET',
            'path' => '/users/{id}',
            'handler' => 'UserController@show',
            'constraints' => ['id' => 123],
            'middlewares' => [],
            'name' => null,
        ]];

        $payload = [
            'meta' => [
                'version' => 1,
                'routes_hash' => hash('sha256', json_encode($routes, JSON_THROW_ON_ERROR)),
            ],
            'routes' => $routes,
        ];

        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage('Route cache entry contains invalid constraints map');

        $cache->load();
    }

    public function testLoadThrowsWhenMiddlewaresContainNonStringValues(): void
    {
        $routes = [[
            'method' => 'GET',
            'path' => '/users',
            'handler' => 'UserController@index',
            'constraints' => [],
            'middlewares' => ['auth', 100],
            'name' => 'users.index',
        ]];

        $payload = [
            'meta' => [
                'version' => 1,
                'routes_hash' => hash('sha256', json_encode($routes, JSON_THROW_ON_ERROR)),
            ],
            'routes' => $routes,
        ];

        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage('Route cache entry contains invalid middlewares list');

        $cache->load();
    }

    public function testLoadThrowsWhenDecodedPayloadIsNotArray(): void
    {
        file_put_contents($this->cacheFile, 'null');

        $cache = new RouteCache($this->cacheFile);

        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage('Route cache payload must decode to an array');

        $cache->load();
    }

    public function testLoadSucceedsWithAbsentMeta(): void
    {
        // A cache file with no 'meta' key — all meta checks are skipped.
        $payload = ['routes' => []];

        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);
        $collection = $cache->load();

        self::assertCount(0, $collection->all());
    }

    public function testLoadThrowsWhenMetaRoutesHashIsNotString(): void
    {
        $payload = [
            'meta' => ['version' => 1, 'routes_hash' => 999],
            'routes' => [],
        ];

        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage('Route cache payload contains invalid metadata');

        $cache->load();
    }

    public function testLoadThrowsWhenRouteEntryIsNotArray(): void
    {
        $routes = ['not-an-array-entry'];

        $payload = [
            'meta' => [
                'version' => 1,
                'routes_hash' => hash('sha256', json_encode($routes, JSON_THROW_ON_ERROR)),
            ],
            'routes' => $routes,
        ];

        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage('Route cache entry must be an object-like array');

        $cache->load();
    }

    public function testLoadThrowsWhenRouteEntryMissingRequiredKey(): void
    {
        $routes = [['method' => 'GET', 'path' => '/health']]; // missing 'handler'

        $payload = [
            'meta' => [
                'version' => 1,
                'routes_hash' => hash('sha256', json_encode($routes, JSON_THROW_ON_ERROR)),
            ],
            'routes' => $routes,
        ];

        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage('Route cache entry missing valid "handler"');

        $cache->load();
    }

    public function testLoadThrowsWhenOptionalFieldsAreInvalid(): void
    {
        // name is not null and not a string
        $routes = [[
            'method' => 'GET',
            'path' => '/health',
            'handler' => 'HealthController@show',
            'constraints' => [],
            'middlewares' => [],
            'name' => 42,
        ]];

        $payload = [
            'meta' => [
                'version' => 1,
                'routes_hash' => hash('sha256', json_encode($routes, JSON_THROW_ON_ERROR)),
            ],
            'routes' => $routes,
        ];

        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage('Route cache entry contains invalid optional fields');

        $cache->load();
    }
}
