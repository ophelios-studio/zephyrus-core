<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use Zephyrus\Container\ContainerInterface;
use Zephyrus\Event\EventDispatcher;
use Zephyrus\Http\Error\HttpExceptionResponder;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\MiddlewarePipeline;
use Zephyrus\Routing\Exception\RouteMiddlewareException;
use Zephyrus\Routing\HandlerResolver;
use Zephyrus\Routing\RouteDispatcher;
use Zephyrus\Routing\Router;

/**
 * Fluent builder that assembles a ready-to-use HttpKernel from high-level
 * configuration without requiring callers to wire the internal pipeline by hand.
 *
 * ## Typical usage
 *
 * ```php
 * $kernel = KernelBuilder::create()
 *     ->withRouter(
 *         (new Router())
 *             ->get('/health', 'HealthController@show')
 *             ->controller(UserController::class)
 *     )
 *     ->withMiddleware(new CorsMiddleware())
 *     ->registerMiddleware('auth', new AuthMiddleware($guard))
 *     ->withControllerFactory(fn (string $class) => $container->get($class))
 *     ->build();
 *
 * $response = $kernel->handle($request);
 * ```
 *
 * ## What the builder wires
 *
 * ```
 * Request
 *   └─▶ HttpKernel::handle()
 *         ├─▶ RouteDispatcher::match()             (from Router, failure deferred)
 *         ├─▶ MiddlewarePipeline::handle()         (GLOBAL middlewares, wrap everything)
 *         │     ├─▶ RouteDispatcher::dispatchMatch()
 *         │     │     ├─▶ per-route middlewares    (via registerMiddleware registry)
 *         │     │     └─▶ HandlerResolver::resolve() (ClassName@method → Response)
 *         │     │           └─▶ Controller method  (Request / scalar injection)
 *         │     └─▶ HttpExceptionResponder         (on any Throwable, INSIDE the pipeline)
 *         └─▶ HttpExceptionResponder               (backstop: a global middleware threw)
 * ```
 *
 * Global middlewares wrap error responses as well as successful ones, so a
 * 404, a 405 and a 500 all carry the security headers a 200 carries. Route
 * middlewares only run for a route that actually matched.
 */
final class KernelBuilder
{
    private ?Router $router = null;

    /** @var list<MiddlewareInterface> */
    private array $globalMiddlewares = [];

    /** @var array<string, MiddlewareInterface> */
    private array $namedRouteMiddlewares = [];

    /** @var callable(class-string): object|null */
    private mixed $controllerFactory = null;

    private ?EventDispatcher $eventDispatcher = null;

    /** @var array<class-string<\Throwable>, callable(\Throwable, ?\Zephyrus\Http\Request): \Zephyrus\Http\Response> */
    private array $exceptionHandlers = [];

    public static function create(): self
    {
        return new self();
    }

    /**
     * Sets the router whose registered routes will be used for dispatch.
     * When omitted, an empty router is used (every request will 404).
     */
    public function withRouter(Router $router): self
    {
        $clone = clone $this;
        $clone->router = $router;

        return $clone;
    }

    /**
     * Appends a global middleware that wraps every request before route
     * matching middleware and the handler are executed.
     *
     * Multiple calls append in registration order.
     */
    public function withMiddleware(MiddlewareInterface $middleware): self
    {
        $clone = clone $this;
        $clone->globalMiddlewares[] = $middleware;

        return $clone;
    }

    /**
     * Registers a named middleware that route definitions may reference.
     *
     * Routes declare middleware names via their `middlewares` list
     * (e.g. `Router::get('/admin', '...', middlewares: ['auth'])`).
     * Those names are resolved here at dispatch time.
     *
     * Registering the same name twice replaces the previous binding.
     */
    public function registerMiddleware(string $name, MiddlewareInterface $middleware): self
    {
        $clone = clone $this;
        $clone->namedRouteMiddlewares[$name] = $middleware;

        return $clone;
    }

    /**
     * Attaches an EventDispatcher so the kernel fires RequestEvent and
     * ResponseEvent on every handled request.
     *
     * When omitted (default) the kernel operates without event hooks, which
     * preserves the behaviour of previous versions.
     */
    public function withEventDispatcher(EventDispatcher $dispatcher): self
    {
        $clone = clone $this;
        $clone->eventDispatcher = $dispatcher;

        return $clone;
    }

