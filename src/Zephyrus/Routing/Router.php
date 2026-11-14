<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use Zephyrus\Core\Cache\BootCache;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Routing\Exception\DuplicateRouteNameException;
use Zephyrus\Routing\Exception\RouteAttributeException;
use Zephyrus\Routing\Exception\RouteMiddlewareException;
use Zephyrus\Routing\Exception\RouteSignatureException;

final class Router
{
    private RouteCollection $routes;
    private RouteAttributeReader $attributeReader;

    /** @var array<string, array<int, string>> */
    private array $middlewareGroups;

    private bool $builtFromConfiguration = false;

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
     * The router of the router: section. In production and staging, its routes are read from the cache written by
     * vendor/bin/zephyrus cache:warm while that cache is usable; otherwise, and always in development and testing,
     * the controllers are discovered, with one error_log line in production and staging. Either way, a route
     * whose #[RequiresEnv] the environment does not meet is left out, on every call.
     *
     * Discovery refuses two routes of one name that can be registered together, a #[MiddlewareGroup] that
     * router.middleware_groups does not declare, and a controller directory that is missing or holds no class.
     *
     * @param string $basePath Absolute path of the application root: relative router: paths are resolved against
     *                         it, and the routes cache is checked against its config.yml.
     * @throws \InvalidArgumentException When basePath is not an absolute path.
     * @throws ConfigurationException When the configuration has no router: section, a controller directory is
     *                                missing or holds no class of its namespace, or the cache leaves basePath.
     * @throws RouteAttributeException When a class cannot be reflected, a #[RequiresEnv] names a variable that is
     *                                 never read, or two routes of one name can be registered together.
     * @throws RouteSignatureException When a route path is malformed.
     * @throws RouteMiddlewareException When a #[MiddlewareGroup] is not declared, groups reference each other in a
     *                                  cycle, or a #[WithoutMiddleware] names a class it may not skip.
     */
    public static function fromConfiguration(Configuration $configuration, string $basePath): self
    {
        $config = $configuration->router
            ?? throw ConfigurationException::missingRequired(RouterConfig::SECTION, 'controllers');

        if (!str_starts_with($basePath, '/')) {
            throw new \InvalidArgumentException(sprintf(
                'Router::fromConfiguration() needs the absolute path of the application root as basePath, not "%s".',
                $basePath,
            ));
        }

        $basePath = realpath($basePath) ?: rtrim($basePath, '/');
        $cache = new RouteCache($config, $basePath, $basePath . '/' . RouteCache::CONFIGURATION_FILE);

        $routes = $configuration->application->environment->isProductionLike() ? self::cachedRoutes($cache) : null;
        $routes ??= RouteCollection::fromRoutes(array_values(array_map(
            static fn (DiscoveredRoute $discovered): Route => $discovered->route,
            array_filter(
                (new RouteDiscovery($config, $basePath))->routes(),
                static fn (DiscoveredRoute $discovered): bool => RouteAttributeReader::requirementsMet(
                    $discovered->requirements,
                ),
            ),
        )));

        $router = new self($routes, null, $config->middlewareGroups);
        $router->builtFromConfiguration = true;

        return $router;
    }

    /**
     * Whether Router::fromConfiguration() built this router or the router it was derived from.
     *
     * @internal Read by ApplicationBuilder.
     */
    public function isBuiltFromConfiguration(): bool
    {
        return $this->builtFromConfiguration;
    }

    private static function cachedRoutes(RouteCache $cache): ?RouteCollection
    {
        if (!$cache->has()) {
            error_log(sprintf(RouteCache::MISSING_LOG_LINE, $cache->path(), BootCache::WARM_COMMAND));

            return null;
        }

        return $cache->load();
    }

    /**
     * Returns a copy with trailing-slash tolerance set; with false, `/users` and `/users/` are distinct.
     */
    public function withTrailingSlashTolerance(bool $tolerant = true): self
    {
        $routes = new RouteCollection($tolerant);

        foreach ($this->routes->all() as $route) {
            $routes->add($route);
        }

        return $this->derive($routes);
    }

