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

        if (!self::isWellFormedValue($normalizedPath)) {
            return [];
        }

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

        // A malformed target is refused BEFORE any route is consulted. See
        // isWellFormedValue() for why an invalid byte is a process-kill rather
        // than an error path.
        if (!self::isWellFormedValue($normalizedPath)) {
            throw new RouteNotFoundException(sprintf(
                'No route matched %s: the request path is not valid UTF-8 or contains a NUL byte',
                strtoupper($method),
            ));
        }

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
     * Match one route against one already-normalized request path.
     *
     * ## Literal segments are compared RAW, parameter segments are decoded
     *
     * Every segment used to be rawurldecode()d before the comparison, literal
     * segments included, so "/%61dmin/secret" matched the route
     * "/admin/secret". Request::path() reports the raw target, which is what a
     * guard, an allowlist, a CSRF exclusion pattern or an audit record reads, so
     * the request executed one route while every path-based check inspected
     * another. Measured through the real kernel with the guard the docblock on
     * Request::path() itself recommended:
     *
     *   /admin/secret            path() '/admin/secret'      -> 401 blocked
     *   /%61dmin/secret          path() '/%61dmin/secret'    -> 200 SECRET
     *   /%61%64%6d%69%6e/secret                              -> 200 SECRET
     *
     * nginx with the documented try_files rewrite leaves REQUEST_URI
     * percent-encoded, so this reproduced in a real deployment.
     *
     * A literal segment is now compared byte for byte against the raw request
     * segment, which makes Request::path() truthful about the literal part of
     * the route that dispatches: that is exactly the part a prefix guard or an
     * anchored exclusion pattern keys on.
     *
     * Decoding the PATH into one canonical string was rejected as the fix. It
     * cannot be done without loss: splitting happens before decoding, so
     * "/p%2Fq" is ONE segment whose value is "p/q", and a decoded path string
     * would render it "/p/q", colliding with the genuinely two-segment request.
     * Manufacturing that collision inside the router is a worse hazard than the
     * one being closed.
     *
     * A PARAMETER segment is still decoded before its constraint is applied,
     * which is the order that keeps the constraint meaningful: checking the raw
     * segment and decoding afterwards would let "%2E%2E" satisfy a constraint
     * that forbids a dot and then hand ".." to the handler.
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
     * Split a path into its raw, still percent-encoded segments.
     *
     * Decoding used to happen here, for every segment. It now happens in
     * extractParameters(), for parameter segments only; see that method.
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
     * Reject a value that is not valid UTF-8 or that carries a NUL byte.
     *
     * SEVERITY IS SET BY A CONSUMER FACT, not by tidiness. The default
     * parameter pattern "[^/]+" is byte-oriented, so "/a/%FF", "/a/%C3%28",
     * "/a/%ED%A0%80" (a surrogate) and "/a/%00" all used to reach a handler
     * argument intact. With PDO emulated prepares on PHP 8.4, binding invalid
     * UTF-8 through pdo_pgsql SEGFAULTS the worker rather than raising, and a
     * consumer measured seven anonymous GET routes being killed that way in
     * production. This is a remote process kill, not an error path.
     *
     * Refusing is breaking toward safety: a route that genuinely needs raw
     * bytes declares its own constraint pattern and takes them percent-encoded,
     * or accepts them in the body rather than the path. Route::SAFE_SLUG is the
     * ready-made pattern for the common case.
     *
     * preg_match with the /u modifier is the validity check rather than
     * mb_check_encoding, so the guard does not depend on ext-mbstring being
     * installed. NUL is valid UTF-8, so it needs its own test.
     */
    private static function isWellFormedValue(string $value): bool
    {
        if (str_contains($value, "\0")) {
            return false;
        }

        return @preg_match('//u', $value) === 1;
    }

    /**
     * Reduce a request path to the form routes are matched against.
     *
     * Two hazards are handled explicitly here, both of which used to be silent.
     *
     * A leading run of slashes is collapsed FIRST. On a bare path string, unlike
     * on a full URL, parse_url() reads a leading "//token" as an authority and
     * returns only what follows, so "//x/admin/secret" became "/admin/secret"
     * and dispatched a route that no path-based guard had inspected. Request
     * canonicalises its target for the same reason, and this is the second half
     * of the same guarantee: the router cannot be desynced even when called
     * directly with a raw string.
     *
     * parse_url() then returns false for a malformed target and null when there
     * is no path component. Casting either to a string turned both into "" and
     * quietly routed them to "/". They resolve to the root deliberately now,
     * rather than by accident.
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
        // The D modifier is not optional. Without it PCRE lets "$" match just
        // before a trailing newline, so every author-written whitelist silently
        // accepted one: "/s/123%0A" satisfied a "\d+" constraint and the
        // handler received the newline intact in its string argument.
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
