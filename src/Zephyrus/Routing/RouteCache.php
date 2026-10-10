<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use JsonException;
use Zephyrus\Exceptions\MessageValue;
use Zephyrus\Routing\Exception\RouteCacheException;
use Zephyrus\Routing\Exception\RouteMiddlewareException;
use Zephyrus\Routing\Exception\RouteSignatureException;

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
    private const REASON_INVALID_PAYLOAD = 'invalid-payload';
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

    /**
     * Whether load() accepts the cache and its routes hash matches $routes.
     */
    public function isFresh(RouteCollection $routes): bool
    {
        return $this->diagnose($routes, null, null)['fresh'];
    }

    /**
     * Returns the metadata when the file decodes and its sections and metadata are well formed, or null.
     * A non-null result does not mean the file is usable: load() may still refuse it.
     *
     * @return array{version: int, routes_hash: string, route_count: int, generated_at: int}|null
     */
    public function metadata(): ?array
    {
        if (!$this->has()) {
            return null;
        }

        $decoded = $this->decodeFile();
        if (is_string($decoded)) {
            return null;
        }

        return $this->headerFailure($decoded) === null ? $decoded['meta'] : null;
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

        return self::ageOf($generatedAt, $now ?? time());
    }

    /**
     * Returns the instant the cache expires for $maxAgeSeconds, or null when there is no cache.
     *
     * @throws RouteCacheException When $maxAgeSeconds is negative.
     */
    public function expiresAt(int $maxAgeSeconds): ?int
    {
        self::assertMaxAge($maxAgeSeconds);

        $generatedAt = $this->generatedAt();

        return $generatedAt === null ? null : self::expiryOf($generatedAt, $maxAgeSeconds);
    }

    /**
     * @throws RouteCacheException When $maxAgeSeconds is negative.
     */
    public function isExpired(int $maxAgeSeconds, ?int $now = null): bool
    {
        self::assertMaxAge($maxAgeSeconds);

        $generatedAt = $this->generatedAt();
        if ($generatedAt === null) {
            return true;
        }

        $currentTime = $now ?? time();

        return $generatedAt > $currentTime || $currentTime > self::expiryOf($generatedAt, $maxAgeSeconds);
    }

    /**
     * @throws RouteCacheException When $maxAgeSeconds is negative.
     */
    public function isFreshWithin(RouteCollection $routes, int $maxAgeSeconds, ?int $now = null): bool
    {
        return $this->inspect($routes, $maxAgeSeconds, $now)['fresh'];
    }

    /**
     * @throws RouteCacheException When the cache is missing, invalid, expired or stale, or $maxAgeSeconds is negative.
     */
    public function ensureFreshWithin(RouteCollection $routes, int $maxAgeSeconds, ?int $now = null): void
    {
        self::assertMaxAge($maxAgeSeconds);

        $state = $this->diagnose($routes, $maxAgeSeconds, $now);
        if (!$state['fresh']) {
            throw $this->refuse($state['problem']);
        }
    }

    /**
     * @throws RouteCacheException When $maxAgeSeconds is negative.
     */
    public function canUseWithin(RouteCollection $routes, int $maxAgeSeconds, ?int $now = null): bool
    {
        return $this->isFreshWithin($routes, $maxAgeSeconds, $now);
    }

    /**
     * Inspects the cache state for diagnostics.
     *
     * exists: the file is present.
     * metadata_valid: the sections and the metadata are accepted: false when the file is missing or cannot be
     * decoded, or when its sections or metadata are invalid.
     * fresh: load() accepts the file, it is within its window and its routes hash matches $routes.
     * expired: older than $maxAgeSeconds or dated in the future; also true when the file is missing or its
     * sections or metadata are invalid.
     * reason: one of missing-file, invalid-metadata, invalid-payload, expired, stale-routes, fresh.
     * age and expires_at: seconds since generation and the expiry instant.
     * generated_at: the generation instant.
     * problem: what a refusal would say after "Route cache file <file> has ", null when fresh.
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
     *   generated_at: ?int,
     *   problem: ?string
     * }
     */
    public function inspect(RouteCollection $routes, int $maxAgeSeconds, ?int $now = null): array
    {
        self::assertMaxAge($maxAgeSeconds);

        return $this->diagnose($routes, $maxAgeSeconds, $now);
    }

    /**
     * Writes the routes to the cache file.
     *
     * @throws RouteCacheException When load() would refuse the routes (for example two routes sharing a name),
     *                             or encoding, creating the directory or writing the file fails.
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

        $problem = $this->buildRoutes(json_decode($json, true, 512, JSON_THROW_ON_ERROR)['routes']);
        if (is_string($problem)) {
            throw new RouteCacheException(sprintf('Route cache not written: %s', $problem));
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
        self::assertMaxAge($maxAgeSeconds);

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
            throw $this->refuse('not been written');
        }

        $decoded = $this->decodeFile();
        if (is_string($decoded)) {
            throw $this->refuse($decoded);
        }

        $problem = $this->payloadFailure($decoded);
        if ($problem !== null) {
            throw $this->refuse($problem);
        }

        $routes = $this->buildRoutes($decoded['routes']);

        return is_string($routes) ? throw $this->refuse($routes) : $routes;
    }

    /**
     * Reads the file once and decides everything: the routes are built exactly as load() builds them, so
     * a cache that load() refuses is never fresh. $maxAgeSeconds null skips the age checks.
     *
     * @return array{
     *   exists: bool,
     *   metadata_valid: bool,
     *   fresh: bool,
     *   expired: bool,
     *   reason: string,
     *   age: ?int,
     *   expires_at: ?int,
     *   generated_at: ?int,
     *   problem: ?string
     * }
     */
    private function diagnose(RouteCollection $routes, ?int $maxAgeSeconds, ?int $now): array
    {
        if (!$this->has()) {
            return self::state(false, false, self::REASON_MISSING_FILE, 'not been written', expired: true);
        }

        $decoded = $this->decodeFile();
        if (is_string($decoded)) {
            return self::state(true, false, self::REASON_INVALID_METADATA, $decoded, expired: true);
        }

        $problem = $this->sectionFailure($decoded);
        if ($problem !== null) {
            return self::state(true, false, self::REASON_INVALID_PAYLOAD, $problem, expired: true);
        }

        $problem = $this->metadataFailure($decoded['meta'] ?? null);
        if ($problem !== null) {
            return self::state(true, false, self::REASON_INVALID_METADATA, $problem, expired: true);
        }

        $meta = $decoded['meta'];
        $generatedAt = $meta['generated_at'];
        $currentTime = $now ?? time();
        $age = self::ageOf($generatedAt, $currentTime);
        $expiresAt = $maxAgeSeconds === null ? null : self::expiryOf($generatedAt, $maxAgeSeconds);
        $future = $maxAgeSeconds !== null && $generatedAt > $currentTime;
        $expired = $future || ($expiresAt !== null && $currentTime > $expiresAt);

        $problem = $this->integrityFailure($decoded);
        if ($problem === null) {
            $built = $this->buildRoutes($decoded['routes']);
            $problem = is_string($built) ? $built : null;
        }

        if ($problem !== null) {
            return self::state(true, true, self::REASON_INVALID_PAYLOAD, $problem, $age, $expiresAt, $generatedAt, $expired);
        }

        if ($expired) {
            return self::state(true, true, self::REASON_EXPIRED, $future ? 'a generation timestamp in the future' : 'expired', $age, $expiresAt, $generatedAt, true);
        }

        if (!hash_equals($meta['routes_hash'], $this->computeRoutesHash($routes->all()))) {
            return self::state(true, true, self::REASON_STALE_ROUTES, 'routes that differ from the current routes', $age, $expiresAt, $generatedAt);
        }

        return self::state(true, true, self::REASON_FRESH, null, $age, $expiresAt, $generatedAt);
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
     *   generated_at: ?int,
     *   problem: ?string
     * }
     */
    private static function state(
        bool $exists,
        bool $metadataValid,
        string $reason,
        ?string $problem,
        ?int $age = null,
        ?int $expiresAt = null,
        ?int $generatedAt = null,
        bool $expired = false,
    ): array {
        return [
            'exists' => $exists,
            'metadata_valid' => $metadataValid,
            'fresh' => $reason === self::REASON_FRESH,
            'expired' => $expired,
            'reason' => $reason,
            'age' => $age,
            'expires_at' => $expiresAt,
            'generated_at' => $generatedAt,
            'problem' => $problem,
        ];
    }

    private static function ageOf(int $generatedAt, int $now): ?int
    {
        return $generatedAt > $now ? null : $now - $generatedAt;
    }

    private static function expiryOf(int $generatedAt, int $maxAgeSeconds): int
    {
        return $maxAgeSeconds > PHP_INT_MAX - $generatedAt ? PHP_INT_MAX : $generatedAt + $maxAgeSeconds;
    }

    /**
     * @throws RouteCacheException When $maxAgeSeconds is negative.
     */
    private static function assertMaxAge(int $maxAgeSeconds): void
    {
        if ($maxAgeSeconds < 0) {
            throw new RouteCacheException('Route cache max age must be zero or greater');
        }
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
     * @param array<mixed> $decoded
     * @return string|null The first problem found in the structure, metadata, count or hash, or null.
     */
    private function payloadFailure(array $decoded): ?string
    {
        return $this->headerFailure($decoded) ?? $this->integrityFailure($decoded);
    }

    /**
     * @param array<mixed> $decoded
     * @return string|null The first problem found in the sections or the metadata, or null.
     */
    private function headerFailure(array $decoded): ?string
    {
        return $this->sectionFailure($decoded) ?? $this->metadataFailure($decoded['meta'] ?? null);
    }

    /**
     * @param array<mixed> $decoded
     */
    private function sectionFailure(array $decoded): ?string
    {
        $routesPayload = $decoded['routes'] ?? null;
        if ($routesPayload === null) {
            return 'no routes section';
        }

        return is_array($routesPayload) && array_is_list($routesPayload) ? null : 'a routes section that is not a list';
    }

    /**
     * Count and hash checks. The caller has already validated the sections and the metadata.
     *
     * @param array<mixed> $decoded
     */
    private function integrityFailure(array $decoded): ?string
    {
        $routesPayload = $decoded['routes'];
        $meta = $decoded['meta'];

        if ($meta['route_count'] !== count($routesPayload)) {
            return 'a route count that does not match its routes section';
        }

        try {
            $actualHash = $this->computePayloadHash($routesPayload);
        } catch (RouteCacheException) {
            return 'a routes section that cannot be hashed';
        }

        return hash_equals($meta['routes_hash'], $actualHash) ? null : 'a routes hash that does not match its routes section';
    }

    /**
     * @param mixed $meta
     * @return string|null The problem with the metadata, or null when it is valid.
     */
    private function metadataFailure(mixed $meta): ?string
    {
        if ($meta === null) {
            return 'no metadata section';
        }

        if (!is_array($meta)) {
            return 'a metadata section that is not an object';
        }

        if (($meta['version'] ?? null) !== self::METADATA_VERSION) {
            return 'been written by another version';
        }

        if (!is_string($meta['routes_hash'] ?? null)) {
            return 'a routes hash that is not a string';
        }

        if (preg_match('/^[a-f0-9]{64}$/D', $meta['routes_hash']) !== 1) {
            return 'a malformed routes hash';
        }

        if (!is_int($meta['route_count'] ?? null) || $meta['route_count'] < 0) {
            return 'an invalid route count';
        }

        if (!is_int($meta['generated_at'] ?? null) || $meta['generated_at'] < 0) {
            return 'an invalid generation timestamp';
        }

        return null;
    }

    private function refuse(string $problem): RouteCacheException
    {
        return RouteCacheException::refused($this->cacheFile, $problem);
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
     * @return array<mixed>|string The decoded payload, or the problem with the file contents.
     */
    private function decodeFile(): array|string
    {
        $contents = @file_get_contents($this->cacheFile);
        if ($contents === false) {
            return 'contents that cannot be read';
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return 'contents that are not valid JSON: ' . $exception->getMessage();
        }

        return is_array($decoded) ? $decoded : 'contents that do not decode to an object';
    }

    /**
     * @param array<mixed> $routesPayload
     * @return RouteCollection|string The routes, or the problem with an entry or with the table.
     */
    private function buildRoutes(array $routesPayload): RouteCollection|string
    {
        $collection = new RouteCollection();

        foreach ($routesPayload as $index => $entry) {
            $route = $this->routeFromEntry($entry);
            if (is_string($route)) {
                return $route . $this->entryLocation($index, $entry);
            }

            $collection->add($route);
        }

        $duplicates = $collection->duplicateRouteNames();
        if ($duplicates !== []) {
            return sprintf(
                'duplicate route names %s; give each route a unique name',
                MessageValue::quoteList(array_map(strval(...), $duplicates)),
            );
        }

        return $collection;
    }

    /**
     * Names the refused entry by its position and, when it is an object, its method and path.
     */
    private function entryLocation(int $index, mixed $entry): string
    {
        if (!is_array($entry)) {
            return sprintf(' at entry %d', $index);
        }

        return sprintf(' at entry %d [%s]', $index, MessageValue::quoteList([$entry['method'] ?? null, $entry['path'] ?? null]));
    }

    private function routeFromEntry(mixed $entry): Route|string
    {
        if (!is_array($entry)) {
            return 'a route that is not an object';
        }

        foreach (['method', 'path', 'handler'] as $requiredKey) {
            if (!array_key_exists($requiredKey, $entry) || !is_string($entry[$requiredKey])) {
                return sprintf('a route without a valid "%s"', $requiredKey);
            }
        }

        $problem = $this->methodPathAndHandlerFailure($entry['method'], $entry['path'], $entry['handler']);
        if ($problem !== null) {
            return $problem;
        }

        $constraints = $entry['constraints'] ?? [];
        $middlewares = $entry['middlewares'] ?? [];
        $name = $entry['name'] ?? null;
        $excludedMiddlewares = $entry[self::EXCLUDED_MIDDLEWARES_KEY] ?? [];

        if (!is_array($constraints) || !is_array($middlewares) || ($name !== null && !is_string($name))) {
            return 'a route with invalid optional fields';
        }

        if (!is_array($excludedMiddlewares)) {
            return 'a route with an invalid excluded middlewares list';
        }

        $problem = $this->constraintsFailure($constraints)
            ?? $this->allStringsFailure($middlewares, 'middlewares')
            ?? $this->routeNameFailure($name)
            ?? $this->excludedMiddlewaresFailure($excludedMiddlewares);
        if ($problem !== null) {
            return $problem;
        }

        try {
            return Route::define(
                method: $entry['method'],
                path: $entry['path'],
                handler: $entry['handler'],
                constraints: $constraints,
                middlewares: $middlewares,
                name: $name,
                excludedMiddlewares: $excludedMiddlewares,
            );
        } catch (RouteSignatureException | RouteMiddlewareException $exception) {
            return 'a route the router refuses: ' . MessageValue::escapeControls($exception->getMessage());
        }
    }

    /**
     * @param array<mixed> $constraints
     */
    private function constraintsFailure(array $constraints): ?string
    {
        foreach ($constraints as $parameter => $pattern) {
            if (!is_string($parameter) || !is_string($pattern)) {
                return 'a route with an invalid constraints map';
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $middlewares
     */
    private function excludedMiddlewaresFailure(array $middlewares): ?string
    {
        if (!array_is_list($middlewares)) {
            return 'a route with an invalid excluded middlewares list';
        }

        return $this->allStringsFailure($middlewares, 'excluded middlewares');
    }

    /**
     * @param array<mixed> $values
     */
    private function allStringsFailure(array $values, string $label): ?string
    {
        foreach ($values as $value) {
            if (!is_string($value)) {
                return sprintf('a route with an invalid %s list', $label);
            }
        }

        return null;
    }

    private function methodPathAndHandlerFailure(string $method, string $path, string $handler): ?string
    {
        if (preg_match('/^[A-Z]+$/D', $method) !== 1) {
            return 'a route with an invalid HTTP method format';
        }

        if ($path === '' || !str_starts_with($path, '/')) {
            return 'a route with an invalid route path';
        }

        if ($handler === '' || !str_contains($handler, '@')) {
            return 'a route with an invalid handler format';
        }

        return null;
    }

    private function routeNameFailure(?string $name): ?string
    {
        return $name !== null && trim($name) === '' ? 'a route with an invalid route name' : null;
    }
}
