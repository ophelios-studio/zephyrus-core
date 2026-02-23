<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use JsonException;
use Zephyrus\Routing\Exception\RouteCacheException;

final class RouteCache
{
    private const METADATA_VERSION = 1;

    public function __construct(private string $cacheFile)
    {
    }

    public function has(): bool
    {
        return is_file($this->cacheFile);
    }

    public function clear(): void
    {
        if (!$this->has()) {
            return;
        }

        if (@unlink($this->cacheFile) === false && is_file($this->cacheFile)) {
            throw new RouteCacheException(sprintf('Unable to delete route cache file: %s', $this->cacheFile));
        }
    }

    public function isFresh(RouteCollection $routes): bool
    {
        if (!$this->has()) {
            return false;
        }

        try {
            $decoded = $this->readPayload();
        } catch (RouteCacheException) {
            return false;
        }

        if (!isset($decoded['routes']) || !is_array($decoded['routes'])) {
            return false;
        }

        $meta = $decoded['meta'] ?? null;
        if (!is_array($meta) || !isset($meta['version'], $meta['routes_hash'])) {
            return false;
        }

        if (!is_int($meta['version']) || $meta['version'] !== self::METADATA_VERSION) {
            return false;
        }

        if (!is_string($meta['routes_hash']) || preg_match('/^[a-f0-9]{64}$/', $meta['routes_hash']) !== 1) {
            return false;
        }

        return hash_equals($meta['routes_hash'], $this->computeRoutesHash($routes->all()));
    }

    public function save(RouteCollection $routes): void
    {
        $routesPayload = $this->routesToPayload($routes->all());
        $routesHash = $this->computeRoutesHash($routes->all());

        $payload = [
            'meta' => [
                'version' => self::METADATA_VERSION,
                'routes_hash' => $routesHash,
            ],
            'routes' => $routesPayload,
        ];

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        } catch (JsonException $exception) {
            throw new RouteCacheException('Unable to encode route cache payload', previous: $exception);
        }

        if ($json === false) {
            throw new RouteCacheException('Unable to encode route cache payload');
        }

        $directory = dirname($this->cacheFile);
        if ($directory !== '' && $directory !== '.' && !is_dir($directory)) {
            $created = @mkdir($directory, 0777, true);
            if ($created === false && !is_dir($directory)) {
                throw new RouteCacheException(sprintf('Unable to create route cache directory: %s', $directory));
            }
        }

        $result = @file_put_contents($this->cacheFile, $json);

