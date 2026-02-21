<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use RuntimeException;

final class RouteCollection
{
    /**
     * @var array<int, Route>
     */
    private array $routes = [];

    public function add(Route $route): void
    {
        $this->routes[] = $route;
    }

    /**
     * @return array<int, Route>
     */
    public function all(): array
    {
        return $this->routes;
    }

    public function match(string $method, string $path): Route
    {
        $normalizedPath = '/' . trim($path, '/');
        $normalizedPath = $normalizedPath === '/' ? '/' : $normalizedPath;

        foreach ($this->routes as $route) {
            if (!$route->matchesMethod($method)) {
                continue;
            }

            if ($route->path === $normalizedPath) {
                return $route;
            }
        }

        throw new RuntimeException(sprintf('No route matched %s %s', strtoupper($method), $normalizedPath));
    }
}
