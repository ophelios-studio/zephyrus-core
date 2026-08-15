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
use Zephyrus\Routing\Exception\RouteSignatureException;

/**
 * Resolves a request to a route and runs that route's middlewares and handler.
 *
 * Routing failures leave this class as exceptions. Turning them into HTTP
 * responses is HttpKernel's job, because that conversion has to happen inside
 * the global middleware pipeline for the error response to carry the global
 * security headers.
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
     * @param MiddlewarePipeline $pipeline Base pipeline the matched route's own
     *   middlewares are appended to. KernelBuilder passes an empty one, since
     *   the global middlewares are run by HttpKernel instead.
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
     * Resolves the route for the given request.
     *
     * Resolves against the in-memory RouteCollection and either returns a
     * RouteMatch or throws. HttpKernel calls this before entering the global
     * middleware pipeline so the route parameters are already on the request
     * when the global middlewares see it, and it defers the throw so that a
     * 404 or a 405 is still rendered inside that pipeline.
     *
     * @throws RouteNotFoundException When no route matches the path.
     * @throws MethodNotAllowedException When the path matches but the method does not.
     * @throws RouteSignatureException When a route carries a malformed constraint pattern.
     */
    public function match(Request $request): RouteMatch
    {
        return $this->routes->match($request->method, $request->path());
    }

    /**
     * Runs an already-resolved route: its own middlewares, then its handler.
     *
     * The request is expected to already carry the route parameters as
     * attributes (see match() and HttpKernel::handle()).
     *
     * Route middlewares are appended to this dispatcher's base pipeline. When
     * the dispatcher is wired by KernelBuilder that base pipeline is EMPTY: the
     * global middlewares are run by HttpKernel one layer further out so that
     * they wrap error responses too. Execution order is unchanged either way,
     * global middlewares first, then route middlewares, then the handler.
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