    /**
     * Convenience shortcut for strict trailing-slash matching.
     */
    public function strictTrailingSlashes(): self
    {
        return $this->withTrailingSlashTolerance(false);
    }

    /**
     * Registers a middleware group alias, expanded by routes added after it.
     *
     * @param array<int, string> $middlewares Middleware names or other group names.
     */
    public function middlewareGroup(string $name, array $middlewares): self
    {
        $clone = clone $this;
        $clone->middlewareGroups[$name] = array_values($middlewares);

        return $clone;
    }

    /**
     * Registers a route; group names in $middlewares are expanded at this point.
     * Routes without a parameter are tried first, parameterised ones in registration order.
     *
     * @param array<string, string> $constraints
     * @param array<int, string> $middlewares
     *
     * @throws RouteSignatureException When a path placeholder is malformed, duplicated or reserved.
     * @throws RouteMiddlewareException When middleware groups reference each other in a cycle.
     */
    public function add(
        string $method,
        string $path,
        string $handler,
        array $constraints = [],
        array $middlewares = [],
        ?string $name = null,
    ): self {
        return $this->derive($this->routes->withRoute(
            Route::define($method, $path, $handler, $constraints, $this->expandMiddlewares($middlewares), $name),
        ));
    }

    /**
     * Names the most recently added route.
     *
     * @throws \LogicException When no route has been added yet.
     */
    public function name(string $routeName): self
    {
        return $this->derive($this->routes->withLastRouteName($routeName));
    }

    /**
     * Skips the given global middlewares on the most recently added route.
     *
     * Only that last route is affected, even right after controller(), group(), resource() or
     * discoverControllers(): pass excludedMiddlewares to group() or resource() for a batch, and use the
     * attribute at class level for a whole controller. Each class may be a parent class or an interface.
     * Route middlewares are not affected, and a 404 or 405 still runs every global middleware (see the
     * WithoutMiddleware attribute).
     *
     * @param class-string ...$middlewares Global middleware classes or interfaces.
     * @throws \LogicException When no route has been added yet.
     * @throws RouteMiddlewareException When a name is a middleware group, is not a middleware, or would skip a
     *                                  framework security middleware, or when middleware groups reference each
     *                                  other in a cycle.
     */
    public function withoutMiddleware(string ...$middlewares): self
    {
        $routes = $this->routes->all();

        if ($routes !== []) {
            $last = $routes[array_key_last($routes)];
            $this->assertNoMiddlewareGroup(
                array_values($middlewares),
                sprintf('Route "%s %s"', $last->method, $last->path),
            );
        }

        return $this->derive($this->routes->withLastRouteExcludedMiddlewares(array_values($middlewares)));
    }

    /**
     * Registers routes under a URL prefix, with shared middlewares and an optional name prefix.
     *
     * $namePrefix applies only to named routes; unnamed routes stay unnamed:
     *
     *   $router = $router->group('/api/v1', fn($r) => $r
     *       ->get('/users', 'UserController@index')->name('users.index'),
     *       namePrefix: 'api.',
     *   );
     *   // findable as 'api.users.index'
     *
     * @param callable(self): self $registrar
     * @param array<int, string> $middlewares
     * @param array<int, string> $excludedMiddlewares Global middleware classes or interfaces every route skips.
     *
     * @throws RouteSignatureException When a path placeholder is malformed, duplicated or reserved.
     * @throws RouteMiddlewareException When middleware groups reference each other in a cycle, or an excluded
     *                                  name is a middleware group, is not a middleware, or would skip a framework
     *                                  security middleware.
     */
    public function group(
        string $prefix,
        callable $registrar,
        array $middlewares = [],
        ?string $namePrefix = null,
        array $excludedMiddlewares = [],
    ): self {
        $scoped = $this->derive(new RouteCollection());
        $scopedResult = $registrar($scoped);

        $router = $this;

        $scopedRoutes = $scopedResult->routes()->all();
        $excludedMiddlewares = $this->assertSkippable(
            array_values($excludedMiddlewares),
            sprintf('Group "%s"', $this->joinPath($prefix, '')),
        );

        foreach ($scopedRoutes as $route) {
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

            $excluded = [...$route->excludedMiddlewares, ...$excludedMiddlewares];

            if ($excluded !== []) {
                $router = $router->withoutMiddleware(...$excluded);
            }
        }

        return $router;
    }

