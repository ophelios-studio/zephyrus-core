<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Core\Config\RouterConfig;
use Zephyrus\Routing\Exception\RouteAttributeException;
use Zephyrus\Routing\Exception\RouteMiddlewareException;
use Zephyrus\Routing\Exception\RouteSignatureException;

/**
 * Discovers every route of the router: section with its #[RequiresEnv] pairs: the list the routes cache holds, and
 * the one Router::fromConfiguration() filters for the current environment.
 *
 * @internal
 */
final readonly class RouteDiscovery
{
    /**
     * @param string $basePath Absolute path of the application root.
     */
    public function __construct(
        private RouterConfig $config,
        private string $basePath,
        private RouteAttributeReader $reader = new RouteAttributeReader(),
    ) {
    }

    /**
     * Every route in discovery order, its middleware groups expanded.
     *
     * @return list<DiscoveredRoute>
     * @throws ConfigurationException When a controller directory is missing or holds no class of its namespace.
     * @throws RouteAttributeException When a class cannot be reflected, a #[RequiresEnv] names a variable that is
     *                                 never read, or two routes of one name can be registered together.
     * @throws RouteMiddlewareException When a #[MiddlewareGroup] is not declared under router.middleware_groups,
     *                                  groups reference each other in a cycle, or a #[WithoutMiddleware] names a
     *                                  class it may not skip.
     * @throws RouteSignatureException When a route path is malformed.
     */
    public function routes(): array
    {
        $routes = [];
        foreach ($this->config->controllerDirectories($this->basePath) as $namespace => $directory) {
            $classes = self::classesIn($namespace, $directory);
            if ($classes === []) {
                throw ConfigurationException::invalidValue(
                    RouterConfig::SECTION,
                    'controllers.' . $namespace,
                    $this->config->controllers[$namespace],
                    sprintf('%s holds no class of that namespace', $directory),
                );
            }

            foreach ($classes as $className) {
                foreach ($this->reader->readWithRequirements($className) as $discovered) {
                    $routes[] = $this->expanded($discovered);
                }
            }
        }

        self::assertNoCoexistingNames($routes);

        return $routes;
    }

    /**
     * The concrete classes under a directory, named from their PSR-4 namespace prefix and path, sorted by name.
     *
     * @return list<class-string>
     */
    public static function classesIn(string $namespace, string $directory): array
    {
        $namespace = rtrim($namespace, '\\') . '\\';
        $classes = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = substr($file->getPathname(), strlen($directory) + 1);
            $className = $namespace . str_replace(['/', '\\', '.php'], ['\\', '\\', ''], $relativePath);

            if (!class_exists($className)) {
                continue;
            }

            $reflection = new ReflectionClass($className);
            if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isTrait()) {
                continue;
            }

            $classes[] = $className;
        }

        sort($classes);

        return $classes;
    }

    /**
     * @throws RouteMiddlewareException
     * @throws RouteSignatureException
     */
    private function expanded(DiscoveredRoute $discovered): DiscoveredRoute
    {
        $route = $discovered->route;
        foreach ($discovered->groups as $group) {
            if (!isset($this->config->middlewareGroups[$group])) {
                throw RouteMiddlewareException::undeclaredMiddlewareGroup($group, $route->handler);
            }
        }

        return new DiscoveredRoute(
            new Route(
                method: $route->method,
                path: $route->path,
                handler: $route->handler,
                constraints: $route->constraints,
                middlewares: Router::expandMiddlewareGroups($this->config->middlewareGroups, $route->middlewares),
                name: $route->name,
                excludedMiddlewares: $route->excludedMiddlewares,
            ),
            $discovered->requirements,
            $discovered->groups,
        );
    }

    /**
     * @param list<DiscoveredRoute> $routes
     * @throws RouteAttributeException
     */
    private static function assertNoCoexistingNames(array $routes): void
    {
        $byName = [];
        foreach ($routes as $discovered) {
            $name = $discovered->route->name;
            if ($name === null) {
                continue;
            }

            foreach ($byName[$name] ?? [] as $earlier) {
                if (self::canCoexist($earlier->requirements, $discovered->requirements)) {
                    throw RouteAttributeException::coexistingRouteNames(
                        $name,
                        $earlier->route->handler,
                        $discovered->route->handler,
                    );
                }
            }

            $byName[$name][] = $discovered;
        }
    }

    /**
     * Two routes can be registered together unless one variable is required at a different value by each.
     *
     * @param list<array{string, string}> $first
     * @param list<array{string, string}> $second
     */
    private static function canCoexist(array $first, array $second): bool
    {
        foreach ($first as [$variable, $value]) {
            foreach ($second as [$otherVariable, $otherValue]) {
                if ($variable === $otherVariable && $value !== $otherValue) {
                    return false;
                }
            }
        }

        return true;
    }
}
