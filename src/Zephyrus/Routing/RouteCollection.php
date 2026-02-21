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

    public function match(string $method, string $path): RouteMatch
    {
        $normalizedPath = '/' . trim($path, '/');
        $normalizedPath = $normalizedPath === '/' ? '/' : $normalizedPath;

        foreach ($this->routes as $route) {
            if (!$route->matchesMethod($method)) {
                continue;
            }

            $parameters = $this->extractParameters($route, $normalizedPath);

            if ($parameters === null) {
                continue;
            }

            return new RouteMatch(route: $route, parameters: $parameters);
        }

        throw new RuntimeException(sprintf('No route matched %s %s', strtoupper($method), $normalizedPath));
    }

    /**
     * @return array<string, string>|null
     */
    private function extractParameters(Route $route, string $path): ?array
    {
        $routeSegments = $this->segments($route->path);
        $pathSegments = $this->segments($path);

        if (count($routeSegments) !== count($pathSegments)) {
            return null;
        }

        $parameters = [];

        foreach ($routeSegments as $index => $segment) {
            $candidate = $pathSegments[$index];

            if ($this->isParameterSegment($segment)) {
                $name = substr($segment, 1, -1);
                $pattern = $route->constraints[$name] ?? '[^/]+';

                $regex = '/^(?:' . str_replace('/', '\\/', $pattern) . ')$/';

                if (!preg_match($regex, $candidate)) {
                    return null;
                }

                $parameters[$name] = $candidate;
                continue;
            }

            if ($segment !== $candidate) {
                return null;
            }
        }

        return $parameters;
    }

    /**
     * @return array<int, string>
     */
    private function segments(string $path): array
    {
        if ($path === '/') {
            return [];
        }

        return explode('/', ltrim($path, '/'));
    }

    private function isParameterSegment(string $segment): bool
    {
        return str_starts_with($segment, '{') && str_ends_with($segment, '}') && strlen($segment) > 2;
    }
}