    /**
     * @param array<int, string> $names
     * @return list<class-string<MiddlewareInterface>>
     *
     * @throws RouteMiddlewareException
     */
    private function assertSkippable(array $names, string $subject): array
    {
        $this->assertNoMiddlewareGroup($names, $subject);

        return Route::skippableMiddlewares($names, $subject);
    }

    /**
     * @param array<int, string> $names
     *
     * @throws RouteMiddlewareException
     */
    private function assertNoMiddlewareGroup(array $names, string $subject): void
    {
        foreach ($names as $name) {
            if (isset($this->middlewareGroups[$name])) {
                throw RouteMiddlewareException::excludedMiddlewareGroup(
                    $subject,
                    $name,
                    array_values(array_filter($this->expandMiddlewares([$name]), Route::isSkippable(...))),
                );
            }
        }
    }

    /**
     * @param array<string, string> $constraints
     * @param array<int, string> $middlewares
     *
     * @throws RouteSignatureException When a path placeholder is malformed, duplicated or reserved.
     * @throws RouteMiddlewareException When middleware groups reference each other in a cycle.
     */
    public function get(string $path, string $handler, array $constraints = [], array $middlewares = []): self
    {
        return $this->add('GET', $path, $handler, $constraints, $middlewares);
    }

    /**
     * @param array<string, string> $constraints
     * @param array<int, string> $middlewares
     *
     * @throws RouteSignatureException When a path placeholder is malformed, duplicated or reserved.
     * @throws RouteMiddlewareException When middleware groups reference each other in a cycle.
     */
    public function head(string $path, string $handler, array $constraints = [], array $middlewares = []): self
    {
        return $this->add('HEAD', $path, $handler, $constraints, $middlewares);
    }

    /**
     * @param array<string, string> $constraints
     * @param array<int, string> $middlewares
     *
     * @throws RouteSignatureException When a path placeholder is malformed, duplicated or reserved.
     * @throws RouteMiddlewareException When middleware groups reference each other in a cycle.
     */
    public function options(string $path, string $handler, array $constraints = [], array $middlewares = []): self
    {
        return $this->add('OPTIONS', $path, $handler, $constraints, $middlewares);
    }

    /**
     * @param array<string, string> $constraints
     * @param array<int, string> $middlewares
     *
     * @throws RouteSignatureException When a path placeholder is malformed, duplicated or reserved.
     * @throws RouteMiddlewareException When middleware groups reference each other in a cycle.
     */
    public function post(string $path, string $handler, array $constraints = [], array $middlewares = []): self
    {
        return $this->add('POST', $path, $handler, $constraints, $middlewares);
    }

    /**
     * @param array<string, string> $constraints
     * @param array<int, string> $middlewares
     *
     * @throws RouteSignatureException When a path placeholder is malformed, duplicated or reserved.
     * @throws RouteMiddlewareException When middleware groups reference each other in a cycle.
     */
    public function put(string $path, string $handler, array $constraints = [], array $middlewares = []): self
    {
        return $this->add('PUT', $path, $handler, $constraints, $middlewares);
    }

