<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use Zephyrus\Routing\Exception\RouteMiddlewareException;

final class Router
{
    private RouteCollection $routes;
    private RouteAttributeReader $attributeReader;

    /** @var array<string, array<int, string>> */
    private array $middlewareGroups;

    /**
     * @param array<string, array<int, string>> $middlewareGroups
     */
    public function __construct(
        ?RouteCollection $routes = null,
        ?RouteAttributeReader $reader = null,
        array $middlewareGroups = [],
    ) {
        $this->routes = $routes ?? new RouteCollection();
        $this->attributeReader = $reader ?? new RouteAttributeReader();
        $this->middlewareGroups = $middlewareGroups;
    }

    /**
     * Registers a reusable middleware group alias.
     *
     * Group entries may include both concrete middleware names and other group
     * names; group expansion happens when routes are registered.
     *
     * @param array<int, string> $middlewares
     */
    public function middlewareGroup(string $name, array $middlewares): self
    {
        $clone = clone $this;
        $clone->middlewareGroups[$name] = array_values($middlewares);

        return $clone;
    }

    /**
     * @param array<string, string> $constraints
     * @param array<int, string> $middlewares
     */
    public function add(
        string $method,
        string $path,
        string $handler,
        array $constraints = [],
        array $middlewares = [],
        ?string $name = null,
    ): self {
        return new self(
            $this->routes->withRoute(
                Route::define($method, $path, $handler, $constraints, $this->expandMiddlewares($middlewares), $name),
            ),
            $this->attributeReader,
            $this->middlewareGroups,
        );
    }

    public function name(string $routeName): self
    {
        return new self($this->routes->withLastRouteName($routeName), $this->attributeReader, $this->middlewareGroups);
    }

    /**
     * Groups routes under a common URL prefix, shared middlewares, and an
     * optional name prefix.
     *
     * Route names defined inside the group (via ->name() or #[Route(name:)])
     * are preserved and, when $namePrefix is supplied, are prepended with that
     * prefix so the caller can build a tidy name hierarchy:
     *
     *   $router->group('/api/v1', fn($r) => $r
     *       ->get('/users', 'UserController@index')->name('users.index'),
     *       namePrefix: 'api.',
     *   );
     *   // findable as 'api.users.index'
     *
     * Unnamed routes inside the group stay unnamed even when $namePrefix is set.
     *
     * @param callable(self): self $registrar
     * @param array<int, string> $middlewares
     */
    public function group(
        string $prefix,
        callable $registrar,
        array $middlewares = [],
        ?string $namePrefix = null,
    ): self {
        $scoped = new self(null, $this->attributeReader, $this->middlewareGroups);
        $scopedResult = $registrar($scoped);

        $router = $this;

        foreach ($scopedResult->routes()->all() as $route) {
            $routeName = $route->name;

            if ($namePrefix !== null && $routeName !== null) {
                $routeName = $namePrefix . $routeName;
            }

            $router = $router->add(
                method: $route->method,
                path: $this->joinPath($prefix, $route->path),
                handler: $route->handler,
                constraints: $route->constraints,
                middlewares: array_values(array_unique([...$middlewares, ...$route->middlewares])),
                name: $routeName,
            );
        }

        return $router;
    }

    /**
     * @param array<string, string> $constraints
     * @param array<int, string> $middlewares
     */
    public function get(string $path, string $handler, array $constraints = [], array $middlewares = []): self
    {
        return $this->add('GET', $path, $handler, $constraints, $middlewares);
    }

    /**
     * @param array<string, string> $constraints
     * @param array<int, string> $middlewares
     */
    public function post(string $path, string $handler, array $constraints = [], array $middlewares = []): self
    {
        return $this->add('POST', $path, $handler, $constraints, $middlewares);
    }

    /**
     * @param array<string, string> $constraints
     * @param array<int, string> $middlewares
     */
    public function put(string $path, string $handler, array $constraints = [], array $middlewares = []): self
    {
        return $this->add('PUT', $path, $handler, $constraints, $middlewares);
    }

    /**
     * @param array<string, string> $constraints
     * @param array<int, string> $middlewares
     */
    public function patch(string $path, string $handler, array $constraints = [], array $middlewares = []): self
    {
        return $this->add('PATCH', $path, $handler, $constraints, $middlewares);
    }

    /**
     * @param array<string, string> $constraints
     * @param array<int, string> $middlewares
     */
    public function delete(string $path, string $handler, array $constraints = [], array $middlewares = []): self
    {
        return $this->add('DELETE', $path, $handler, $constraints, $middlewares);
    }

