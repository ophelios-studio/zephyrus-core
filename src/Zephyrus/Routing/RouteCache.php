<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use JsonException;
use Zephyrus\Routing\Exception\RouteCacheException;

final class RouteCache
{
    public function __construct(private string $cacheFile)
    {
    }

    public function save(RouteCollection $routes): void
    {
        $payload = array_map(
            static fn (Route $route): array => [
                'method' => $route->method,
                'path' => $route->path,
                'handler' => $route->handler,
                'constraints' => $route->constraints,
                'middlewares' => $route->middlewares,
                'name' => $route->name,
            ],
            $routes->all(),
        );

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        } catch (JsonException $exception) {
            throw new RouteCacheException('Unable to encode route cache payload', previous: $exception);
        }

        if ($json === false) {
            throw new RouteCacheException('Unable to encode route cache payload');
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

        $collection = new RouteCollection();

        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                throw new RouteCacheException('Route cache entry must be an object-like array');
            }

            foreach (['method', 'path', 'handler'] as $requiredKey) {
                if (!array_key_exists($requiredKey, $entry) || !is_string($entry[$requiredKey])) {
                    throw new RouteCacheException(sprintf('Route cache entry missing valid "%s"', $requiredKey));
                }
            }

            $constraints = $entry['constraints'] ?? [];
            $middlewares = $entry['middlewares'] ?? [];
            $name = $entry['name'] ?? null;

            if (!is_array($constraints) || !is_array($middlewares) || ($name !== null && !is_string($name))) {
                throw new RouteCacheException('Route cache entry contains invalid optional fields');
            }

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
}
