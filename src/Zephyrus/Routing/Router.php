<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

final class Router
{
    private RouteCollection $routes;

    public function __construct(?RouteCollection $routes = null)
    {
        $this->routes = $routes ?? new RouteCollection();
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
    ): self {
        return new self(
            $this->routes->withRoute(
                Route::define($method, $path, $handler, $constraints, $middlewares),
            ),
        );
    }

    public function name(string $routeName): self
    {
        return new self($this->routes->withLastRouteName($routeName));
    }

    /**
     * @param callable(self): self $registrar
     * @param array<int, string> $middlewares
     */
    public function group(string $prefix, callable $registrar, array $middlewares = []): self
    {
        $scoped = new self();
        $scopedResult = $registrar($scoped);

        $router = $this;

        foreach ($scopedResult->routes()->all() as $route) {
            $router = $router->add(
                method: $route->method,
                path: $this->joinPath($prefix, $route->path),
                handler: $route->handler,
                constraints: $route->constraints,
                middlewares: array_values(array_unique([...$middlewares, ...$route->middlewares])),
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

    public function routes(): RouteCollection
    {
        return $this->routes;
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
}
