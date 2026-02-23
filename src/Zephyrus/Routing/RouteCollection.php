<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use Zephyrus\Routing\Exception\MethodNotAllowedException;
use Zephyrus\Routing\Exception\RouteNotFoundException;
use Zephyrus\Routing\Exception\RouteSignatureException;

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

    public function withRoute(Route $route): self
    {
        $collection = new self();
        $collection->routes = $this->routes;
        $collection->routes[] = $route;

        return $collection;
    }

    public function withLastRouteName(string $name): self
    {
        if ($this->routes === []) {
            return $this;
        }

        $collection = new self();
        $collection->routes = $this->routes;

        $lastIndex = count($collection->routes) - 1;
        $collection->routes[$lastIndex] = $collection->routes[$lastIndex]->withName($name);

        return $collection;
    }

    public function findByName(string $name): ?Route
    {
        foreach ($this->routes as $route) {
            if ($route->name === $name) {
                return $route;
            }
        }

        return null;
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
        $normalizedPath = $this->normalizePath($path);
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            $parameters = $this->extractParameters($route, $normalizedPath);

            if ($parameters === null) {
                continue;
            }

            if (!$this->routeAcceptsMethod($route, $method)) {
                $allowedMethods[] = $route->method;

                if ($route->method === 'GET') {
                    $allowedMethods[] = 'HEAD';
                }

                continue;
            }

            return new RouteMatch(route: $route, parameters: $parameters);
        }

        if ($allowedMethods !== []) {
            $allowedMethods = array_values(array_unique($allowedMethods));
            sort($allowedMethods);

            throw new MethodNotAllowedException($allowedMethods, $normalizedPath);
        }

        throw new RouteNotFoundException(sprintf('No route matched %s %s', strtoupper($method), $normalizedPath));
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
                $regex = $this->compileConstraintRegex($route, $name, $pattern);

                $matched = @preg_match($regex, $candidate);
                if ($matched !== 1) {
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
        $normalized = $this->normalizePath($path);

        if ($normalized === '/') {
            return [];
        }

        return array_map(
            static fn (string $segment): string => rawurldecode($segment),
            explode('/', ltrim($normalized, '/')),
        );
    }

    private function normalizePath(string $path): string
    {
        $parsedPath = (string) parse_url($path, PHP_URL_PATH);

        $normalized = '/' . trim($parsedPath, '/');

        return $normalized === '/' ? '/' : $normalized;
    }

    private function routeAcceptsMethod(Route $route, string $method): bool
    {
        $normalizedMethod = strtoupper($method);

        if ($route->matchesMethod($normalizedMethod)) {
            return true;
        }

        return $normalizedMethod === 'HEAD' && $route->method === 'GET';
    }

    private function compileConstraintRegex(Route $route, string $parameterName, string $pattern): string
    {
        $regex = '~^(?:' . str_replace('~', '\\~', $pattern) . ')$~';

        if (@preg_match($regex, '') === false) {
            throw new RouteSignatureException(sprintf(
                'Invalid route constraint pattern for parameter "%s" on route "%s": %s',
                $parameterName,
                $route->path,
                $pattern,
            ));
        }

        return $regex;
    }

    private function isParameterSegment(string $segment): bool
    {
        return str_starts_with($segment, '{') && str_ends_with($segment, '}') && strlen($segment) > 2;
    }
}
