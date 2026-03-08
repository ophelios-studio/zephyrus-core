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

    /**
     * When true (default), a trailing slash on the incoming request path is
     * ignored so that /users and /users/ resolve to the same route.  Set to
     * false to require paths to match exactly, trailing slash and all.
     */
    public function __construct(
        private readonly bool $trailingSlashTolerant = true,
    ) {
    }

    public function add(Route $route): void
    {
        $this->routes[] = $route;
    }

    public function withRoute(Route $route): self
    {
        $collection = new self($this->trailingSlashTolerant);
        $collection->routes = $this->routes;
        $collection->routes[] = $route;

        return $collection;
    }

    public function withLastRouteName(string $name): self
    {
        if ($this->routes === []) {
            return $this;
        }

        $collection = new self($this->trailingSlashTolerant);
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

    public function hasNamed(string $name): bool
    {
        return $this->findByName($name) !== null;
    }

    /**
     * @return array<int, string>
     */
    public function methods(): array
    {
        $methods = array_values(array_unique(array_map(
            static fn (Route $route): string => $route->method,
            $this->routes,
        )));

        sort($methods);

        return $methods;
    }

    /**
     * @return array<int, string>
     */
    public function paths(): array
    {
        return array_map(
            static fn (Route $route): string => $route->path,
            $this->routes,
        );
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        $names = [];

        foreach ($this->routes as $route) {
            if ($route->name !== null) {
                $names[] = $route->name;
            }
        }

        return $names;
    }

    /**
     * @return array<int, string>
     */
    public function handlers(): array
    {
        return array_map(
            static fn (Route $route): string => $route->handler,
            $this->routes,
        );
    }

    /**
     * @return array<string, int>
     */
    public function methodHistogram(): array
    {
        $histogram = [];

        foreach ($this->routes as $route) {
            $histogram[$route->method] = ($histogram[$route->method] ?? 0) + 1;
        }

        ksort($histogram);

        return $histogram;
    }

    /**
     * @return array<string, int>
     */
    public function middlewareHistogram(): array
    {
        $histogram = [];

        foreach ($this->routes as $route) {
            foreach ($route->middlewares as $middleware) {
                $histogram[$middleware] = ($histogram[$middleware] ?? 0) + 1;
            }
        }

        ksort($histogram);

        return $histogram;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function pathsByMethod(): array
    {
        $grouped = [];

        foreach ($this->routes as $route) {
            $grouped[$route->method] ??= [];
            $grouped[$route->method][] = $route->path;
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * @return array<int, string>
     */
    public function uniqueMiddlewares(): array
    {
        $unique = array_keys($this->middlewareHistogram());
        sort($unique);

        return $unique;
    }

    /**
     * @return array<string, int>
     */
    public function parameterHistogram(): array
    {
        $histogram = [];

        foreach ($this->routes as $route) {
            preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $route->path, $matches);

            foreach ($matches[1] ?? [] as $parameter) {
                $name = (string) $parameter;
                $histogram[$name] = ($histogram[$name] ?? 0) + 1;
            }
        }

        ksort($histogram);

        return $histogram;
    }

    /**
     * @return array<string, int>
     */
    public function constrainedParameterHistogram(): array
    {
        $histogram = [];

        foreach ($this->routes as $route) {
            foreach (array_keys($route->constraints) as $parameter) {
                $name = (string) $parameter;
                $histogram[$name] = ($histogram[$name] ?? 0) + 1;
            }
        }

        ksort($histogram);

        return $histogram;
    }

    /**
     * @return array<string, int>
     */
    public function controllerHistogram(): array
    {
        $histogram = [];

        foreach ($this->routes as $route) {
            [$controller] = explode('@', $route->handler, 2);
            $histogram[$controller] = ($histogram[$controller] ?? 0) + 1;
        }

        ksort($histogram);

        return $histogram;
    }

    public function staticRouteCount(): int
    {
        $count = 0;

        foreach ($this->routes as $route) {
            if (!str_contains($route->path, '{')) {
                $count++;
            }
        }

        return $count;
    }

    public function parameterizedRouteCount(): int
    {
        return $this->count() - $this->staticRouteCount();
    }

    public function constrainedRouteCount(): int
    {
        $count = 0;

        foreach ($this->routes as $route) {
            if ($route->constraints !== []) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array{total: int, named: int, unnamed: int, duplicate_names: int, methods: array<string, int>, middlewares: array<string, int>, middleware_count: int, paths_by_method: array<string, array<int, string>>, parameters: array<string, int>, constrained_parameters: array<string, int>, controllers: array<string, int>, controller_count: int, static_routes: int, parameterized_routes: int, constrained_routes: int}
     */
    public function summary(): array
    {
        $total = $this->count();
        $named = count($this->names());
        $middlewares = $this->middlewareHistogram();
        $controllers = $this->controllerHistogram();

        return [
            'total' => $total,
            'named' => $named,
            'unnamed' => $total - $named,
            'duplicate_names' => count($this->duplicateRouteNames()),
            'methods' => $this->methodHistogram(),
            'middlewares' => $middlewares,
            'middleware_count' => count($middlewares),
            'paths_by_method' => $this->pathsByMethod(),
            'parameters' => $this->parameterHistogram(),
            'constrained_parameters' => $this->constrainedParameterHistogram(),
            'controllers' => $controllers,
            'controller_count' => count($controllers),
            'static_routes' => $this->staticRouteCount(),
            'parameterized_routes' => $this->parameterizedRouteCount(),
            'constrained_routes' => $this->constrainedRouteCount(),
        ];
    }

    /**
     * @return array<string, Route>
     */
    public function namedRoutes(): array
    {
        $this->assertNoDuplicateRouteNames();

        $named = [];

        foreach ($this->routes as $route) {
            if ($route->name !== null) {
                $named[$route->name] = $route;
            }
        }

        return $named;
    }

    /**
     * @return array<int, Route>
     */
    public function routesByMethod(string $method): array
    {
        $normalized = strtoupper($method);

        return array_values(array_filter(
            $this->routes,
            static fn (Route $route): bool => $route->method === $normalized,
        ));
    }

    /**
     * @return array<int, string>
     */
    public function allowedMethodsForPath(string $path): array
    {
        $normalizedPath = $this->normalizePath($path);
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            if ($this->extractParameters($route, $normalizedPath) === null) {
                continue;
            }

            $allowedMethods[] = $route->method;

            if ($route->method === 'GET') {
                $allowedMethods[] = 'HEAD';
            }
        }

        $allowedMethods = array_values(array_unique($allowedMethods));
        sort($allowedMethods);

        return $allowedMethods;
    }

    /**
     * @return array<int, string>
     */
    public function duplicateRouteNames(): array
    {
        $counts = [];

        foreach ($this->names() as $name) {
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        $duplicates = [];

        foreach ($counts as $name => $count) {
            if ($count > 1) {
                $duplicates[] = $name;
            }
        }

        sort($duplicates);

        return $duplicates;
    }

    public function assertNoDuplicateRouteNames(): void
    {
        $duplicates = $this->duplicateRouteNames();

        if ($duplicates !== []) {
            throw new RouteSignatureException(sprintf(
                'Duplicate route names detected: %s',
                implode(', ', $duplicates),
            ));
        }
    }

    public function isTrailingSlashTolerant(): bool
    {
        return $this->trailingSlashTolerant;
    }

    public function count(): int
    {
        return count($this->routes);
    }

    public function isEmpty(): bool
    {
        return $this->routes === [];
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

        if ($this->trailingSlashTolerant) {
            $normalized = '/' . trim($parsedPath, '/');

            return $normalized === '/' ? '/' : $normalized;
        }

        // Strict mode: preserve a trailing slash so that /users/ and /users are
        // treated as distinct paths during segment-count comparison.
        $hasTrailingSlash = str_ends_with($parsedPath, '/') && strlen($parsedPath) > 1;
        $normalized = '/' . trim($parsedPath, '/');

        if ($normalized === '/') {
            return '/';
        }

        return $hasTrailingSlash ? $normalized . '/' : $normalized;
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
