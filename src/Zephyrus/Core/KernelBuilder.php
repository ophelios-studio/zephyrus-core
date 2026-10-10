<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use Zephyrus\Container\ContainerInterface;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Event\EventDispatcher;
use Zephyrus\Http\Error\HttpExceptionResponder;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\MiddlewarePipeline;
use Zephyrus\Rendering\RenderEngine;
use Zephyrus\Rendering\RenderResponses;
use Zephyrus\Routing\Exception\RouteMiddlewareException;
use Zephyrus\Routing\HandlerResolver;
use Zephyrus\Routing\RouteDispatcher;
use Zephyrus\Routing\Router;
use Zephyrus\Security\ContentSecurityPolicyMiddleware;
use Zephyrus\Security\SecureHeadersMiddleware;

/**
 * Fluent builder assembling a ready-to-use HttpKernel from high-level
 * configuration, without wiring the internal pipeline by hand.
 *
 * Global middlewares wrap error responses as well as successful ones, so a
 * 404, a 405 and a 500 carry the same decorations as a 200. Route middlewares
 * only run for a route that matched.
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

    private ?RenderEngine $renderEngine = null;

    /** @var array<class-string<\Throwable>, callable(\Throwable, \Zephyrus\Http\Request): \Zephyrus\Http\Response> */
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
     * Returns the router set by withRouter(), or null when none was set.
     */
    public function router(): ?Router
    {
        return $this->router;
    }

    /**
     * Appends a global middleware. Middlewares run in registration order.
     */
    public function withMiddleware(MiddlewareInterface $middleware): self
    {
        $clone = clone $this;
        $clone->globalMiddlewares[] = $middleware;

        return $clone;
    }

    /**
     * Registers a middleware under a name that routes reference in their
     * `middlewares` list. Registering the same name twice replaces the binding.
     */
    public function registerMiddleware(string $name, MiddlewareInterface $middleware): self
    {
        $clone = clone $this;
        $clone->namedRouteMiddlewares[$name] = $middleware;

        return $clone;
    }

    /**
     * Attaches the EventDispatcher receiving RequestEvent, ExceptionEvent and
     * ResponseEvent. Without one, the kernel fires no events.
     */
    public function withEventDispatcher(EventDispatcher $dispatcher): self
    {
        $clone = clone $this;
        $clone->eventDispatcher = $dispatcher;

        return $clone;
    }

    /**
     * Overrides the default controller factory (`new $class()`), typically with a DI container resolver.
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
     * Injects the engine into every controller using RenderResponses, including
     * through a parent class or another trait. Replaces an engine the factory
     * set, whatever the order of calls with withControllerFactory().
     */
    public function withRenderEngine(RenderEngine $engine): self
    {
        $clone = clone $this;
        $clone->renderEngine = $engine;

        return $clone;
    }

    /**
     * Shorthand for withControllerFactory(): whichever of the two is called last wins.
     *
     * Resolves controllers through ContainerInterface::get(), so auto-wiring and
     * explicit bindings both apply. A singleton binding reuses its controller across requests.
     */
    public function withContainer(ContainerInterface $container): self
    {
        return $this->withControllerFactory(static fn (string $class): object => $container->get($class));
    }

    /**
     * Registers a handler for an exception class, checked before the built-in
     * mappings (404, 405, 422, 500). The most specific matching class wins, by
     * instanceof. The handler receives the request without route middleware
     * attributes (see HttpKernel).
     *
     * @param class-string<\Throwable> $exceptionClass
     * @param callable(\Throwable, \Zephyrus\Http\Request): \Zephyrus\Http\Response $handler
     */
    public function withExceptionHandler(string $exceptionClass, callable $handler): self
    {
        $clone = clone $this;
        $clone->exceptionHandlers[$exceptionClass] = $handler;

        return $clone;
    }

    /**
     * Whether a middleware of the given class is registered, globally or under a route name.
     * Matching is by instanceof: a decorator wrapping a framework middleware is not seen
     * (see ApplicationBuilder::withAcknowledgedSecurityKeys()).
     *
     * @param class-string $class
     */
    public function hasMiddleware(string $class): bool
    {
        if ($this->hasGlobalMiddleware($class)) {
            return true;
        }

        foreach ($this->namedRouteMiddlewares as $middleware) {
            if ($middleware instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the middlewares registered under a route name, keyed by that name.
     *
     * @return array<string, MiddlewareInterface>
     */
    public function namedMiddlewares(): array
    {
        return $this->namedRouteMiddlewares;
    }

    /**
     * Whether a middleware of the given class is registered globally. Unlike
     * hasMiddleware(), a route-named middleware does not count.
     *
     * @param class-string $class
     */
    public function hasGlobalMiddleware(string $class): bool
    {
        foreach ($this->globalMiddlewares as $middleware) {
            if ($middleware instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Assembles a fully wired HttpKernel. The builder is unchanged and can build again.
     *
     * @throws ConfigurationException When an enforced ContentSecurityPolicyMiddleware is registered
     *   before a SecureHeadersMiddleware that sets a csp. The check covers GLOBAL middlewares only.
     */
    public function build(): HttpKernel
    {
        $this->assertNoShadowedContentSecurityPolicy();

        $router = $this->router ?? new Router();
        $namedMiddlewares = $this->namedRouteMiddlewares;

        $globalPipeline = new MiddlewarePipeline($this->globalMiddlewares);

        $resolver = new HandlerResolver($this->renderEngineAwareFactory());

        $dispatcher = new RouteDispatcher(
            routes: $router->routes(),
            // Global middlewares run in HttpKernel, not here, so they also wrap error responses.
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

    /**
     * Returns the controller factory, wrapped to inject the render engine into RenderResponses controllers.
     *
     * @return (callable(class-string): object)|null
     */
    private function renderEngineAwareFactory(): ?callable
    {
        $engine = $this->renderEngine;

        if ($engine === null) {
            return $this->controllerFactory;
        }

        $factory = $this->controllerFactory ?? static fn (string $class): object => new $class();

        return static function (string $class) use ($factory, $engine): object {
            $controller = $factory($class);

            if (self::usesRenderResponses($controller) && method_exists($controller, 'setRenderEngine')) {
                $controller->setRenderEngine($engine);
            }

            return $controller;
        };
    }

    /**
     * Whether the object's class or a parent uses RenderResponses, directly or through another trait.
     */
    private static function usesRenderResponses(object $controller): bool
    {
        for ($class = $controller::class; $class !== false; $class = get_parent_class($class)) {
            if (self::traitsUseRenderResponses(class_uses($class) ?: [])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $traits
     */
    private static function traitsUseRenderResponses(array $traits): bool
    {
        if (isset($traits[RenderResponses::class])) {
            return true;
        }

        foreach ($traits as $trait) {
            if (self::traitsUseRenderResponses(class_uses($trait) ?: [])) {
                return true;
            }
        }

        return false;
    }

    /**
     * An enforced policy registered before a csp-setting SecureHeadersMiddleware
     * would never be sent: the inner csp is already on the response when the outer one runs.
     *
     * @throws ConfigurationException
     */
    private function assertNoShadowedContentSecurityPolicy(): void
    {
        $enforcedPolicyOutside = false;

        foreach ($this->globalMiddlewares as $middleware) {
            if ($middleware instanceof ContentSecurityPolicyMiddleware && $middleware->sendsEnforcedPolicy()) {
                $enforcedPolicyOutside = true;

                continue;
            }

            if ($enforcedPolicyOutside && $middleware instanceof SecureHeadersMiddleware && $middleware->hasContentSecurityPolicy()) {
                throw ConfigurationException::shadowedContentSecurityPolicy();
            }
        }
    }
}
