<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use Closure;
use Throwable;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\MiddlewarePipeline;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Exception\MethodNotAllowedException;
use Zephyrus\Routing\Exception\RouteMiddlewareException;
use Zephyrus\Routing\Exception\RouteNotFoundException;
use Zephyrus\Routing\Exception\RoutePathRefusal;
use Zephyrus\Routing\Exception\RouteSignatureException;

/**
 * Resolves a request to a route and runs that route's middlewares and handler.
 *
 * Routing failures are thrown, not converted: HttpKernel renders them inside the
 * global pipeline so that error responses carry the global security headers.
 */
final readonly class RouteDispatcher
{
    /**
     * @var Closure(RouteMatch, Request): Response
     */
    private Closure $resolver;

    /**
     * @var Closure(string): MiddlewareInterface
     */
    private Closure $routeMiddlewareResolver;

    /**
     * @param MiddlewarePipeline $pipeline Base pipeline the route middlewares are appended to
     *   (KernelBuilder passes an empty one; HttpKernel runs the global middlewares).
     * @param callable(RouteMatch, Request): Response $resolver
     * @param callable(string): MiddlewareInterface|null $routeMiddlewareResolver
     */
    public function __construct(
        private RouteCollection $routes,
        private MiddlewarePipeline $pipeline,
        callable $resolver,
        ?callable $routeMiddlewareResolver = null,
    ) {
        $this->resolver = Closure::fromCallable($resolver);
        $this->routeMiddlewareResolver = $routeMiddlewareResolver !== null
            ? Closure::fromCallable($routeMiddlewareResolver)
            : static fn (string $name): MiddlewareInterface => throw RouteMiddlewareException::unknownMiddleware($name);
    }

    /**
     * Resolves the route for the given request, or throws.
     *
     * HttpKernel calls this before the global pipeline so that route parameters
     * are already on the request, and defers the throw so a 404 or 405 still
     * passes through the global middlewares.
     *
     * @throws RouteNotFoundException When no route matches the path.
     * @throws MethodNotAllowedException When the path matches but the method does not.
     * @throws RouteSignatureException When a route carries a malformed constraint pattern.
     */
    public function match(Request $request): RouteMatch
    {
        if ($request->uri()->pathHasControlCharacter()) {
            throw RouteNotFoundException::pathIsRefused($request->method, RoutePathRefusal::ControlCharacter);
        }

        return $this->routes->match($request->method, $request->path());
    }

    /**
     * Runs an already-resolved route: its middlewares, then its handler.
     *
     * The request must already carry the route parameters as attributes (see match()).
     * Through HttpKernel the full order is: global middlewares, route middlewares, handler.
     *
     * @throws RouteMiddlewareException When a route middleware cannot be resolved.
     */
    public function dispatchMatch(RouteMatch $match, Request $request): Response
    {
        $routeMiddlewares = $this->resolveRouteMiddlewares($match->route->middlewares);
        $pipeline = $this->pipeline->pipeMany($routeMiddlewares);

        return $pipeline->handle(
            $request,
            fn (Request $request): Response => ($this->resolver)($match, $request),
        );
    }

    /**
     * @param array<int, string> $middlewareNames
     * @return array<int, MiddlewareInterface>
     */
    private function resolveRouteMiddlewares(array $middlewareNames): array
    {
        $resolved = [];

        foreach (array_values(array_unique($middlewareNames)) as $name) {
            try {
                $resolved[] = ($this->routeMiddlewareResolver)($name);
            } catch (Throwable $e) {
                if ($e instanceof RouteMiddlewareException) {
                    throw $e;
                }

                throw RouteMiddlewareException::resolutionFailed($name, $e);
            }
        }

        return $resolved;
    }
}
