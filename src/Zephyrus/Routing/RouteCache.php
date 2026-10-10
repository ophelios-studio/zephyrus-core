<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use JsonException;
use Zephyrus\Routing\Exception\RouteCacheException;
use Zephyrus\Routing\Exception\RouteMiddlewareException;

/**
 * Persists the compiled route table as a JSON file and validates it on load.
 *
 * The file is {"meta": {...}, "routes": [...]}. meta holds the format version (1), the sha256 of the
 * routes section, the route count and generated_at. load() refuses a file without meta, an unknown
 * version, or a count or hash mismatch. The hash detects corruption, not tampering, so protect the
 * file with permissions. A cache whose hash no longer matches the current routes is stale.
 */
final class RouteCache
{
    private const METADATA_VERSION = 1;

    /** Optional entry key: a missing key loads as an empty list. */
    private const EXCLUDED_MIDDLEWARES_KEY = 'excluded_middlewares';

    private const REASON_MISSING_FILE = 'missing-file';
    private const REASON_INVALID_METADATA = 'invalid-metadata';
    private const REASON_EXPIRED = 'expired';
    private const REASON_STALE_ROUTES = 'stale-routes';
    private const REASON_FRESH = 'fresh';

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

    /**
     * Deletes the cache file when present.
     *
     * @throws RouteCacheException When the file exists and cannot be deleted.
     */
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
        if ($this->metadataFailure($meta) !== null) {
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

        return $this->metadataFailure($meta) === null ? $meta : null;
    }

    public function generatedAt(): ?int
    {
        $meta = $this->metadata();

        return $meta['generated_at'] ?? null;
    }

    public function routeCount(): ?int
    {
        $meta = $this->metadata();

        return $meta['route_count'] ?? null;
    }

    public function routesHash(): ?string
    {
        $meta = $this->metadata();

        return $meta['routes_hash'] ?? null;
    }

    public function metadataVersion(): ?int
    {
        $meta = $this->metadata();

        return $meta['version'] ?? null;
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

    /**
     * Returns the instant the cache expires for $maxAgeSeconds, or null when there is no cache.
     *
     * @throws RouteCacheException When $maxAgeSeconds is negative.
     */
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

    /**
     * @throws RouteCacheException When $maxAgeSeconds is negative.
     */
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

    /**
     * @throws RouteCacheException When $maxAgeSeconds is negative.
     */
    public function isFreshWithin(RouteCollection $routes, int $maxAgeSeconds, ?int $now = null): bool
    {
        if ($this->isExpired($maxAgeSeconds, $now)) {
            return false;
        }

        return $this->isFresh($routes);
    }

    /**
     * @throws RouteCacheException When the cache is missing, invalid, expired or stale, or $maxAgeSeconds is negative.
     */
    public function ensureFreshWithin(RouteCollection $routes, int $maxAgeSeconds, ?int $now = null): void
    {
        $state = $this->inspect($routes, $maxAgeSeconds, $now);
        $message = $this->reasonToExceptionMessage($state['reason']);

        if ($message !== null) {
            throw new RouteCacheException($message);
        }
    }

    /**
     * @throws RouteCacheException When $maxAgeSeconds is negative.
     */
    public function canUseWithin(RouteCollection $routes, int $maxAgeSeconds, ?int $now = null): bool
    {
        return $this->inspect($routes, $maxAgeSeconds, $now)['reason'] === self::REASON_FRESH;
    }

    /**
     * Inspects the cache state for diagnostics.
     *
     * @throws RouteCacheException When $maxAgeSeconds is negative.
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

    /**
     * Writes the routes to the cache file.
     *
     * @throws RouteCacheException When encoding, creating the directory or writing the file fails.
     */
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

        $directory = dirname($this->cacheFile);
        if ($directory !== '' && $directory !== '.' && !is_dir($directory)) {
            // Explicit 0755, never world-writable: the cache drives Class@method dispatch.
            $created = @mkdir($directory, 0755, true);
            if ($created === false && !is_dir($directory)) {
                throw new RouteCacheException(sprintf('Unable to create route cache directory: %s', $directory));
            }
        }

        // Written to a temporary file then renamed, so a concurrent load() never reads a partial payload.
        // The mode is set explicitly, not left to the umask.
        $temporary = $this->cacheFile . '.' . bin2hex(random_bytes(8)) . '.tmp';

        $result = @file_put_contents($temporary, $json);
        if ($result === false) {
            @unlink($temporary);

            throw new RouteCacheException(sprintf('Unable to write route cache file: %s', $this->cacheFile));
        }

        @chmod($temporary, 0644);

        if (@rename($temporary, $this->cacheFile) === false) {
            @unlink($temporary);

            throw new RouteCacheException(sprintf('Unable to write route cache file: %s', $this->cacheFile));
        }
    }

    /**
     * Saves the routes and returns the written metadata.
     *
     * @return array{version: int, routes_hash: string, route_count: int, generated_at: int}
     * @throws RouteCacheException When the save fails.
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
     * Rewrites the cache only when it is not fresh within $maxAgeSeconds.
     *
     * @return bool True when the cache was written, false when it was already fresh.
     * @throws RouteCacheException When $maxAgeSeconds is negative or the save fails.
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

    /**
     * Loads and validates the cache file into a RouteCollection.
     *
     * @throws RouteCacheException When the file is missing or unreadable, or fails a structure, metadata,
     *                             hash or route validation.
     */
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
        $refusal = $this->metadataFailure($meta);
        if ($refusal !== null) {
            throw new RouteCacheException($refusal);
        }

        $routesPayload = $decoded['routes'];
        if ($meta['route_count'] !== count($routesPayload)) {
            throw new RouteCacheException($this->refusal('has a route count that does not match its routes section'));
        }

