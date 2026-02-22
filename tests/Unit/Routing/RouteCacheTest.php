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
                'routes_hash' => 'invalid-hash',
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
}