    /**
     * Registers conventional CRUD routes for a resource controller.
     *
     * Generated handlers:
     * - GET    /resource           -> Controller@index
     * - GET    /resource/{id}      -> Controller@show
     * - POST   /resource           -> Controller@store
     * - PUT    /resource/{id}      -> Controller@update
     * - PATCH  /resource/{id}      -> Controller@patch
     * - DELETE /resource/{id}      -> Controller@delete
     *
     * @param array<int, string> $middlewares
     */
    public function resource(string $resourcePath, string $controller, array $middlewares = []): self
    {
        $basePath = '/' . trim($resourcePath, '/');

        return $this
            ->get($basePath, sprintf('%s@index', $controller), middlewares: $middlewares)
            ->get($basePath . '/{id}', sprintf('%s@show', $controller), ['id' => '\\d+'], $middlewares)
            ->post($basePath, sprintf('%s@store', $controller), middlewares: $middlewares)
            ->put($basePath . '/{id}', sprintf('%s@update', $controller), ['id' => '\\d+'], $middlewares)
            ->patch($basePath . '/{id}', sprintf('%s@patch', $controller), ['id' => '\\d+'], $middlewares)
            ->delete($basePath . '/{id}', sprintf('%s@delete', $controller), ['id' => '\\d+'], $middlewares);
    }

    /**
     * Registers all routes discovered via #[Route] attributes on the given
     * controller class. Handler strings are set to `ClassName@methodName`.
     *
     * @param class-string $className
     */
    public function controller(string $className): self
    {
        $discovered = $this->attributeReader->read($className);

        $router = $this;

        foreach ($discovered as $route) {
            $router = new self(
                $router->routes->withRoute(new Route(
                    method: $route->method,
                    path: $route->path,
                    handler: $route->handler,
                    constraints: $route->constraints,
                    middlewares: $router->expandMiddlewares($route->middlewares),
                    name: $route->name,
                )),
                $router->attributeReader,
                $router->middlewareGroups,
            );
        }

        return $router;
    }

    public function routes(): RouteCollection
    {
        return $this->routes;
    }

    public function hasRouteNamed(string $name): bool
    {
        return $this->routes->hasNamed($name);
    }

    /**
     * @return array<int, string>
     */
    public function routeMethods(): array
    {
        return $this->routes->methods();
    }

    /**
     * @return array<int, string>
     */
    public function routePaths(): array
    {
        return $this->routes->paths();
    }

    /**
     * @return array<int, string>
     */
    public function routeNames(): array
    {
        return $this->routes->names();
    }

    /**
     * @return array<int, string>
     */
    public function routeHandlers(): array
    {
        return $this->routes->handlers();
    }

    /**
     * @return array<string, int>
     */
    public function routeMethodHistogram(): array
    {
        return $this->routes->methodHistogram();
    }

    /**
     * @return array<string, int>
     */
    public function routeMiddlewareHistogram(): array
    {
        return $this->routes->middlewareHistogram();
    }

    /**
     * @return array<int, string>
     */
    public function routeUniqueMiddlewares(): array
    {
        return $this->routes->uniqueMiddlewares();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function routePathsByMethod(): array
    {
        return $this->routes->pathsByMethod();
    }

    /**
     * @return array<string, int>
     */
    public function routeParameterHistogram(): array
    {
        return $this->routes->parameterHistogram();
    }

    /**
     * @return array<string, int>
     */
    public function routeConstrainedParameterHistogram(): array
    {
        return $this->routes->constrainedParameterHistogram();
    }

    /**
     * @return array{total: int, named: int, unnamed: int, duplicate_names: int, methods: array<string, int>, middlewares: array<string, int>, middleware_count: int, paths_by_method: array<string, array<int, string>>, parameters: array<string, int>, constrained_parameters: array<string, int>}
     */
    public function routeSummary(): array
    {
        return $this->routes->summary();
    }

    /**
     * @return array<string, Route>
     */
    public function namedRoutes(): array
    {
        return $this->routes->namedRoutes();
    }

    /**
     * @return array<int, Route>
     */
    public function routesByMethod(string $method): array
    {
        return $this->routes->routesByMethod($method);
    }

    /**
     * @return array<int, string>
     */
    public function duplicateRouteNames(): array
    {
        return $this->routes->duplicateRouteNames();
    }

    public function assertNoDuplicateRouteNames(): self
    {
        $this->routes->assertNoDuplicateRouteNames();

        return $this;
    }

    public function count(): int
    {
        return $this->routes->count();
    }

    public function isEmpty(): bool
    {
        return $this->routes->isEmpty();
    }

    private function joinPath(string $prefix, string $path): string
    {
        $left = trim($prefix, '/');
        $right = trim($path, '/');

        if ($left === '' && $right === '') {
            return '/';
        }

        if ($left === '') {
            return '/' . $right;
        }

        if ($right === '') {
            return '/' . $left;
        }

        return '/' . $left . '/' . $right;
    }

    /**
     * @param array<int, string> $middlewares
     * @param array<int, string> $stack
     *
     * @return array<int, string>
     */
    private function expandMiddlewares(array $middlewares, array $stack = []): array
    {
        $expanded = [];

        foreach ($middlewares as $name) {
            if (isset($this->middlewareGroups[$name])) {
                if (in_array($name, $stack, true)) {
                    throw RouteMiddlewareException::circularGroupReference($stack, $name);
                }

                $expanded = [
                    ...$expanded,
                    ...$this->expandMiddlewares($this->middlewareGroups[$name], [...$stack, $name]),
                ];
                continue;
            }

            $expanded[] = $name;
        }

        return array_values(array_unique($expanded));
    }
}