    /**
     * @param array<string, string> $constraints
     * @param array<int, string> $middlewares
     *
     * @throws RouteSignatureException When a path placeholder is malformed, duplicated or reserved.
     * @throws RouteMiddlewareException When middleware groups reference each other in a cycle.
     */
    public function patch(string $path, string $handler, array $constraints = [], array $middlewares = []): self
    {
        return $this->add('PATCH', $path, $handler, $constraints, $middlewares);
    }

    /**
     * @param array<string, string> $constraints
     * @param array<int, string> $middlewares
     *
     * @throws RouteSignatureException When a path placeholder is malformed, duplicated or reserved.
     * @throws RouteMiddlewareException When middleware groups reference each other in a cycle.
     */
    public function delete(string $path, string $handler, array $constraints = [], array $middlewares = []): self
    {
        return $this->add('DELETE', $path, $handler, $constraints, $middlewares);
    }

    /**
     * Registers conventional CRUD routes for a resource controller; {id} accepts digits only.
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
     * @param array<int, string> $excludedMiddlewares Global middleware classes or interfaces every route skips.
     *
     * @throws RouteSignatureException When a path placeholder is malformed, duplicated or reserved.
     * @throws RouteMiddlewareException When middleware groups reference each other in a cycle, or an excluded
     *                                  name is a middleware group, is not a middleware, or would skip a framework
     *                                  security middleware.
     */
    public function resource(
        string $resourcePath,
        string $controller,
        array $middlewares = [],
        array $excludedMiddlewares = [],
    ): self {
        $basePath = '/' . trim($resourcePath, '/');
        $this->assertSkippable($excludedMiddlewares, sprintf('Resource "%s"', $this->joinPath($resourcePath, '')));

        return $this->group('', fn (self $resource): self => $resource
            ->get($basePath, sprintf('%s@index', $controller), middlewares: $middlewares)
            ->get($basePath . '/{id}', sprintf('%s@show', $controller), ['id' => '\\d+'], $middlewares)
            ->post($basePath, sprintf('%s@store', $controller), middlewares: $middlewares)
            ->put($basePath . '/{id}', sprintf('%s@update', $controller), ['id' => '\\d+'], $middlewares)
            ->patch($basePath . '/{id}', sprintf('%s@patch', $controller), ['id' => '\\d+'], $middlewares)
            ->delete($basePath . '/{id}', sprintf('%s@delete', $controller), ['id' => '\\d+'], $middlewares),
            excludedMiddlewares: $excludedMiddlewares,
        );
    }

    /**
     * Registers the routes declared with #[Route] attributes on a controller class.
     *
     * Handler strings are set to `ClassName@methodName`. Only public methods declared by the class
     * itself are read; inherited methods are not.
     *
     * @param class-string $className
     *
     * @throws RouteAttributeException When the class cannot be reflected or declares a duplicate route name.
     * @throws RouteSignatureException When a path placeholder is malformed, duplicated or reserved, or a path holds
     *                                  "?" or "#".
     * @throws RouteMiddlewareException When middleware groups reference each other in a cycle, or a
     *                                  #[WithoutMiddleware] names a class it may not skip.
     */
    public function controller(string $className): self
    {
        $discovered = $this->attributeReader->read($className);

        $router = $this;

        foreach ($discovered as $route) {
            $router = $router->derive($router->routes->withRoute(new Route(
                method: $route->method,
                path: $route->path,
                handler: $route->handler,
                constraints: $route->constraints,
                middlewares: $router->expandMiddlewares($route->middlewares),
                name: $route->name,
                excludedMiddlewares: $route->excludedMiddlewares,
            )));
        }

        return $router;
    }

