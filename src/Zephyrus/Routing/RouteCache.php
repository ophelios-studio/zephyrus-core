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

    public function filePath(): string
    {
        return $this->cacheFile;
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
        if (!$this->isValidMetaForFreshness($meta)) {
            return false;
        }

        $routePayloadCount = count($decoded['routes']);
        if ($meta['route_count'] !== $routePayloadCount) {
            return false;
        }

        return hash_equals($meta['routes_hash'], $this->computeRoutesHash($routes->all()));
    }

    /**
     * @return array{version: int, routes_hash: string, route_count: int, generated_at: int}|null
     */
    public function metadata(): ?array
    {
        if (!$this->has()) {
            return null;
        }

        try {
            $decoded = $this->readPayload();
        } catch (RouteCacheException) {
            return null;
        }

        $meta = $decoded['meta'] ?? null;

        return $this->isValidMetaForFreshness($meta) ? $meta : null;
    }

    public function generatedAt(): ?int
    {
        $meta = $this->metadata();

        return $meta['generated_at'] ?? null;
    }

    public function age(?int $now = null): ?int
    {
        $generatedAt = $this->generatedAt();
        if ($generatedAt === null) {
            return null;
        }

        $currentTime = $now ?? time();

        if ($generatedAt > $currentTime) {
            return null;
        }

        return $currentTime - $generatedAt;
    }

    public function expiresAt(int $maxAgeSeconds): ?int
    {
        if ($maxAgeSeconds < 0) {
            throw new RouteCacheException('Route cache max age must be zero or greater');
        }

        $generatedAt = $this->generatedAt();
        if ($generatedAt === null) {
            return null;
        }

        return $generatedAt + $maxAgeSeconds;
    }

    public function isExpired(int $maxAgeSeconds, ?int $now = null): bool
    {
        $expiresAt = $this->expiresAt($maxAgeSeconds);
        if ($expiresAt === null) {
            return true;
        }

        $currentTime = $now ?? time();
        $generatedAt = $this->generatedAt();

        if ($generatedAt !== null && $generatedAt > $currentTime) {
            return true;
        }

        return $currentTime > $expiresAt;
    }

    public function isFreshWithin(RouteCollection $routes, int $maxAgeSeconds, ?int $now = null): bool
    {
        if ($this->isExpired($maxAgeSeconds, $now)) {
            return false;
        }

        return $this->isFresh($routes);
    }

    public function ensureFreshWithin(RouteCollection $routes, int $maxAgeSeconds, ?int $now = null): void
    {
        $state = $this->inspect($routes, $maxAgeSeconds, $now);

        $message = match ($state['reason']) {
            'missing-file' => 'Route cache file is missing',
            'invalid-metadata' => 'Route cache metadata is missing or invalid',
            'expired' => 'Route cache is expired',
            'stale-routes' => 'Route cache does not match current routes',
            default => null,
        };

        if ($message !== null) {
            throw new RouteCacheException($message);
        }
    }

    /**
     * Inspect cache state for diagnostics.
     *
     * @return array{
     *   exists: bool,
     *   metadata_valid: bool,
     *   fresh: bool,
     *   expired: bool,
     *   reason: string,
     *   age: ?int,
     *   expires_at: ?int,
     *   generated_at: ?int
     * }
     */
    public function inspect(RouteCollection $routes, int $maxAgeSeconds, ?int $now = null): array
    {
        if ($maxAgeSeconds < 0) {
            throw new RouteCacheException('Route cache max age must be zero or greater');
        }

        return $this->evaluateState($routes, $maxAgeSeconds, $now);
    }

    public function save(RouteCollection $routes): void
    {
        $routesPayload = $this->routesToPayload($routes->all());

        $payload = [
            'meta' => $this->buildMetadata($routesPayload),
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

    /**
     * Save the given routes and return the generated cache metadata.
     *
     * @return array{version: int, routes_hash: string, route_count: int, generated_at: int}
     */
    public function warm(RouteCollection $routes): array
    {
        $this->save($routes);

        $meta = $this->metadata();
        if ($meta === null) {
            throw new RouteCacheException('Route cache metadata unavailable after save');
        }

        return $meta;
    }

    /**
     * Warm the cache only when stale.
     *
     * Returns true when a new cache write happened, false when the existing
     * cache was already fresh within the requested max age window.
     */
    public function warmIfStale(RouteCollection $routes, int $maxAgeSeconds, ?int $now = null): bool
    {
        if ($maxAgeSeconds < 0) {
            throw new RouteCacheException('Route cache max age must be zero or greater');
        }

        if ($this->isFreshWithin($routes, $maxAgeSeconds, $now)) {
            return false;
        }

        $this->warm($routes);

        return true;
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

        if ($meta !== null && array_key_exists('route_count', $meta) && !is_int($meta['route_count'])) {
            throw new RouteCacheException('Route cache payload contains invalid metadata route count');
        }

        if ($meta !== null && array_key_exists('generated_at', $meta) && !is_int($meta['generated_at'])) {
            throw new RouteCacheException('Route cache payload contains invalid metadata generation timestamp');
        }

        $routesPayload = $decoded['routes'];
        if ($meta !== null) {
            if (array_key_exists('route_count', $meta) && $meta['route_count'] !== count($routesPayload)) {
                throw new RouteCacheException('Route cache payload metadata route count mismatch');
            }

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
     * @return array{
     *   exists: bool,
     *   metadata_valid: bool,
     *   fresh: bool,
     *   expired: bool,
     *   reason: string,
     *   age: ?int,
     *   expires_at: ?int,
     *   generated_at: ?int
     * }
     */
    private function evaluateState(RouteCollection $routes, int $maxAgeSeconds, ?int $now): array
    {
        if (!$this->has()) {
            return [
                'exists' => false,
                'metadata_valid' => false,
                'fresh' => false,
                'expired' => true,
                'reason' => 'missing-file',
                'age' => null,
                'expires_at' => null,
                'generated_at' => null,
            ];
        }

        $meta = $this->metadata();
        if ($meta === null) {
            return [
                'exists' => true,
                'metadata_valid' => false,
                'fresh' => false,
                'expired' => true,
                'reason' => 'invalid-metadata',
                'age' => null,
                'expires_at' => null,
                'generated_at' => null,
            ];
        }

        $age = $this->age($now);
        $expiresAt = $this->expiresAt($maxAgeSeconds);
        $expired = $this->isExpired($maxAgeSeconds, $now);

        if ($expired) {
            return [
                'exists' => true,
                'metadata_valid' => true,
                'fresh' => false,
                'expired' => true,
                'reason' => 'expired',
                'age' => $age,
                'expires_at' => $expiresAt,
                'generated_at' => $meta['generated_at'],
            ];
        }

        $fresh = $this->isFresh($routes);
        if (!$fresh) {
            return [
                'exists' => true,
                'metadata_valid' => true,
                'fresh' => false,
                'expired' => false,
                'reason' => 'stale-routes',
                'age' => $age,
                'expires_at' => $expiresAt,
                'generated_at' => $meta['generated_at'],
            ];
        }

        return [
            'exists' => true,
            'metadata_valid' => true,
            'fresh' => true,
            'expired' => false,
            'reason' => 'fresh',
            'age' => $age,
            'expires_at' => $expiresAt,
            'generated_at' => $meta['generated_at'],
        ];
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
     * @param array<int, array{method: string, path: string, handler: string, constraints: array<string, string>, middlewares: array<int, string>, name: ?string}> $routesPayload
     * @return array{version: int, routes_hash: string, route_count: int, generated_at: int}
     */
    private function buildMetadata(array $routesPayload): array
    {
        return [
            'version' => self::METADATA_VERSION,
            'routes_hash' => $this->computePayloadHash($routesPayload),
            'route_count' => count($routesPayload),
            'generated_at' => time(),
        ];
    }

    /**
     * @param mixed $meta
     */
    private function isValidMetaForFreshness(mixed $meta): bool
    {
        if (!is_array($meta)) {
            return false;
        }

        if (!isset($meta['version'], $meta['routes_hash'], $meta['route_count'])) {
            return false;
        }

        if (!is_int($meta['version']) || $meta['version'] !== self::METADATA_VERSION) {
            return false;
        }

        if (!is_string($meta['routes_hash']) || preg_match('/^[a-f0-9]{64}$/', $meta['routes_hash']) !== 1) {
            return false;
        }

        return is_int($meta['route_count']) && $meta['route_count'] >= 0;
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
