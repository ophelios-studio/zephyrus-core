<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use Zephyrus\Routing\Exception\MethodNotAllowedException;
use Zephyrus\Routing\Exception\RouteMiddlewareException;
use Zephyrus\Routing\Exception\RouteNotFoundException;
use Zephyrus\Routing\Exception\RouteSignatureException;

final class RouteCollection
{
    private const CONTROL_CHARACTER_PATTERN = '/[\x00-\x1F\x7F]/';

    /**
     * @var array<int, Route>
     */
    private array $routes = [];

    /**
     * Whether /users and /users/ resolve to the same route (default true).
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

    /**
     * Names the last added route.
     *
     * @throws \LogicException When the collection is empty.
     */
    public function withLastRouteName(string $name): self
    {
        if ($this->routes === []) {
            throw new \LogicException(sprintf('name("%s") must follow a route: add one before naming it.', $name));
        }

        $collection = new self($this->trailingSlashTolerant);
        $collection->routes = $this->routes;

        $lastIndex = count($collection->routes) - 1;
        $collection->routes[$lastIndex] = $collection->routes[$lastIndex]->withName($name);

        return $collection;
    }

    /**
     * Adds global middlewares the last added route skips, keeping the ones it already skips.
     *
     * @param array<int, string> $middlewares Global middleware classes or interfaces.
     * @throws \LogicException When the collection is empty.
     * @throws RouteMiddlewareException When a class is not a middleware or would skip a framework security
     *                                  middleware.
     */
    public function withLastRouteExcludedMiddlewares(array $middlewares): self
    {
        if ($this->routes === []) {
            throw new \LogicException('withoutMiddleware() must follow a route: add one before excluding a middleware.');
        }

        $collection = new self($this->trailingSlashTolerant);
        $collection->routes = $this->routes;

        $lastIndex = count($collection->routes) - 1;
        $last = $collection->routes[$lastIndex];
        $collection->routes[$lastIndex] = $last->withExcludedMiddlewares([...$last->excludedMiddlewares, ...$middlewares]);

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
            if ($this->isStaticRoute($route)) {
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
     * Every route whose pattern matches the path, whatever its method, in match order (static first).
     * A path holding a control character matches nothing.
     *
     * @return list<Route>
     */
    public function routesForPath(string $path): array
    {
        $normalizedPath = $this->normalizePath($path);

        if (self::pathRefusalReason($path, $normalizedPath) !== null) {
            return [];
        }

        return array_values(array_filter(
            $this->routesInMatchOrder(),
            fn (Route $route): bool => $this->extractParameters($route, $normalizedPath) !== null,
        ));
    }

    /**
     * @return array<int, string>
     */
    public function allowedMethodsForPath(string $path): array
    {
        $allowedMethods = [];

        foreach ($this->routesForPath($path) as $route) {
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
     * @return list<int|string>
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

    /**
     * @throws RouteSignatureException When a route name is used by more than one route.
     */
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

    /**
     * Matches a method and path, or throws.
     *
     * Routes without a parameter are tried before parameterised ones, each group in registration
     * order, and the first route matching both path and method wins. A 405 is raised only when a
     * route matches the path but none accepts the method; a GET route also accepts HEAD.
     *
     * @throws RouteNotFoundException When no route matches, or the path is not valid UTF-8 or contains a control character.
     * @throws MethodNotAllowedException When the path matches but no route accepts the method.
     * @throws RouteSignatureException When a route constraint is not a valid regular expression.
     */
    public function match(string $method, string $path): RouteMatch
    {
        $normalizedPath = $this->normalizePath($path);
        $refusal = self::pathRefusalReason($path, $normalizedPath);

        if ($refusal !== null) {
            throw RouteNotFoundException::refusedPath($method, $refusal);
        }

        $allowedMethods = [];

        foreach ($this->routesInMatchOrder() as $route) {
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
     * Matches one route against a normalized path, or returns null.
     *
     * Literal segments are compared raw, never decoded, so a guard reading Request::path()
     * sees the segments that dispatch. The path must not be decoded before splitting, or
     * "/p%2Fq" would collide with "/p/q".
     * Parameter values are decoded before their constraint is checked, or "%2E%2E" would
     * pass a constraint that forbids dots.
     *
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
                $value = rawurldecode($candidate);

                if (!self::isWellFormedValue($value)) {
                    return null;
                }

                $pattern = $route->constraints[$name] ?? '[^/]+';
                $regex = $this->compileConstraintRegex($route, $name, $pattern);

                $matched = @preg_match($regex, $value);
                if ($matched !== 1) {
                    return null;
                }

                $parameters[$name] = $value;
                continue;
            }

            if ($segment !== $candidate) {
                return null;
            }
        }

        return $parameters;
    }

    /**
     * Splits a normalized path into raw, still percent-encoded segments.
     *
     * @return array<int, string>
     */
    private function segments(string $path): array
    {
        $normalized = $this->normalizePath($path);

        if ($normalized === '/') {
            return [];
        }

        return explode('/', ltrim($normalized, '/'));
    }

    /**
     * Rejects a value that is not valid UTF-8 or contains a control character (C0 or DEL).
     *
     * No route constraint can admit such a value: invalid UTF-8 or NUL bound through PDO emulated
     * prepares can segfault the worker on pdo_pgsql. Uses /u rather than mb_check_encoding(),
     * so ext-mbstring is not required.
     */
    private static function isWellFormedValue(string $value): bool
    {
        if (preg_match(self::CONTROL_CHARACTER_PATTERN, $value) === 1) {
            return false;
        }

        return @preg_match('//u', $value) === 1;
    }

    /**
     * The RouteNotFoundException reason for a refused path, or null when it may be matched.
     *
     * The raw path is checked before "?" or "#", and before normalizePath(), because parse_url() rewrites
     * control bytes to "_" rather than failing.
     */
    private static function pathRefusalReason(string $rawPath, string $normalizedPath): ?string
    {
        $rawPath = substr($rawPath, 0, strcspn($rawPath, '?#'));

        if (preg_match(self::CONTROL_CHARACTER_PATTERN, $rawPath) === 1) {
            return RouteNotFoundException::REASON_CONTROL_CHARACTER;
        }

        if (!self::isWellFormedValue($rawPath) || !self::isWellFormedValue($normalizedPath)) {
            return RouteNotFoundException::REASON_INVALID_UTF8;
        }

        return null;
    }

    /**
     * The routes in the order they are tried: static routes first, then parameterised ones.
     *
     * @return list<Route>
     */
    private function routesInMatchOrder(): array
    {
        $static = [];
        $parameterized = [];

        foreach ($this->routes as $route) {
            if ($this->isStaticRoute($route)) {
                $static[] = $route;
            } else {
                $parameterized[] = $route;
            }
        }

        return [...$static, ...$parameterized];
    }

    private function isStaticRoute(Route $route): bool
    {
        foreach (explode('/', $route->path) as $segment) {
            if ($this->isParameterSegment($segment)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reduces a request path to the form routes are matched against.
     *
     * A leading run of slashes is collapsed first, because parse_url() reads "//host/x" as
     * an authority. A malformed target, or one without a path, resolves to "/".
     */
    private function normalizePath(string $path): string
    {
        if (str_starts_with($path, '//')) {
            $path = '/' . ltrim($path, '/');
        }

        $parsed = parse_url($path, PHP_URL_PATH);
        $parsedPath = is_string($parsed) && $parsed !== '' ? $parsed : '/';

        if ($this->trailingSlashTolerant) {
            $normalized = '/' . trim($parsedPath, '/');

            return $normalized === '/' ? '/' : $normalized;
        }

        // Strict mode keeps the trailing slash, so /users/ and /users differ.
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
        // The D modifier is required: without it "$" accepts a trailing newline, so "123\n" satisfies "\d+".
        $regex = '~^(?:' . str_replace('~', '\\~', $pattern) . ')$~D';

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