        try {
            $actualHash = $this->computePayloadHash($routesPayload);
        } catch (RouteCacheException $exception) {
            throw new RouteCacheException($this->refusal('has a routes section that cannot be hashed'), previous: $exception);
        }

        if (!hash_equals($meta['routes_hash'], $actualHash)) {
            throw new RouteCacheException($this->refusal('has a routes hash that does not match its routes section'));
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
            $excludedMiddlewares = $entry[self::EXCLUDED_MIDDLEWARES_KEY] ?? [];

            if (!is_array($constraints) || !is_array($middlewares) || ($name !== null && !is_string($name))) {
                throw new RouteCacheException('Route cache entry contains invalid optional fields');
            }

            if (!is_array($excludedMiddlewares)) {
                throw new RouteCacheException('Route cache entry contains invalid excluded middlewares list');
            }

            $this->assertValidConstraints($constraints);
            $this->assertValidMiddlewares($middlewares);
            $this->assertValidRouteName($name);
            $this->assertValidExcludedMiddlewares($excludedMiddlewares);

            try {
                // Any invalid entry must surface as RouteCacheException.
                $route = Route::define(
                    method: $entry['method'],
                    path: $entry['path'],
                    handler: $entry['handler'],
                    constraints: $constraints,
                    middlewares: $middlewares,
                    name: $name,
                    excludedMiddlewares: $excludedMiddlewares,
                );
            } catch (\Zephyrus\Routing\Exception\RouteSignatureException | RouteMiddlewareException $exception) {
                throw new RouteCacheException($exception->getMessage(), previous: $exception);
            }

            $collection->add($route);
        }

        try {
            $collection->assertNoDuplicateRouteNames();
        } catch (\Zephyrus\Routing\Exception\RouteSignatureException $exception) {
            throw new RouteCacheException($exception->getMessage(), previous: $exception);
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
                'reason' => self::REASON_MISSING_FILE,
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
                'reason' => self::REASON_INVALID_METADATA,
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
                'reason' => self::REASON_EXPIRED,
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
                'reason' => self::REASON_STALE_ROUTES,
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
            'reason' => self::REASON_FRESH,
            'age' => $age,
            'expires_at' => $expiresAt,
            'generated_at' => $meta['generated_at'],
        ];
    }

    private function reasonToExceptionMessage(string $reason): ?string
    {
        return match ($reason) {
            self::REASON_MISSING_FILE => 'Route cache file is missing',
            self::REASON_INVALID_METADATA => 'Route cache metadata is missing or invalid',
            self::REASON_EXPIRED => 'Route cache is expired',
            self::REASON_STALE_ROUTES => 'Route cache does not match current routes',
            default => null,
        };
    }

    /**
     * Builds the routes section. The excluded middlewares key is written only when non-empty, which keeps
     * the hash of a route table without exclusions unchanged.
     *
     * @param array<int, Route> $routes
     * @return array<int, array{method: string, path: string, handler: string, constraints: array<string, string>, middlewares: array<int, string>, name: ?string, excluded_middlewares?: list<string>}>
     */
    private function routesToPayload(array $routes): array
    {
        return array_map(
            static function (Route $route): array {
                $entry = [
                    'method' => $route->method,
                    'path' => $route->path,
                    'handler' => $route->handler,
                    'constraints' => $route->constraints,
                    'middlewares' => $route->middlewares,
                    'name' => $route->name,
                ];

                if ($route->excludedMiddlewares !== []) {
                    $entry[self::EXCLUDED_MIDDLEWARES_KEY] = $route->excludedMiddlewares;
                }

                return $entry;
            },
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
     * @param array<int, array{method: string, path: string, handler: string, constraints: array<string, string>, middlewares: array<int, string>, name: ?string, excluded_middlewares?: list<string>}> $routesPayload
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
     * @return string|null The refusal message, or null when the metadata is valid.
     */
    private function metadataFailure(mixed $meta): ?string
    {
        if ($meta === null) {
            return $this->refusal('has no metadata section');
        }

        if (!is_array($meta)) {
            return $this->refusal('has a metadata section that is not an object');
        }

        if (!is_string($meta['routes_hash'] ?? null)) {
            return $this->refusal('has a routes hash that is not a string');
        }

        if (($meta['version'] ?? null) !== self::METADATA_VERSION) {
            return $this->refusal('has an unsupported metadata version');
        }

        if (preg_match('/^[a-f0-9]{64}$/D', $meta['routes_hash']) !== 1) {
            return $this->refusal('has a malformed routes hash');
        }

        if (!is_int($meta['route_count'] ?? null) || $meta['route_count'] < 0) {
            return $this->refusal('has an invalid route count');
        }

        if (!is_int($meta['generated_at'] ?? null)) {
            return $this->refusal('has an invalid generation timestamp');
        }

        return null;
    }

    private function refusal(string $problem): string
    {
        return sprintf('Route cache file %s %s, rebuild the cache with save() or warm()', $this->cacheFile, $problem);
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
        $this->assertAllStrings($middlewares, 'middlewares');
    }

    /**
     * @param array<mixed> $middlewares
     */
    private function assertValidExcludedMiddlewares(array $middlewares): void
    {
        if (!array_is_list($middlewares)) {
            throw new RouteCacheException('Route cache entry contains invalid excluded middlewares list');
        }

        $this->assertAllStrings($middlewares, 'excluded middlewares');
    }

    /**
     * @param array<mixed> $values
     */
    private function assertAllStrings(array $values, string $label): void
    {
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new RouteCacheException(sprintf('Route cache entry contains invalid %s list', $label));
            }
        }
    }

    private function assertValidMethodPathAndHandler(string $method, string $path, string $handler): void
    {
        if (preg_match('/^[A-Z]+$/D', $method) !== 1) {
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
