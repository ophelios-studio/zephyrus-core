<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Routing\Exception\RouteCacheException;
use Zephyrus\Routing\Route;
use Zephyrus\Routing\RouteCache;
use Zephyrus\Routing\RouteCollection;
use Zephyrus\Security\AuthGuardMiddleware;
use Zephyrus\Security\CsrfMiddleware;
use Zephyrus\Session\SessionMiddleware;

final class RouteCacheTest extends TestCase
{
    private string $cacheFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheFile = sys_get_temp_dir() . '/zephyrus2-route-cache-' . bin2hex(random_bytes(8)) . '.json';
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

    public function testFilePathReturnsConfiguredCacheLocation(): void
    {
        $cache = new RouteCache($this->cacheFile);

        self::assertSame($this->cacheFile, $cache->filePath());
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

    public function testWarmReturnsGeneratedMetadataAfterSavingRoutes(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show', name: 'health.show'));

        $cache = new RouteCache($this->cacheFile);

        $meta = $cache->warm($routes);

        self::assertSame(1, $meta['version']);
        self::assertSame(1, $meta['route_count']);
        self::assertArrayHasKey('routes_hash', $meta);
        self::assertArrayHasKey('generated_at', $meta);
        self::assertTrue($cache->has());
    }

    public function testWarmIfStaleReturnsTrueAndWritesWhenCacheIsMissing(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show', name: 'health.show'));

        $cache = new RouteCache($this->cacheFile);

        self::assertTrue($cache->warmIfStale($routes, 300, time()));
        self::assertTrue($cache->has());
    }

    public function testWarmIfStaleReturnsFalseWhenCacheIsFreshWithinWindow(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show', name: 'health.show'));

        $cache = new RouteCache($this->cacheFile);
        $meta = $cache->warm($routes);

        self::assertFalse($cache->warmIfStale($routes, 300, $meta['generated_at'] + 5));
    }

    public function testWarmIfStaleThrowsOnNegativeMaxAge(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show', name: 'health.show'));

        $cache = new RouteCache($this->cacheFile);

        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage('Route cache max age must be zero or greater');

        $cache->warmIfStale($routes, -1, time());
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

    public function testRouteCountReturnsNullWhenMetadataMissing(): void
    {
        $cache = new RouteCache($this->cacheFile);

        self::assertNull($cache->routeCount());
    }

    public function testRouteCountReturnsMetadataRouteCountAfterSave(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show'));
        $routes->add(Route::define('GET', '/status', 'HealthController@status'));

        $cache = new RouteCache($this->cacheFile);
        $cache->save($routes);

        self::assertSame(2, $cache->routeCount());
    }

    public function testRoutesHashReturnsNullWhenMetadataMissing(): void
    {
        $cache = new RouteCache($this->cacheFile);

        self::assertNull($cache->routesHash());
    }

    public function testRoutesHashReturnsMetadataHashAfterSave(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show'));
        $routes->add(Route::define('GET', '/status', 'HealthController@status'));

        $cache = new RouteCache($this->cacheFile);
        $cache->save($routes);

        $hash = $cache->routesHash();

        self::assertIsString($hash);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    public function testMetadataVersionReturnsNullWhenMetadataMissing(): void
    {
        $cache = new RouteCache($this->cacheFile);

        self::assertNull($cache->metadataVersion());
    }

    public function testMetadataVersionReturnsCurrentVersionAfterSave(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show'));

        $cache = new RouteCache($this->cacheFile);
        $cache->save($routes);

        self::assertSame(1, $cache->metadataVersion());
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

    public function testExpiresAtReturnsNullWhenMetadataMissing(): void
    {
        $cache = new RouteCache($this->cacheFile);

        self::assertNull($cache->expiresAt(60));
    }

    public function testExpiresAtReturnsComputedTimestamp(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show'));

        $cache = new RouteCache($this->cacheFile);
        $cache->save($routes);

        $generatedAt = $cache->generatedAt();
        self::assertNotNull($generatedAt);

        self::assertSame($generatedAt + 90, $cache->expiresAt(90));
    }

    public function testExpiresAtThrowsOnNegativeMaxAge(): void
    {
        $cache = new RouteCache($this->cacheFile);

        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage('Route cache max age must be zero or greater');

        $cache->expiresAt(-1);
    }

    public function testIsExpiredReturnsTrueWhenMetadataMissing(): void
    {
        $cache = new RouteCache($this->cacheFile);

        self::assertTrue($cache->isExpired(60));
    }

    public function testIsExpiredReturnsFalseAtExpiryBoundary(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show'));

        $cache = new RouteCache($this->cacheFile);
        $cache->save($routes);

        $generatedAt = $cache->generatedAt();
        self::assertNotNull($generatedAt);

        self::assertFalse($cache->isExpired(60, $generatedAt + 60));
    }

    public function testIsExpiredReturnsTrueAfterExpiryBoundary(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show'));

        $cache = new RouteCache($this->cacheFile);
        $cache->save($routes);

        $generatedAt = $cache->generatedAt();
        self::assertNotNull($generatedAt);

        self::assertTrue($cache->isExpired(60, $generatedAt + 61));
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

        $this->assertRefused(static fn (): mixed => $cache->load(), 'not been written');
    }

    public function testLoadThrowsOnInvalidJsonPayload(): void
    {
        file_put_contents($this->cacheFile, '{not-json}');

        $cache = new RouteCache($this->cacheFile);

        $this->assertRefused(static fn (): mixed => $cache->load(), 'contents that are not valid JSON');
    }

    public function testLoadThrowsWhenRoutesSectionMissing(): void
    {
        file_put_contents($this->cacheFile, json_encode(['meta' => ['version' => 1]], JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->assertRefused(static fn (): mixed => $cache->load(), 'no routes section');
    }

    public function testLoadThrowsOnHashMismatch(): void
    {
        $payload = [
            'meta' => [
                'version' => 1,
                'routes_hash' => str_repeat('a', 64),
                'route_count' => 1,
                'generated_at' => 1700000000,
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

        $this->assertLoadRefused($cache, 'a routes hash that does not match its routes section');
    }

    public function testLoadThrowsOnUnsupportedMetadataVersion(): void
    {
        $payload = [
            'meta' => [
                'version' => 2,
                'routes_hash' => hash('sha256', json_encode([], JSON_THROW_ON_ERROR)),
                'route_count' => 0,
                'generated_at' => 1700000000,
            ],
            'routes' => [],
        ];

        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->assertLoadRefused($cache, 'been written by another version');
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

    public function testFreshnessAndLoadRefuseMetadataWithoutGeneratedAt(): void
    {
        $meta = self::metadataFor([]);
        unset($meta['generated_at']);

        $this->assertFileRefusedByFreshnessAndLoad($meta, 'an invalid generation timestamp');
    }

    public function testFreshnessAndLoadRefuseMetadataWithStringGeneratedAt(): void
    {
        $meta = self::metadataFor([]);
        $meta['generated_at'] = '1700000000';

        $this->assertFileRefusedByFreshnessAndLoad($meta, 'an invalid generation timestamp');
    }

    public function testFreshnessRefusesRoutesHashWithTrailingNewline(): void
    {
        $meta = self::metadataFor([]);
        $meta['routes_hash'] .= "\n";

        file_put_contents($this->cacheFile, json_encode(['meta' => $meta, 'routes' => []], JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        self::assertNull($cache->metadata());
        self::assertFalse($cache->isFresh(new RouteCollection()));
    }

    public function testFreshnessRefusesRoutesSectionEditedUnderIntactMetadata(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show'));

        $cache = new RouteCache($this->cacheFile);
        $cache->save($routes);

        $payload = json_decode((string) file_get_contents($this->cacheFile), true, 512, JSON_THROW_ON_ERROR);
        $payload['routes'][0]['handler'] = 'AdminController@show';
        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        self::assertFalse($cache->isFresh($routes));
        $this->assertLoadRefused($cache, 'a routes hash that does not match its routes section');
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('payloadLoadRefusalProvider')]
    public function testIsFreshIsFalseExactlyWhenLoadRefusesTheFile(array $payload, bool $loadRefuses): void
    {
        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        try {
            $cache->load();
            $refused = false;
        } catch (RouteCacheException) {
            $refused = true;
        }

        self::assertSame($loadRefuses, $refused);
        self::assertSame($loadRefuses, !$cache->isFresh(new RouteCollection()));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function payloadLoadRefusalProvider(): iterable
    {
        $meta = self::metadataFor([]);

        yield 'valid cache' => [['meta' => $meta, 'routes' => []], false];
        yield 'routes section missing' => [['meta' => $meta], true];
        yield 'routes section is an object' => [['meta' => ['route_count' => 1, 'routes_hash' => hash('sha256', '{"GET":[]}')] + $meta, 'routes' => ['GET' => []]], true];
        yield 'routes hash mismatch' => [['meta' => ['routes_hash' => str_repeat('a', 64)] + $meta, 'routes' => []], true];
        yield 'route count mismatch' => [['meta' => ['route_count' => 1] + $meta, 'routes' => []], true];
        yield 'metadata missing' => [['routes' => []], true];
        yield 'version unsupported' => [['meta' => ['version' => 2] + $meta, 'routes' => []], true];
        yield 'routes hash not a string' => [['meta' => ['routes_hash' => 1] + $meta, 'routes' => []], true];
        yield 'routes hash malformed' => [['meta' => ['routes_hash' => strtoupper($meta['routes_hash'])] + $meta, 'routes' => []], true];
        yield 'route count invalid' => [['meta' => ['route_count' => -1] + $meta, 'routes' => []], true];
        yield 'generated_at missing' => [['meta' => array_diff_key($meta, ['generated_at' => true]), 'routes' => []], true];
        yield 'generated_at string' => [['meta' => ['generated_at' => '1700000000'] + $meta, 'routes' => []], true];
        yield 'generated_at negative' => [['meta' => ['generated_at' => -1] + $meta, 'routes' => []], true];
        yield 'generated_at minimum' => [['meta' => ['generated_at' => PHP_INT_MIN] + $meta, 'routes' => []], true];
        yield 'generated_at negative maximum' => [['meta' => ['generated_at' => -PHP_INT_MAX] + $meta, 'routes' => []], true];
        yield 'generated_at zero' => [['meta' => ['generated_at' => 0] + $meta, 'routes' => []], false];
    }

    public function testVersionIsCheckedBeforeTheRoutesHash(): void
    {
        file_put_contents($this->cacheFile, json_encode(['meta' => ['version' => 2, 'routes_hash' => 999], 'routes' => []], JSON_THROW_ON_ERROR));

        $this->assertLoadRefused(new RouteCache($this->cacheFile), 'been written by another version');
    }

    public function testExpiresAtDoesNotOverflowForExtremeGeneratedAtOrMaxAge(): void
    {
        $cache = new RouteCache($this->cacheFile);

        file_put_contents($this->cacheFile, json_encode(['meta' => ['generated_at' => PHP_INT_MAX] + self::metadataFor([]), 'routes' => []], JSON_THROW_ON_ERROR));

        self::assertSame(PHP_INT_MAX, $cache->expiresAt(60));
        self::assertTrue($cache->isExpired(60, 1700000000));

        file_put_contents($this->cacheFile, json_encode(['meta' => ['generated_at' => 1700000000] + self::metadataFor([]), 'routes' => []], JSON_THROW_ON_ERROR));

        self::assertSame(PHP_INT_MAX, $cache->expiresAt(PHP_INT_MAX));
    }

    #[DataProvider('negativeGeneratedAtProvider')]
    public function testNegativeGeneratedAtIsRefusedWithoutOverflowing(int $generatedAt): void
    {
        file_put_contents($this->cacheFile, json_encode(['meta' => ['generated_at' => $generatedAt] + self::metadataFor([]), 'routes' => []], JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        self::assertNull($cache->age(1700000000));
        self::assertFalse($cache->canUseWithin(new RouteCollection(), 60, 1700000000));
        self::assertSame('invalid-metadata', $cache->inspect(new RouteCollection(), 60, 1700000000)['reason']);
        $this->assertLoadRefused($cache, 'an invalid generation timestamp');
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function negativeGeneratedAtProvider(): iterable
    {
        yield 'minus one' => [-1];
        yield 'minimum' => [PHP_INT_MIN];
        yield 'negative maximum' => [-PHP_INT_MAX];
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

    public function testEnsureFreshWithinThrowsOnNegativeMaxAge(): void
    {
        $cache = new RouteCache($this->cacheFile);

        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage('Route cache max age must be zero or greater');

        $cache->ensureFreshWithin(new RouteCollection(), -1);
    }

    public function testEnsureFreshWithinSucceedsForFreshCacheWithinWindow(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show', name: 'health.show'));

        $cache = new RouteCache($this->cacheFile);
        $cache->save($routes);

        $generatedAt = $cache->generatedAt();
        self::assertNotNull($generatedAt);

        $cache->ensureFreshWithin($routes, 120, $generatedAt + 30);

        self::assertTrue(true);
    }

    public function testEnsureFreshWithinThrowsWhenCacheFileMissing(): void
    {
        $cache = new RouteCache($this->cacheFile);

        $this->assertRefused(static fn (): mixed => $cache->ensureFreshWithin(new RouteCollection(), 60), 'not been written');
    }

    public function testEnsureFreshWithinThrowsWhenMetadataInvalid(): void
    {
        file_put_contents($this->cacheFile, json_encode(['routes' => []], JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->assertRefused(static fn (): mixed => $cache->ensureFreshWithin(new RouteCollection(), 60), 'no metadata section');
    }

    public function testEnsureFreshWithinThrowsWhenCacheExpired(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show'));

        $cache = new RouteCache($this->cacheFile);
        $cache->save($routes);

        $generatedAt = $cache->generatedAt();
        self::assertNotNull($generatedAt);

        $this->assertRefused(static fn (): mixed => $cache->ensureFreshWithin($routes, 60, $generatedAt + 61), 'expired');
    }

    public function testEnsureFreshWithinThrowsWhenRouteSetDiffers(): void
    {
        $cachedRoutes = new RouteCollection();
        $cachedRoutes->add(Route::define('GET', '/health', 'HealthController@show', name: 'health.show'));

        $currentRoutes = new RouteCollection();
        $currentRoutes->add(Route::define('GET', '/health', 'HealthController@show', name: 'health.show'));
        $currentRoutes->add(Route::define('GET', '/status', 'HealthController@status', name: 'health.status'));

        $cache = new RouteCache($this->cacheFile);
        $cache->save($cachedRoutes);

        $generatedAt = $cache->generatedAt();
        self::assertNotNull($generatedAt);

        $this->assertRefused(static fn (): mixed => $cache->ensureFreshWithin($currentRoutes, 120, $generatedAt + 10), 'routes that differ from the current routes');
    }

    public function testInspectReportsMissingFileState(): void
    {
        $cache = new RouteCache($this->cacheFile);

        $state = $cache->inspect(new RouteCollection(), 60);

        self::assertSame('missing-file', $state['reason']);
        self::assertFalse($state['exists']);
        self::assertFalse($state['metadata_valid']);
        self::assertFalse($state['fresh']);
        self::assertTrue($state['expired']);
        self::assertNull($state['age']);
        self::assertNull($state['expires_at']);
        self::assertNull($state['generated_at']);
    }

    public function testInspectReportsInvalidMetadataState(): void
    {
        file_put_contents($this->cacheFile, json_encode(['routes' => []], JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);
        $state = $cache->inspect(new RouteCollection(), 60);

        self::assertSame('invalid-metadata', $state['reason']);
        self::assertTrue($state['exists']);
        self::assertFalse($state['metadata_valid']);
        self::assertFalse($state['fresh']);
        self::assertTrue($state['expired']);
    }

    public function testInspectReportsExpiredState(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show'));

        $cache = new RouteCache($this->cacheFile);
        $cache->save($routes);

        $generatedAt = $cache->generatedAt();
        self::assertNotNull($generatedAt);

        $state = $cache->inspect($routes, 60, $generatedAt + 61);

        self::assertSame('expired', $state['reason']);
        self::assertTrue($state['exists']);
        self::assertTrue($state['metadata_valid']);
        self::assertFalse($state['fresh']);
        self::assertTrue($state['expired']);
        self::assertSame($generatedAt, $state['generated_at']);
    }

    public function testInspectReportsStaleRoutesState(): void
    {
        $cachedRoutes = new RouteCollection();
        $cachedRoutes->add(Route::define('GET', '/health', 'HealthController@show'));

        $currentRoutes = new RouteCollection();
        $currentRoutes->add(Route::define('GET', '/health', 'HealthController@show'));
        $currentRoutes->add(Route::define('GET', '/status', 'HealthController@status'));

        $cache = new RouteCache($this->cacheFile);
        $cache->save($cachedRoutes);

        $generatedAt = $cache->generatedAt();
        self::assertNotNull($generatedAt);

        $state = $cache->inspect($currentRoutes, 120, $generatedAt + 10);

        self::assertSame('stale-routes', $state['reason']);
        self::assertTrue($state['exists']);
        self::assertTrue($state['metadata_valid']);
        self::assertFalse($state['fresh']);
        self::assertFalse($state['expired']);
    }

    public function testInspectReportsFreshState(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show', name: 'health.show'));

        $cache = new RouteCache($this->cacheFile);
        $cache->save($routes);

        $generatedAt = $cache->generatedAt();
        self::assertNotNull($generatedAt);

        $state = $cache->inspect($routes, 120, $generatedAt + 10);

        self::assertSame('fresh', $state['reason']);
        self::assertTrue($state['exists']);
        self::assertTrue($state['metadata_valid']);
        self::assertTrue($state['fresh']);
        self::assertFalse($state['expired']);
        self::assertSame(10, $state['age']);
        self::assertSame($generatedAt + 120, $state['expires_at']);
        self::assertSame($generatedAt, $state['generated_at']);
    }

    public function testInspectThrowsOnNegativeMaxAge(): void
    {
        $cache = new RouteCache($this->cacheFile);

        $this->expectException(RouteCacheException::class);
        $this->expectExceptionMessage('Route cache max age must be zero or greater');

        $cache->inspect(new RouteCollection(), -1);
    }

    public function testCanUseWithinReturnsTrueForFreshCache(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show'));

        $cache = new RouteCache($this->cacheFile);
        $cache->save($routes);

        $generatedAt = $cache->generatedAt();
        self::assertNotNull($generatedAt);

        self::assertTrue($cache->canUseWithin($routes, 60, $generatedAt + 10));
    }

    public function testCanUseWithinReturnsFalseForStaleOrExpiredCache(): void
    {
        $cachedRoutes = new RouteCollection();
        $cachedRoutes->add(Route::define('GET', '/health', 'HealthController@show'));

        $currentRoutes = new RouteCollection();
        $currentRoutes->add(Route::define('GET', '/health', 'HealthController@show'));
        $currentRoutes->add(Route::define('GET', '/status', 'HealthController@status'));

        $cache = new RouteCache($this->cacheFile);
        $cache->save($cachedRoutes);

        $generatedAt = $cache->generatedAt();
        self::assertNotNull($generatedAt);

        self::assertFalse($cache->canUseWithin($currentRoutes, 60, $generatedAt + 10));
        self::assertFalse($cache->canUseWithin($cachedRoutes, 60, $generatedAt + 61));
    }

    public function testSaveCreatesMissingCacheDirectory(): void
    {
        $cacheDirectory = sys_get_temp_dir() . '/zephyrus2-route-cache-' . bin2hex(random_bytes(8));
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

        $this->assertLoadRefused($cache, 'a malformed routes hash');
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

        $this->assertLoadRefused($cache, 'an invalid route count');
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
                'generated_at' => 1700000000,
                'route_count' => 99,
            ],
            'routes' => $routes,
        ];

        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->assertLoadRefused($cache, 'a route count that does not match its routes section');
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

        $this->assertLoadRefused($cache, 'an invalid generation timestamp');
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
            'meta' => self::metadataFor($routes),
            'routes' => $routes,
        ];

        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->assertRefused(static fn (): mixed => $cache->load(), 'a route with an invalid constraints map');
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
            'meta' => self::metadataFor($routes),
            'routes' => $routes,
        ];

        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->assertRefused(static fn (): mixed => $cache->load(), 'a route with an invalid middlewares list');
    }

    public function testLoadThrowsWhenDecodedPayloadIsNotArray(): void
    {
        file_put_contents($this->cacheFile, 'null');

        $cache = new RouteCache($this->cacheFile);

        $this->assertRefused(static fn (): mixed => $cache->load(), 'contents that do not decode to an object');
    }

    public function testLoadRefusesPayloadWithoutMetadataSection(): void
    {
        $routes = [[
            'method' => 'GET',
            'path' => '/health',
            'handler' => 'HealthController@show',
            'constraints' => [],
            'middlewares' => [],
            'name' => null,
        ]];

        file_put_contents($this->cacheFile, json_encode(['routes' => $routes], JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->assertLoadRefused($cache, 'no metadata section');
    }

    public function testMetadataAndFreshnessIgnoreFileWithoutMetadataSection(): void
    {
        file_put_contents($this->cacheFile, json_encode(['routes' => []], JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        self::assertNull($cache->metadata());
        self::assertFalse($cache->isFresh(new RouteCollection()));
    }

    #[DataProvider('requiredMetadataFieldProvider')]
    public function testLoadRefusesMetadataMissingRequiredField(string $field, string $problem): void
    {
        $meta = self::metadataFor([]);
        unset($meta[$field]);

        file_put_contents($this->cacheFile, json_encode(['meta' => $meta, 'routes' => []], JSON_THROW_ON_ERROR));

        $this->assertLoadRefused(new RouteCache($this->cacheFile), $problem);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function requiredMetadataFieldProvider(): iterable
    {
        yield 'version' => ['version', 'been written by another version'];
        yield 'routes_hash' => ['routes_hash', 'a routes hash that is not a string'];
        yield 'route_count' => ['route_count', 'an invalid route count'];
        yield 'generated_at' => ['generated_at', 'an invalid generation timestamp'];
    }

    public function testLoadRefusesRoutesHashWithTrailingNewline(): void
    {
        $meta = self::metadataFor([]);
        $meta['routes_hash'] .= "\n";

        file_put_contents($this->cacheFile, json_encode(['meta' => $meta, 'routes' => []], JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->assertLoadRefused($cache, 'a malformed routes hash');
    }

    public function testLoadRefusesHttpMethodWithTrailingNewline(): void
    {
        $routes = [[
            'method' => "GET\n",
            'path' => '/health',
            'handler' => 'HealthController@show',
            'constraints' => [],
            'middlewares' => [],
            'name' => null,
        ]];

        file_put_contents($this->cacheFile, json_encode(['meta' => self::metadataFor($routes), 'routes' => $routes], JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->assertRefused(static fn (): mixed => $cache->load(), 'a route with an invalid HTTP method format');
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function assertFileRefusedByFreshnessAndLoad(array $meta, string $problem): void
    {
        file_put_contents($this->cacheFile, json_encode(['meta' => $meta, 'routes' => []], JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        self::assertNull($cache->metadata());
        self::assertFalse($cache->isFresh(new RouteCollection()));
        $this->assertLoadRefused($cache, $problem);
    }

    private function assertLoadRefused(RouteCache $cache, string $problem): void
    {
        $this->assertRefused(static fn (): mixed => $cache->load(), $problem);
    }

    /**
     * @param \Closure(): mixed $action
     */
    private function assertExactRefusal(\Closure $action, string $problem): void
    {
        try {
            $action();
        } catch (RouteCacheException $exception) {
            self::assertSame(
                sprintf('Route cache file %s has %s; rebuild the cache with save() or warm()', $this->cacheFile, $problem),
                $exception->getMessage(),
            );

            return;
        }

        self::fail('The route cache was accepted but should be refused');
    }

    private function assertRefused(\Closure $action, string $problem): void
    {
        try {
            $action();
        } catch (RouteCacheException $exception) {
            self::assertStringContainsString($this->cacheFile, $exception->getMessage());
            self::assertStringContainsString('rebuild', $exception->getMessage());
            self::assertStringContainsString($problem, $exception->getMessage());

            return;
        }

        self::fail('The route cache was accepted but should be refused');
    }

    public function testLoadThrowsWhenMetaRoutesHashIsNotString(): void
    {
        $payload = [
            'meta' => ['version' => 1, 'routes_hash' => 999],
            'routes' => [],
        ];

        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->assertLoadRefused($cache, 'a routes hash that is not a string');
    }

    public function testLoadThrowsWhenRouteEntryIsNotArray(): void
    {
        $routes = ['not-an-array-entry'];

        $payload = [
            'meta' => self::metadataFor($routes),
            'routes' => $routes,
        ];

        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->assertExactRefusal(static fn (): mixed => $cache->load(), 'a route that is not an object at entry 0');
    }

    public function testLoadThrowsWhenRouteEntryMissingRequiredKey(): void
    {
        $routes = [['method' => 'GET', 'path' => '/health']]; // missing 'handler'

        $payload = [
            'meta' => self::metadataFor($routes),
            'routes' => $routes,
        ];

        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->assertRefused(static fn (): mixed => $cache->load(), 'a route without a valid "handler"');
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
            'meta' => self::metadataFor($routes),
            'routes' => $routes,
        ];

        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->assertRefused(static fn (): mixed => $cache->load(), 'a route with invalid optional fields');
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
            'meta' => self::metadataFor($routes),
            'routes' => $routes,
        ];

        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->assertRefused(static fn (): mixed => $cache->load(), 'a route with an invalid HTTP method format');
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
            'meta' => self::metadataFor($routes),
            'routes' => $routes,
        ];

        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->assertRefused(static fn (): mixed => $cache->load(), 'a route with an invalid route path');
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
            'meta' => self::metadataFor($routes),
            'routes' => $routes,
        ];

        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->assertRefused(static fn (): mixed => $cache->load(), 'a route with an invalid handler format at entry 0 ["GET","/health"]');
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
            'meta' => self::metadataFor($routes),
            'routes' => $routes,
        ];

        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->assertRefused(static fn (): mixed => $cache->load(), 'a route with an invalid route name');
    }

    public function testLoadThrowsWhenDuplicateRouteNamesExist(): void
    {
        $routes = [
            [
                'method' => 'GET',
                'path' => '/users/1',
                'handler' => 'UserController@showOne',
                'constraints' => [],
                'middlewares' => [],
                'name' => 'users.show',
            ],
            [
                'method' => 'GET',
                'path' => '/users/2',
                'handler' => 'UserController@showTwo',
                'constraints' => [],
                'middlewares' => [],
                'name' => 'users.show',
            ],
        ];

        $payload = [
            'meta' => [
                'version' => 1,
                'routes_hash' => hash('sha256', json_encode($routes, JSON_THROW_ON_ERROR)),
                'route_count' => 2,
                'generated_at' => time(),
            ],
            'routes' => $routes,
        ];

        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        $this->assertRefused(static fn (): mixed => $cache->load(), 'more than one route is named "users.show"; give each route a unique name');
    }

    public function testLoadNamesEveryDuplicatedRouteNameOnce(): void
    {
        $entries = [
            ['method' => 'GET', 'path' => '/users/1', 'handler' => 'UserController@showOne', 'constraints' => [], 'middlewares' => [], 'name' => 'users.show'],
            ['method' => 'GET', 'path' => '/users/2', 'handler' => 'UserController@edit', 'constraints' => [], 'middlewares' => [], 'name' => 'users.edit'],
            ['method' => 'GET', 'path' => '/users/3', 'handler' => 'UserController@showThree', 'constraints' => [], 'middlewares' => [], 'name' => 'users.show'],
            ['method' => 'GET', 'path' => '/users/4', 'handler' => 'UserController@editFour', 'constraints' => [], 'middlewares' => [], 'name' => 'users.edit'],
        ];
        file_put_contents($this->cacheFile, json_encode(['meta' => self::metadataFor($entries), 'routes' => $entries], JSON_THROW_ON_ERROR));

        $this->assertExactRefusal(
            fn (): mixed => (new RouteCache($this->cacheFile))->load(),
            'more than one route is named "users.edit", "users.show"; give each route a unique name',
        );
    }

    public function testLoadQuotesNumericDuplicatedRouteNames(): void
    {
        $entries = [
            ['method' => 'GET', 'path' => '/a', 'handler' => 'A@one', 'constraints' => [], 'middlewares' => [], 'name' => '7'],
            ['method' => 'GET', 'path' => '/b', 'handler' => 'A@two', 'constraints' => [], 'middlewares' => [], 'name' => '7'],
        ];
        file_put_contents($this->cacheFile, json_encode(['meta' => self::metadataFor($entries), 'routes' => $entries], JSON_THROW_ON_ERROR));

        $this->assertExactRefusal(
            fn (): mixed => (new RouteCache($this->cacheFile))->load(),
            'more than one route is named "7"; give each route a unique name',
        );
    }

    public function testLoadEscapesControlAndBidiCharactersInDuplicatedRouteNames(): void
    {
        $entries = [
            ['method' => 'GET', 'path' => '/a', 'handler' => 'A@one', 'constraints' => [], 'middlewares' => [], 'name' => "x\x1b\u{0085}\u{202E}\x7f"],
            ['method' => 'GET', 'path' => '/b', 'handler' => 'A@two', 'constraints' => [], 'middlewares' => [], 'name' => "x\x1b\u{0085}\u{202E}\x7f"],
        ];
        file_put_contents($this->cacheFile, json_encode(['meta' => self::metadataFor($entries), 'routes' => $entries], JSON_THROW_ON_ERROR));

        $this->assertExactRefusal(
            fn (): mixed => (new RouteCache($this->cacheFile))->load(),
            'more than one route is named "x\u001b\u0085\u202e\u007f"; give each route a unique name',
        );
    }

    public function testLoadEscapesControlAndBidiCharactersInTheRefusedEntry(): void
    {
        $entries = [['method' => 'GET', 'path' => "/x\x1b\u{0085}\u{202E}\x7f", 'handler' => 'Missing']];
        file_put_contents($this->cacheFile, json_encode(['meta' => self::metadataFor($entries), 'routes' => $entries], JSON_THROW_ON_ERROR));

        $this->assertExactRefusal(
            fn (): mixed => (new RouteCache($this->cacheFile))->load(),
            'a route with an invalid handler format at entry 0 ["GET","/x\u001b\u0085\u202e\u007f"]',
        );
    }

    public function testInspectReportsAnEditedRoutesSectionAsAnInvalidPayload(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show'));

        $cache = new RouteCache($this->cacheFile);
        $cache->save($routes);

        $payload = json_decode((string) file_get_contents($this->cacheFile), true, 512, JSON_THROW_ON_ERROR);
        $payload['routes'][0]['handler'] = 'AdminController@show';
        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        $state = $cache->inspect($routes, 60);

        self::assertSame('invalid-payload', $state['reason']);
        self::assertSame('a routes hash that does not match its routes section', $state['problem']);
        self::assertFalse($state['fresh']);
        self::assertFalse($cache->canUseWithin($routes, 60));
    }

    public function testInspectReportsARehashedBadEntryAsAnInvalidPayload(): void
    {
        $entries = [['method' => 'get', 'path' => '/health', 'handler' => 'HealthController@show']];
        file_put_contents($this->cacheFile, json_encode(['meta' => self::metadataFor($entries), 'routes' => $entries], JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);
        $state = $cache->inspect(new RouteCollection(), 60, 1700000000);

        self::assertSame('invalid-payload', $state['reason']);
        self::assertSame('a route with an invalid HTTP method format at entry 0 ["get","/health"]', $state['problem']);
        self::assertTrue($state['metadata_valid']);
        self::assertFalse($state['fresh']);
        self::assertSame(1700000000, $state['generated_at']);
    }

    public function testALiveTableWithDuplicateNamesIsNeverFresh(): void
    {
        $live = new RouteCollection();
        $live->add(Route::define('GET', '/users/1', 'UserController@showOne', name: 'users.show'));
        $live->add(Route::define('GET', '/users/2', 'UserController@showTwo', name: 'users.show'));

        $entries = [
            ['method' => 'GET', 'path' => '/users/1', 'handler' => 'UserController@showOne', 'constraints' => [], 'middlewares' => [], 'name' => 'users.show'],
            ['method' => 'GET', 'path' => '/users/2', 'handler' => 'UserController@showTwo', 'constraints' => [], 'middlewares' => [], 'name' => 'users.show'],
        ];
        file_put_contents($this->cacheFile, json_encode(['meta' => self::metadataFor($entries), 'routes' => $entries], JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        self::assertFalse($cache->isFresh($live));
        self::assertFalse($cache->canUseWithin($live, 60, 1700000000));
        $this->assertRefused(static fn (): mixed => $cache->ensureFreshWithin($live, 60, 1700000000), 'more than one route is named "users.show"; give each route a unique name');
    }

    public function testEnsureFreshWithinNamesAGenerationTimestampInTheFuture(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show'));

        $cache = new RouteCache($this->cacheFile);
        $cache->save($routes);

        $generatedAt = $cache->generatedAt();
        self::assertNotNull($generatedAt);
        $now = $generatedAt - 120;

        self::assertTrue($cache->isFresh($routes));
        self::assertFalse($cache->canUseWithin($routes, 300, $now));
        self::assertSame('a generation timestamp in the future', $cache->inspect($routes, 300, $now)['problem']);
        $this->assertRefused(static fn (): mixed => $cache->ensureFreshWithin($routes, 300, $now), 'a generation timestamp in the future');
    }

    public function testInspectReportsNoProblemForAFreshCache(): void
    {
        $routes = new RouteCollection();
        $cache = new RouteCache($this->cacheFile);
        $cache->save($routes);

        self::assertNull($cache->inspect($routes, 60)['problem']);
    }

    public function testRoutesSectionThatIsAnObjectIsRefusedAsNotAList(): void
    {
        $section = ['GET' => []];
        $meta = ['route_count' => 1, 'routes_hash' => hash('sha256', json_encode($section, JSON_THROW_ON_ERROR))] + self::metadataFor([]);
        file_put_contents($this->cacheFile, json_encode(['meta' => $meta, 'routes' => $section], JSON_THROW_ON_ERROR));

        $this->assertLoadRefused(new RouteCache($this->cacheFile), 'a routes section that is not a list');
    }

    public function testInspectReportsANonListRoutesSectionAsAnExpiredInvalidPayload(): void
    {
        $section = ['GET' => []];
        $meta = ['route_count' => 1, 'routes_hash' => hash('sha256', json_encode($section, JSON_THROW_ON_ERROR))] + self::metadataFor([]);
        file_put_contents($this->cacheFile, json_encode(['meta' => $meta, 'routes' => $section], JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);
        $state = $cache->inspect(new RouteCollection(), 60, 1700000010);

        self::assertSame('invalid-payload', $state['reason']);
        self::assertFalse($state['metadata_valid']);
        self::assertTrue($state['expired']);
        self::assertSame('a routes section that is not a list', $state['problem']);
        self::assertNull($cache->metadata());
        self::assertTrue($cache->isExpired(60, 1700000010));
    }

    #[DataProvider('unloadableRouteProvider')]
    public function testSaveRefusesWhatLoadWouldRefuseAndWarmIfStaleDoesNotWrite(\Closure $defineRoute, string $problem): void
    {
        $routes = new RouteCollection();
        $routes->add($defineRoute());

        $cache = new RouteCache($this->cacheFile);

        foreach ([static fn () => $cache->save($routes), static fn () => $cache->warmIfStale($routes, 60)] as $write) {
            try {
                $write();
                self::fail('Expected a RouteCacheException');
            } catch (RouteCacheException $exception) {
                self::assertStringContainsString($problem, $exception->getMessage());
            }
        }

        self::assertFileDoesNotExist($this->cacheFile);
    }

    /**
     * @return iterable<string, array{\Closure(): Route, string}>
     */
    public static function unloadableRouteProvider(): iterable
    {
        yield 'handler without an at sign' => [static fn (): Route => Route::define('GET', '/a', 'Handler'), 'invalid handler format'];
        yield 'method with a dash' => [static fn (): Route => Route::define('M-SEARCH', '/a', 'C@m'), 'invalid HTTP method format'];
        yield 'blank route name' => [static fn (): Route => Route::define('GET', '/a', 'C@m', name: ' '), 'invalid route name'];
        yield 'integer constraint key' => [static fn (): Route => Route::define('GET', '/a', 'C@m', constraints: [1 => '\\d+']), 'invalid constraints map'];
        yield 'non-string middleware' => [static fn (): Route => Route::define('GET', '/a', 'C@m', middlewares: [1]), 'invalid middlewares list'];
    }

    public function testInspectReportsExpiryAndMetadataForAnExpiredCacheWithABadEntry(): void
    {
        $entries = [['method' => 'get', 'path' => '/health', 'handler' => 'HealthController@show']];
        file_put_contents($this->cacheFile, json_encode(['meta' => self::metadataFor($entries), 'routes' => $entries], JSON_THROW_ON_ERROR));

        $state = (new RouteCache($this->cacheFile))->inspect(new RouteCollection(), 60, 1700000100);

        self::assertSame('invalid-payload', $state['reason']);
        self::assertTrue($state['expired']);
        self::assertSame(100, $state['age']);
        self::assertSame(1700000060, $state['expires_at']);
    }

    public function testInspectKeepsMetadataForARoutesHashMismatch(): void
    {
        $meta = ['routes_hash' => str_repeat('a', 64)] + self::metadataFor([]);
        file_put_contents($this->cacheFile, json_encode(['meta' => $meta, 'routes' => []], JSON_THROW_ON_ERROR));

        $state = (new RouteCache($this->cacheFile))->inspect(new RouteCollection(), 60, 1700000010);

        self::assertSame('invalid-payload', $state['reason']);
        self::assertTrue($state['metadata_valid']);
        self::assertFalse($state['expired']);
        self::assertSame(10, $state['age']);
        self::assertSame(1700000060, $state['expires_at']);
    }

    public function testInvalidJsonRefusalKeepsTheDecoderMessage(): void
    {
        file_put_contents($this->cacheFile, '{not json');

        $this->assertLoadRefused(new RouteCache($this->cacheFile), 'contents that are not valid JSON: Syntax error');
    }

    public function testIsFreshIgnoresAGenerationTimestampInTheFuture(): void
    {
        $routes = new RouteCollection();
        $cache = new RouteCache($this->cacheFile);
        $cache->save($routes);

        $payload = json_decode((string) file_get_contents($this->cacheFile), true, 512, JSON_THROW_ON_ERROR);
        $payload['meta']['generated_at'] = time() + 3600;
        file_put_contents($this->cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));

        self::assertTrue($cache->isFresh($routes));
    }

    public function testMetadataIsReturnedForARoutesHashMismatch(): void
    {
        $meta = ['routes_hash' => str_repeat('a', 64)] + self::metadataFor([]);
        file_put_contents($this->cacheFile, json_encode(['meta' => $meta, 'routes' => []], JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);

        self::assertSame($meta, $cache->metadata());
        self::assertSame(str_repeat('a', 64), $cache->routesHash());
    }

    #[DataProvider('integrityMismatchMetadataProvider')]
    public function testAccessorsDescribeAnIntegrityMismatchLikeInspect(array $meta): void
    {
        file_put_contents($this->cacheFile, json_encode(['meta' => $meta, 'routes' => []], JSON_THROW_ON_ERROR));

        $cache = new RouteCache($this->cacheFile);
        $now = 1700000010;
        $state = $cache->inspect(new RouteCollection(), 60, $now);

        self::assertSame('invalid-payload', $state['reason']);
        self::assertSame($state['expired'], $cache->isExpired(60, $now));
        self::assertSame($state['generated_at'], $cache->generatedAt());
        self::assertSame($state['age'], $cache->age($now));
        self::assertSame($state['expires_at'], $cache->expiresAt(60));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function integrityMismatchMetadataProvider(): iterable
    {
        yield 'routes hash mismatch' => [['routes_hash' => str_repeat('a', 64)] + self::metadataFor([])];
        yield 'route count mismatch' => [['route_count' => 3] + self::metadataFor([])];
    }

    public function testSaveAndWarmRefuseDuplicateRouteNamesWithoutWritingTheFile(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/users/1', 'UserController@showOne', name: 'users.show'));
        $routes->add(Route::define('GET', '/users/2', 'UserController@showTwo', name: 'users.show'));

        $cache = new RouteCache($this->cacheFile);

        foreach ([static fn () => $cache->save($routes), static fn () => $cache->warm($routes), static fn () => $cache->warmIfStale($routes, 60)] as $write) {
            try {
                $write();
                self::fail('Expected a RouteCacheException');
            } catch (RouteCacheException $exception) {
                self::assertSame('Route cache not written: more than one route is named "users.show"; give each route a unique name', $exception->getMessage());
            }
        }

        self::assertFileDoesNotExist($this->cacheFile);
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
        $cacheDirectory = sys_get_temp_dir() . '/zephyrus2-route-cache-' . bin2hex(random_bytes(8));
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

    public function testSaveAndLoadRoundTripKeepsTheExcludedMiddlewares(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/', 'HomeController@index', excludedMiddlewares: [
            SessionMiddleware::class,
            AuthGuardMiddleware::class,
        ]));
        $routes->add(Route::define('GET', '/account', 'AccountController@show'));

        $cache = new RouteCache($this->cacheFile);
        $cache->save($routes);
        $loaded = $cache->load()->all();

        self::assertSame([SessionMiddleware::class, AuthGuardMiddleware::class], $loaded[0]->excludedMiddlewares);
        self::assertSame([], $loaded[1]->excludedMiddlewares);
        self::assertTrue($cache->isFresh($routes));
    }

    public function testEntryWithoutExcludedMiddlewaresLoadsAsExcludingNothing(): void
    {
        $routes = [[
            'method' => 'GET',
            'path' => '/',
            'handler' => 'HomeController@index',
            'constraints' => [],
            'middlewares' => ['auth'],
            'name' => 'home',
        ]];

        file_put_contents($this->cacheFile, json_encode([
            'meta' => self::metadataFor($routes),
            'routes' => $routes,
        ], JSON_THROW_ON_ERROR));

        $loaded = (new RouteCache($this->cacheFile))->load()->all();

        self::assertSame([], $loaded[0]->excludedMiddlewares);
        self::assertSame(['auth'], $loaded[0]->middlewares);
    }

    public function testFileWrittenBeforeExclusionsExistedStaysFreshForRoutesExcludingNothing(): void
    {
        $routes = [[
            'method' => 'GET',
            'path' => '/',
            'handler' => 'HomeController@index',
            'constraints' => [],
            'middlewares' => [],
            'name' => null,
        ]];

        file_put_contents($this->cacheFile, json_encode([
            'meta' => self::metadataFor($routes),
            'routes' => $routes,
        ], JSON_THROW_ON_ERROR));

        $current = new RouteCollection();
        $current->add(Route::define('GET', '/', 'HomeController@index'));

        self::assertTrue((new RouteCache($this->cacheFile))->isFresh($current));
    }

    public function testCacheStopsBeingFreshWhenAnExclusionIsAdded(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/', 'HomeController@index'));

        $cache = new RouteCache($this->cacheFile);
        $cache->save($routes);

        $excluding = new RouteCollection();
        $excluding->add(Route::define('GET', '/', 'HomeController@index', excludedMiddlewares: [SessionMiddleware::class]));

        self::assertFalse($cache->isFresh($excluding));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedExcludedMiddlewares(): iterable
    {
        yield 'string instead of list' => [SessionMiddleware::class];
        yield 'non-string entry' => [[SessionMiddleware::class, 7]];
        yield 'nested list' => [[[SessionMiddleware::class]]];
    }

    #[DataProvider('malformedExcludedMiddlewares')]
    public function testLoadRefusesMalformedExcludedMiddlewares(mixed $excluded): void
    {
        $routes = [[
            'method' => 'GET',
            'path' => '/',
            'handler' => 'HomeController@index',
            'excluded_middlewares' => $excluded,
        ]];

        file_put_contents($this->cacheFile, json_encode([
            'meta' => self::metadataFor($routes),
            'routes' => $routes,
        ], JSON_THROW_ON_ERROR));

        $this->assertRefused(fn (): mixed => (new RouteCache($this->cacheFile))->load(), 'excluded middlewares');
    }

    public function testLoadRefusesAnEntryExcludingASecurityMiddleware(): void
    {
        $routes = [[
            'method' => 'POST',
            'path' => '/webhook',
            'handler' => 'HookController@receive',
            'excluded_middlewares' => [CsrfMiddleware::class],
        ]];

        file_put_contents($this->cacheFile, json_encode([
            'meta' => self::metadataFor($routes),
            'routes' => $routes,
        ], JSON_THROW_ON_ERROR));

        $this->assertRefused(fn (): mixed => (new RouteCache($this->cacheFile))->load(), 'security.csrf.exceptions');
    }

    /**
     * @param array<int, array<string, mixed>> $routes
     * @return array{version: int, routes_hash: string, route_count: int, generated_at: int}
     */
    private static function metadataFor(array $routes): array
    {
        return [
            'version' => 1,
            'routes_hash' => hash('sha256', json_encode($routes, JSON_THROW_ON_ERROR)),
            'route_count' => count($routes),
            'generated_at' => 1700000000,
        ];
    }
}