        if ($result === false) {
            throw new RouteCacheException(sprintf('Unable to write route cache file: %s', $this->cacheFile));
        }
    }

    public function load(): RouteCollection
    {
        if (!is_file($this->cacheFile)) {
            throw new RouteCacheException(sprintf('Route cache file does not exist: %s', $this->cacheFile));
        }

        $decoded = $this->readPayload();

        if (!isset($decoded['routes']) || !is_array($decoded['routes'])) {
            throw new RouteCacheException('Route cache payload missing routes section');
        }

        $meta = $decoded['meta'] ?? null;
        if ($meta !== null && (!is_array($meta) || !isset($meta['routes_hash']) || !is_string($meta['routes_hash']))) {
            throw new RouteCacheException('Route cache payload contains invalid metadata');
        }

        if ($meta !== null && (!array_key_exists('version', $meta) || !is_int($meta['version']) || $meta['version'] !== self::METADATA_VERSION)) {
            throw new RouteCacheException('Route cache payload contains unsupported metadata version');
        }

        if ($meta !== null && !preg_match('/^[a-f0-9]{64}$/', $meta['routes_hash'])) {
            throw new RouteCacheException('Route cache payload contains invalid metadata hash format');
        }

        $routesPayload = $decoded['routes'];
        if ($meta !== null) {
            try {
                $actualHash = $this->computePayloadHash($routesPayload);
            } catch (RouteCacheException $exception) {
                throw new RouteCacheException('Unable to validate route cache payload hash', previous: $exception);
            }

            if (!hash_equals($meta['routes_hash'], $actualHash)) {
                throw new RouteCacheException('Route cache payload hash mismatch');
            }
        }

        $collection = new RouteCollection();

        foreach ($routesPayload as $entry) {
            if (!is_array($entry)) {
                throw new RouteCacheException('Route cache entry must be an object-like array');
            }

            foreach (['method', 'path', 'handler'] as $requiredKey) {
                if (!array_key_exists($requiredKey, $entry) || !is_string($entry[$requiredKey])) {
                    throw new RouteCacheException(sprintf('Route cache entry missing valid "%s"', $requiredKey));
                }
            }

            $this->assertValidMethodPathAndHandler(
                $entry['method'],
                $entry['path'],
                $entry['handler'],
            );

            $constraints = $entry['constraints'] ?? [];
            $middlewares = $entry['middlewares'] ?? [];
            $name = $entry['name'] ?? null;

            if (!is_array($constraints) || !is_array($middlewares) || ($name !== null && !is_string($name))) {
                throw new RouteCacheException('Route cache entry contains invalid optional fields');
            }

            $this->assertValidConstraints($constraints);
            $this->assertValidMiddlewares($middlewares);
            $this->assertValidRouteName($name);

            $collection->add(Route::define(
                method: $entry['method'],
                path: $entry['path'],
                handler: $entry['handler'],
                constraints: $constraints,
                middlewares: $middlewares,
                name: $name,
            ));
        }

        return $collection;
    }

    /**
     * @param array<int, Route> $routes
     * @return array<int, array{method: string, path: string, handler: string, constraints: array<string, string>, middlewares: array<int, string>, name: ?string}>
     */
    private function routesToPayload(array $routes): array
    {
        return array_map(
            static fn (Route $route): array => [
                'method' => $route->method,
                'path' => $route->path,
                'handler' => $route->handler,
                'constraints' => $route->constraints,
                'middlewares' => $route->middlewares,
                'name' => $route->name,
            ],
            $routes,
        );
    }

    /**
     * @param array<int, Route> $routes
     */
    private function computeRoutesHash(array $routes): string
    {
        return $this->computePayloadHash($this->routesToPayload($routes));
    }

    /**
     * @param array<mixed> $payload
     */
    private function computePayloadHash(array $payload): string
    {
        try {
            return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new RouteCacheException('Unable to encode route cache payload', previous: $exception);
        }
    }

    /**
     * @return array<mixed>
     */
    private function readPayload(): array
    {
        $contents = @file_get_contents($this->cacheFile);

        if ($contents === false) {
            throw new RouteCacheException(sprintf('Unable to read route cache file: %s', $this->cacheFile));
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RouteCacheException('Unable to decode route cache payload', previous: $exception);
        }

        if (!is_array($decoded)) {
            throw new RouteCacheException('Route cache payload must decode to an array');
        }

        return $decoded;
    }

    /**
     * @param array<mixed> $constraints
     */
    private function assertValidConstraints(array $constraints): void
    {
        foreach ($constraints as $parameter => $pattern) {
            if (!is_string($parameter) || !is_string($pattern)) {
                throw new RouteCacheException('Route cache entry contains invalid constraints map');
            }
        }
    }

    /**
     * @param array<mixed> $middlewares
     */
    private function assertValidMiddlewares(array $middlewares): void
    {
        foreach ($middlewares as $middleware) {
            if (!is_string($middleware)) {
                throw new RouteCacheException('Route cache entry contains invalid middlewares list');
            }
        }
    }

    private function assertValidMethodPathAndHandler(string $method, string $path, string $handler): void
    {
        if (preg_match('/^[A-Z]+$/', $method) !== 1) {
            throw new RouteCacheException('Route cache entry contains invalid HTTP method format');
        }

        if ($path === '' || !str_starts_with($path, '/')) {
            throw new RouteCacheException('Route cache entry contains invalid route path');
        }

        if ($handler === '' || !str_contains($handler, '@')) {
            throw new RouteCacheException('Route cache entry contains invalid handler format');
        }
    }

    private function assertValidRouteName(?string $name): void
    {
        if ($name !== null && trim($name) === '') {
            throw new RouteCacheException('Route cache entry contains invalid route name');
        }
    }
}