    /**
     * Recursively registers the controllers under a directory that declare route attributes.
     *
     * Class names are derived from the PSR-4 namespace prefix and each file path. Every
     * candidate file is autoloaded. A missing directory returns the router unchanged.
     *
     * @param string $namespace PSR-4 namespace prefix of the directory.
     * @param string $directory Absolute path to the controllers directory.
     * @param class-string|null $parentClass Only register subclasses of this class.
     *
     * @throws RouteAttributeException When a class cannot be reflected or declares a duplicate route name.
     * @throws RouteSignatureException When a path placeholder is malformed, duplicated or reserved.
     * @throws RouteMiddlewareException When middleware groups reference each other in a cycle, or a
     *                                  #[WithoutMiddleware] names a class it may not skip.
     */
    public function discoverControllers(
        string $namespace,
        string $directory,
        ?string $parentClass = null,
    ): self {
        $directory = rtrim($directory, '/\\');
        if (!is_dir($directory)) {
            return $this;
        }

        $classes = RouteDiscovery::classesIn($namespace, $directory);
        $router = $this;

        foreach ($classes as $className) {
            if ($parentClass !== null && !is_subclass_of($className, $parentClass)) {
                continue;
            }

            $router = $router->controller($className);
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
     * @return array<string, int>
     */
    public function routeControllerHistogram(): array
    {
        return $this->routes->controllerHistogram();
    }

    public function staticRouteCount(): int
    {
        return $this->routes->staticRouteCount();
    }

    public function parameterizedRouteCount(): int
    {
        return $this->routes->parameterizedRouteCount();
    }

    public function constrainedRouteCount(): int
    {
        return $this->routes->constrainedRouteCount();
    }

    /**
     * @return array{total: int, named: int, unnamed: int, duplicate_names: int, methods: array<string, int>, middlewares: array<string, int>, middleware_count: int, paths_by_method: array<string, array<int, string>>, parameters: array<string, int>, constrained_parameters: array<string, int>, controllers: array<string, int>, controller_count: int, static_routes: int, parameterized_routes: int, constrained_routes: int}
     */
    public function routeSummary(): array
    {
        return $this->routes->summary();
    }

    /**
     * @return array<string, Route>
     *
     * @throws DuplicateRouteNameException When a route name is used by more than one route.
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
     * @return list<int|string>
     */
    public function duplicateRouteNames(): array
    {
        return $this->routes->duplicateRouteNames();
    }

    /**
     * @throws DuplicateRouteNameException When a route name is used by more than one route.
     */
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

    public function isTrailingSlashTolerant(): bool
    {
        return $this->routes->isTrailingSlashTolerant();
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
     * A copy holding the given routes, keeping the reader, the middleware groups and the configuration mark.
     */
    private function derive(RouteCollection $routes): self
    {
        $router = new self($routes, $this->attributeReader, $this->middlewareGroups);
        $router->builtFromConfiguration = $this->builtFromConfiguration;

        return $router;
    }

    /**
     * @param array<int, string> $middlewares
     *
     * @return array<int, string>
     *
     * @throws RouteMiddlewareException When middleware groups reference each other in a cycle.
     */
    private function expandMiddlewares(array $middlewares): array
    {
        return self::expandMiddlewareGroups($this->middlewareGroups, $middlewares);
    }

    /**
     * Replaces each group name by the middlewares of the group, recursively, keeping the first of duplicates.
     *
     * @internal Read by RouteDiscovery.
     * @param array<string, array<int, string>> $groups
     * @param array<int, string> $middlewares
     * @param array<int, string> $stack
     *
     * @return array<int, string>
     *
     * @throws RouteMiddlewareException When middleware groups reference each other in a cycle.
     */
    public static function expandMiddlewareGroups(array $groups, array $middlewares, array $stack = []): array
    {
        $expanded = [];

        foreach ($middlewares as $name) {
            if (isset($groups[$name])) {
                if (in_array($name, $stack, true)) {
                    throw RouteMiddlewareException::circularGroupReference($stack, $name);
                }

                $expanded = [
                    ...$expanded,
                    ...self::expandMiddlewareGroups($groups, $groups[$name], [...$stack, $name]),
                ];
                continue;
            }

            $expanded[] = $name;
        }

        return array_values(array_unique($expanded));
    }
}
