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

    public function testHasReturnsFalseWhenCacheFileMissing(): void
    {
        $cache = new RouteCache($this->cacheFile);

        self::assertFalse($cache->has());
    }

    public function testMetadataReturnsNullWhenCacheFileMissing(): void
    {
        $cache = new RouteCache($this->cacheFile);

        self::assertNull($cache->metadata());
        self::assertNull($cache->generatedAt());
        self::assertNull($cache->age());
    }

    public function testMetadataReturnsExpectedFieldsAfterSave(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show', name: 'health.show'));

        $cache = new RouteCache($this->cacheFile);
        $cache->save($routes);

        $meta = $cache->metadata();

        self::assertNotNull($meta);
        self::assertSame(1, $meta['version']);
        self::assertSame(1, $meta['route_count']);
        self::assertArrayHasKey('routes_hash', $meta);
        self::assertArrayHasKey('generated_at', $meta);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $meta['routes_hash']);
    }

    public function testGeneratedAtReturnsTimestampFromMetadata(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show'));

        $cache = new RouteCache($this->cacheFile);
        $cache->save($routes);

        $generatedAt = $cache->generatedAt();

        self::assertIsInt($generatedAt);
        self::assertGreaterThan(0, $generatedAt);
    }

    public function testAgeReturnsElapsedSecondsWhenMetadataIsUsable(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show'));

        $cache = new RouteCache($this->cacheFile);
        $cache->save($routes);

        $generatedAt = $cache->generatedAt();
        self::assertNotNull($generatedAt);

        self::assertSame(10, $cache->age($generatedAt + 10));
    }

    public function testAgeReturnsNullWhenGeneratedAtIsInFuture(): void
    {
        $routes = [[
            'method' => 'GET',
            'path' => '/health',
            'handler' => 'HealthController@show',
            'constraints' => [],
            'middlewares' => [],
            'name' => null,
        ]];

        $payload = [
            'meta' => [
                'version' => 1,
                'routes_hash' => hash('sha256', json_encode($routes, JSON_THROW_ON_ERROR)),
                'route_count' => 1,
                'generated_at' => time() + 600,
            ],
            'routes' => $routes,
        ];

        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        self::assertNull($cache->age(time()));
    }

    public function testHasReturnsTrueAfterSave(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show'));

        $cache = new RouteCache($this->cacheFile);
        $cache->save($routes);

        self::assertTrue($cache->has());
    }

    public function testClearRemovesExistingCacheFile(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show'));

        $cache = new RouteCache($this->cacheFile);
        $cache->save($routes);

        self::assertFileExists($this->cacheFile);

        $cache->clear();

        self::assertFileDoesNotExist($this->cacheFile);
        self::assertFalse($cache->has());
    }

    public function testClearIsNoOpWhenCacheFileMissing(): void
    {
        $cache = new RouteCache($this->cacheFile);

        $cache->clear();

        self::assertFalse($cache->has());
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

    public function testIsFreshReturnsTrueWhenCacheMatchesRoutes(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show', name: 'health.show'));

        $cache = new RouteCache($this->cacheFile);
        $cache->save($routes);

        self::assertTrue($cache->isFresh($routes));
    }

    public function testIsFreshReturnsFalseWhenRouteSetChanges(): void
    {
        $cachedRoutes = new RouteCollection();
        $cachedRoutes->add(Route::define('GET', '/health', 'HealthController@show', name: 'health.show'));

        $currentRoutes = new RouteCollection();
        $currentRoutes->add(Route::define('GET', '/health', 'HealthController@show', name: 'health.show'));
        $currentRoutes->add(Route::define('GET', '/status', 'HealthController@status', name: 'health.status'));

        $cache = new RouteCache($this->cacheFile);
        $cache->save($cachedRoutes);

        self::assertFalse($cache->isFresh($currentRoutes));
    }

    public function testIsFreshReturnsFalseWhenCacheMetadataMissing(): void
    {
        file_put_contents($this->cacheFile, json_encode(['routes' => []], JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        self::assertFalse($cache->isFresh(new RouteCollection()));
    }

    public function testIsFreshReturnsFalseWhenRouteCountMetadataIsMissing(): void
    {
        $routes = [];
        $payload = [
            'meta' => [
                'version' => 1,
                'routes_hash' => hash('sha256', json_encode($routes, JSON_THROW_ON_ERROR)),
            ],
            'routes' => $routes,
        ];

        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        self::assertFalse($cache->isFresh(new RouteCollection()));
    }

    public function testIsFreshReturnsFalseWhenCachePayloadInvalid(): void
    {
        file_put_contents($this->cacheFile, '{broken-json}');

        $cache = new RouteCache($this->cacheFile);
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show'));

        self::assertFalse($cache->isFresh($routes));
    }

    public function testIsFreshWithinReturnsTrueWhenFreshAndWithinAgeWindow(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show'));

        $cache = new RouteCache($this->cacheFile);
        $cache->save($routes);

        $meta = $cache->metadata();
        self::assertNotNull($meta);

        self::assertTrue($cache->isFreshWithin($routes, 60, $meta['generated_at'] + 30));
    }

    public function testIsFreshWithinReturnsFalseWhenCacheIsTooOld(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show'));

        $cache = new RouteCache($this->cacheFile);
        $cache->save($routes);

        $meta = $cache->metadata();
        self::assertNotNull($meta);

        self::assertFalse($cache->isFreshWithin($routes, 60, $meta['generated_at'] + 61));
    }

    public function testIsFreshWithinReturnsFalseWhenGeneratedAtIsInFuture(): void
    {
        $routes = [[
            'method' => 'GET',
            'path' => '/health',
            'handler' => 'HealthController@show',
            'constraints' => [],
            'middlewares' => [],
            'name' => null,
        ]];

        $payload = [
            'meta' => [
                'version' => 1,
                'routes_hash' => hash('sha256', json_encode($routes, JSON_THROW_ON_ERROR)),
                'route_count' => 1,
                'generated_at' => time() + 300,
            ],
            'routes' => $routes,
        ];

        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);
        $currentRoutes = new RouteCollection();
        $currentRoutes->add(Route::define('GET', '/health', 'HealthController@show'));

        self::assertFalse($cache->isFreshWithin($currentRoutes, 60, time()));
    }

    public function testIsFreshWithinThrowsOnNegativeMaxAge(): void
    {
        $cache = new RouteCache($this->cacheFile);

        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage('Route cache max age must be zero or greater');

        $cache->isFreshWithin(new RouteCollection(), -1);
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

    public function testSaveThrowsOnUnencodableRoutePayload(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', "/bad-\xB1", 'HealthController@show'));

        $cache = new RouteCache($this->cacheFile);

        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage('Unable to encode route cache payload');

        $cache->save($routes);
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

    public function testLoadThrowsWhenMetadataRouteCountIsInvalid(): void
    {
        $payload = [
            'meta' => [
                'version' => 1,
                'routes_hash' => hash('sha256', json_encode([], JSON_THROW_ON_ERROR)),
                'route_count' => '1',
            ],
            'routes' => [],
        ];

        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage('Route cache payload contains invalid metadata route count');

        $cache->load();
    }

    public function testLoadThrowsWhenMetadataRouteCountDoesNotMatchPayload(): void
    {
        $routes = [[
            'method' => 'GET',
            'path' => '/health',
            'handler' => 'HealthController@show',
            'constraints' => [],
            'middlewares' => [],
            'name' => null,
        ]];

        $payload = [
            'meta' => [
                'version' => 1,
                'routes_hash' => hash('sha256', json_encode($routes, JSON_THROW_ON_ERROR)),
                'route_count' => 99,
            ],
            'routes' => $routes,
        ];

        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage('Route cache payload metadata route count mismatch');

        $cache->load();
    }

    public function testLoadThrowsWhenMetadataGeneratedAtIsInvalid(): void
    {
        $payload = [
            'meta' => [
                'version' => 1,
                'routes_hash' => hash('sha256', json_encode([], JSON_THROW_ON_ERROR)),
                'route_count' => 0,
                'generated_at' => 'now',
            ],
            'routes' => [],
        ];

        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage('Route cache payload contains invalid metadata generation timestamp');

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

    public function testLoadThrowsWhenMethodFormatIsInvalid(): void
    {
        $routes = [[
            'method' => 'Get',
            'path' => '/health',
            'handler' => 'HealthController@show',
            'constraints' => [],
            'middlewares' => [],
            'name' => 'health.show',
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
        $this->expectExceptionMessage('Route cache entry contains invalid HTTP method format');

        $cache->load();
    }

    public function testLoadThrowsWhenPathIsInvalid(): void
    {
        $routes = [[
            'method' => 'GET',
            'path' => 'health',
            'handler' => 'HealthController@show',
            'constraints' => [],
            'middlewares' => [],
            'name' => 'health.show',
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
        $this->expectExceptionMessage('Route cache entry contains invalid route path');

        $cache->load();
    }

    public function testLoadThrowsWhenHandlerFormatIsInvalid(): void
    {
        $routes = [[
            'method' => 'GET',
            'path' => '/health',
            'handler' => 'HealthController',
            'constraints' => [],
            'middlewares' => [],
            'name' => 'health.show',
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
        $this->expectExceptionMessage('Route cache entry contains invalid handler format');

        $cache->load();
    }

    public function testLoadThrowsWhenRouteNameIsBlankString(): void
    {
        $routes = [[
            'method' => 'GET',
            'path' => '/health',
            'handler' => 'HealthController@show',
            'constraints' => [],
            'middlewares' => [],
            'name' => '   ',
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
        $this->expectExceptionMessage('Route cache entry contains invalid route name');

        $cache->load();
    }

    public function testSaveThrowsWhenCacheDirectoryCannotBeCreated(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show'));

        $cache = new RouteCache('/dev/null/zephyrus-routes.json');

        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage('Unable to create route cache directory');

        $cache->save($routes);
    }

    public function testSaveThrowsWhenCacheFileCannotBeWritten(): void
    {
        $cacheDirectory = sys_get_temp_dir() . '/zephyrus2-route-cache-' . uniqid('', true);
        mkdir($cacheDirectory, 0777, true);

        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show'));

        // Intentionally point cache file path to a directory, so file_put_contents fails.
        $cache = new RouteCache($cacheDirectory);

        try {
            $this->expectException(RouteCacheException::class);
            $this->expectExceptionMessage('Unable to write route cache file');

            $cache->save($routes);
        } finally {
            @rmdir($cacheDirectory);
        }
    }
}