    /**
     * Overrides the default controller factory (`new $class()`) with a custom
     * callable — typically a DI container resolver.
     *
     * @param callable(class-string): object $factory
     */
    public function withControllerFactory(callable $factory): self
    {
        $clone = clone $this;
        $clone->controllerFactory = $factory;

        return $clone;
    }

    /**
     * Wires a DI container as the controller factory.
     *
     * This is a convenience wrapper around withControllerFactory() for the
     * common case of resolving controllers from a ContainerInterface (e.g.
     * the built-in Container with auto-wiring).
     *
     * ```php
     * $container = new Container();
     * $container->singleton(UserRepository::class, fn ($c) => new UserRepository($c->get(Database::class)));
     *
     * $kernel = KernelBuilder::create()
     *     ->withRouter($router)
     *     ->withContainer($container)
     *     ->build();
     * ```
     *
     * Controllers are resolved via ContainerInterface::get(), so auto-wiring
     * and explicit bindings both work.  For singleton controllers the same
     * instance is reused across requests.
     */
    public function withContainer(ContainerInterface $container): self
    {
        return $this->withControllerFactory(static fn (string $class): object => $container->get($class));
    }

    /**
     * Register a custom exception handler for a specific exception class.
     *
     * When the kernel catches an exception, registered handlers are checked
     * before the built-in mappings (404, 405, 422, 500). The most-specific
     * matching class wins via instanceof.
     *
     * @param class-string<\Throwable> $exceptionClass
     * @param callable(\Throwable, ?\Zephyrus\Http\Request): \Zephyrus\Http\Response $handler
     */
    public function withExceptionHandler(string $exceptionClass, callable $handler): self
    {
        $clone = clone $this;
        $clone->exceptionHandlers[$exceptionClass] = $handler;

        return $clone;
    }

    /**
     * Whether a middleware of the given class is already registered, globally
     * or under a route-middleware name.
     *
     * This exists so that a caller can ask "is this protection wired?" WITHOUT
     * the builder ever wiring one itself. ApplicationBuilder uses it to refuse
     * a boot whose security configuration nothing consumes; a consumer can use
     * it to avoid registering a second copy of something it already registers.
     *
     * Matching is by instanceof. The framework's own security middlewares are
     * final, so in practice that means the exact class: a consumer that WRAPS
     * one in a delegating decorator is invisible here, which is a real limit
     * and the reason an explicit escape hatch exists. See
     * ApplicationBuilder::withAcknowledgedSecurityKeys().
     *
     * @param class-string $class
     */
    public function hasMiddleware(string $class): bool
    {
        foreach ($this->globalMiddlewares as $middleware) {
            if ($middleware instanceof $class) {
                return true;
            }
        }

        foreach ($this->namedRouteMiddlewares as $middleware) {
            if ($middleware instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Assembles and returns a fully wired HttpKernel.
     *
     * The builder itself is unchanged after this call and may be reused to
     * produce additional kernels (e.g. in tests).
     */
    public function build(): HttpKernel
    {
        $router = $this->router ?? new Router();
        $namedMiddlewares = $this->namedRouteMiddlewares;

        $globalPipeline = new MiddlewarePipeline($this->globalMiddlewares);

        $resolver = new HandlerResolver($this->controllerFactory);

        $dispatcher = new RouteDispatcher(
            routes: $router->routes(),
            // The global middlewares are deliberately NOT handed to the
            // dispatcher: HttpKernel runs them one layer further out so they
            // also wrap the error responder. The dispatcher only adds the
            // matched route's own middlewares, which keeps the execution order
            // identical (global first, then route, then handler).
            pipeline: new MiddlewarePipeline(),
            resolver: $resolver->resolve(...),
            routeMiddlewareResolver: $namedMiddlewares !== []
                ? static function (string $name) use ($namedMiddlewares): MiddlewareInterface {
                    return $namedMiddlewares[$name]
                        ?? throw RouteMiddlewareException::unknownMiddleware($name);
                }
                : null,
        );

        $responder = new HttpExceptionResponder();
        foreach ($this->exceptionHandlers as $class => $handler) {
            $responder->registerHandler($class, $handler);
        }

        return new HttpKernel($dispatcher, $responder, $this->eventDispatcher, $globalPipeline);
    }
}
