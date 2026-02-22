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

    public function routes(): RouteCollection
    {
        return $this->routes;
    }
}
