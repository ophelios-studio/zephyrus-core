<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Zephyrus\Core\Cache\BootCache;
use Zephyrus\Core\Cache\BootCacheException;
use Zephyrus\Core\Cache\CacheRefusal;
use Zephyrus\Core\Cache\PathPrefix;
use Zephyrus\Core\Cache\WarmableCache;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Core\Config\RouterConfig;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Routing\Exception\RouteAttributeException;
use Zephyrus\Routing\Exception\RouteCacheException;
use Zephyrus\Routing\Exception\RouteMiddlewareException;
use Zephyrus\Routing\Exception\RouteSignatureException;

/**
 * Every route of the router: section with its #[RequiresEnv] pairs, written by vendor/bin/zephyrus cache:warm and
 * filtered for the environment on every load. Protected by owners and modes (see BootCache), and ignored once
 * config.yml, the router: section, a controller directory, or the file of a parent class, trait or interface of a
 * controller has changed. A constant of another class read by an attribute is not tracked: run cache:warm on every
 * build.
 *
 * @internal
 */
final readonly class RouteCache implements WarmableCache
{
    public const string CONFIGURATION_FILE = 'config.yml';

    public const string ROOT_ONLY = 'router: is cached only from ' . self::CONFIGURATION_FILE
        . ' at the application root';
    private const string MISSING_LOG_LINE =
        'Zephyrus: no routes cache at %s, so the controllers were discovered for this request. Run %s to write it.';
    private const string UNCACHEABLE_LOG_LINE = 'Zephyrus: the controllers were discovered for this request: '
        . self::ROOT_ONLY . ', and %s does not exist.';

    private const string FINGERPRINT_ALGORITHM = 'xxh128';
    private const int ENTRY_SIZE = 8;

    private string $path;
    private BootCache $cache;

    /**
     * @param string $basePath Real path of the application root.
     * @param string $configurationFile The config.yml the router: section is read from.
     * @param (\Closure(string): (int|false))|null $ownerOf Reads the owner uid of a path, fileowner() by default.
     * @throws ConfigurationException when the cache file would leave the application root.
     */
    public function __construct(
        private RouterConfig $config,
        private string $basePath,
        private string $configurationFile,
        ?\Closure $ownerOf = null,
    ) {
        $this->path = $config->cachePath($basePath);
        $this->cache = new BootCache($this->path, $this->basePath, $configurationFile, $ownerOf);
    }

    /**
     * The routes cache of a configuration file that declares router:, under the directory of that file.
     *
     * @throws ConfigurationException when the file is not a config.yml, or the cache file would leave its directory.
     */
    public static function forConfigurationFile(string $source, RouterConfig $config): self
    {
        if (basename($source) !== self::CONFIGURATION_FILE) {
            throw new ConfigurationException(sprintf(
                'Configuration file %s declares router:, but %s: move the router: section to that file.',
                basename($source),
                self::ROOT_ONLY,
            ));
        }

        return new self($config, dirname($source), $source);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Discover every route, whatever the environment; the closure writes them with their #[RequiresEnv] pairs.
     *
     * @return \Closure(): string
     * @throws ConfigurationException when a controller directory is missing or holds no class of its namespace.
     * @throws RouteAttributeException when a #[RequiresEnv] names a variable that is never read, or two routes of
     *                                 one name can be registered together.
     * @throws RouteMiddlewareException when a #[MiddlewareGroup] is not declared under router.middleware_groups.
     * @throws RouteSignatureException when a route path is malformed.
     * @throws RouteCacheException when no route is found.
     * @throws BootCacheException when the cache cannot be written safely.
     */
    public function prepare(): \Closure
    {
        $routes = (new RouteDiscovery($this->config, $this->basePath))->routes();
        if ($routes === []) {
            throw RouteCacheException::noRoutes($this->path);
        }

        $payload = $this->payloadOf($routes);
        $store = $this->cache->prepareStore($payload, $this->fingerprint($payload));
        $line = sprintf('Wrote %s (%d %s)', $this->path, count($routes), count($routes) === 1 ? 'route' : 'routes');

        return static function () use ($store, $line): string {
            $store();

            return $line;
        };
    }

    /**
     * The routes with their #[RequiresEnv] pairs and the files their controllers inherit from.
     *
     * @param list<DiscoveredRoute> $routes
     * @return array{conditions: list<list<array{string, string}>>, routes: list<list<mixed>>, dependencies: list<string>}
     */
    private function payloadOf(array $routes): array
    {
        $conditions = [];
        $conditionIndexes = [];
        $entries = [];
        foreach ($routes as $discovered) {
            $key = var_export($discovered->requirements, true);
            if (!isset($conditionIndexes[$key])) {
                $conditionIndexes[$key] = count($conditions);
                $conditions[] = $discovered->requirements;
            }

            $route = $discovered->route;
            $entries[] = [
                $route->method,
                $route->path,
                $route->handler,
                $route->constraints,
                $route->middlewares,
                $route->name,
                $route->excludedMiddlewares,
                $conditionIndexes[$key],
            ];
        }

        return [
            'conditions' => $conditions,
            'routes' => $entries,
            'dependencies' => $this->dependencies($routes),
        ];
    }

    /**
     * The cached routes the environment meets, in discovery order, or null with one error_log line when the cache
     * is absent or unusable.
     */
    public function load(): ?RouteCollection
    {
        if (!is_file($this->configurationFile)) {
            error_log(sprintf(self::UNCACHEABLE_LOG_LINE, $this->configurationFile));

            return null;
        }

        if (!file_exists($this->path) && !is_link($this->path)) {
            error_log(sprintf(self::MISSING_LOG_LINE, $this->path, BootCache::WARM_COMMAND));

            return null;
        }

        $payload = $this->cache->load($this->fingerprint(...));
        if ($payload === null) {
            return null;
        }

        try {
            return RouteCollection::fromRoutes($this->activeRoutes($payload));
        } catch (CacheRefusal $refusal) {
            $this->logIgnored($refusal->getMessage());

            return null;
        }
    }

    public function prepareClear(): \Closure
    {
        return $this->cache->prepareClear();
    }

    /**
     * @param array<mixed> $payload
     * @return list<Route>
     * @throws CacheRefusal
     */
    private function activeRoutes(array $payload): array
    {
        $conditions = $payload['conditions'] ?? null;
        $entries = $payload['routes'] ?? null;
        if (!is_array($conditions) || !is_array($entries) || !array_is_list($entries)) {
            throw new CacheRefusal('it does not hold routes');
        }

        $met = [];
        foreach ($conditions as $index => $requirements) {
            if (!self::isRequirementList($requirements)) {
                throw new CacheRefusal(sprintf('its condition %s is not a list of variable and value pairs', $index));
            }

            try {
                $met[$index] = RouteAttributeReader::requirementsMet($requirements);
            } catch (\InvalidArgumentException) {
                throw new CacheRefusal(sprintf('its condition %s names a variable that is never read', $index));
            }
        }

        $namespaces = array_keys($this->config->controllers);
        $active = [];
        foreach ($entries as $index => $entry) {
            if (!is_array($entry) || count($entry) !== self::ENTRY_SIZE || !array_is_list($entry)) {
                throw self::malformedRoute($index);
            }

            [$method, $path, $handler, $constraints, $middlewares, $name, $excluded, $condition] = $entry;
            if (
                !is_string($method) || !is_string($path) || !is_string($handler) || !is_array($constraints)
                || !is_array($middlewares) || !array_is_list($middlewares) || ($name !== null && !is_string($name))
                || !is_array($excluded) || !array_is_list($excluded) || !is_int($condition) || !isset($met[$condition])
            ) {
                throw self::malformedRoute($index);
            }

            foreach ($constraints as $placeholder => $pattern) {
                if (!is_string($placeholder) || !is_string($pattern)) {
                    throw self::malformedRoute($index);
                }
            }

            foreach ([...$middlewares, ...$excluded] as $middleware) {
                if (!is_string($middleware)) {
                    throw self::malformedRoute($index);
                }
            }

            if (!self::isConfiguredHandler($handler, $namespaces)) {
                throw new CacheRefusal(sprintf('its route %d has a handler outside router.controllers', $index));
            }

            if ($met[$condition]) {
                /** @var array<string, string> $constraints */
                /** @var list<string> $middlewares */
                /** @var list<class-string<MiddlewareInterface>> $excluded */
                $active[] = [$method, $path, $handler, $constraints, $middlewares, $name, $excluded];
            }
        }

        return Route::restoreAll($active);
    }

    /**
     * @param list<string> $namespaces
     */
    private static function isConfiguredHandler(string $handler, array $namespaces): bool
    {
        foreach ($namespaces as $namespace) {
            if (PathPrefix::isUnder($handler, $namespace, '\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The current fingerprint of everything the cached routes were read from, or '' when the payload names no
     * dependency list or a controller directory is gone.
     *
     * @param array<mixed> $payload
     */
    private function fingerprint(array $payload): string
    {
        $dependencies = $payload['dependencies'] ?? null;
        if (!self::isStringList($dependencies)) {
            return '';
        }

        try {
            $directories = $this->config->controllerDirectories($this->basePath);
        } catch (ConfigurationException) {
            return '';
        }

        $lines = [];
        foreach ($directories as $directory) {
            $directoryIterator = new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS);
            foreach (new RecursiveIteratorIterator($directoryIterator) as $file) {
                $lines[] = $this->fileLine($file);
            }
        }

        foreach ($dependencies as $dependency) {
            $path = $this->absolute($dependency);
            $lines[] = is_file($path) ? $this->fileLine(new SplFileInfo($path)) : '';
        }

        sort($lines);
        array_unshift(
            $lines,
            (string) hash_file(self::FINGERPRINT_ALGORITHM, $this->configurationFile),
            var_export($this->config->toArray(), true),
        );

        return hash(self::FINGERPRINT_ALGORITHM, implode("\n", $lines));
    }

    private function fileLine(SplFileInfo $file): string
    {
        return sprintf('%s %d %d', $this->relative($file->getPathname()), $file->getMTime(), $file->getSize());
    }

    /**
     * The files outside the controller directories that declare a parent class, a trait or an interface of a
     * controller.
     *
     * @param list<DiscoveredRoute> $routes
     * @return list<string> Paths relative to the application root when under it.
     */
    private function dependencies(array $routes): array
    {
        $directories = $this->config->controllerDirectories($this->basePath);

        $files = [];
        foreach ($routes as $discovered) {
            /** @var class-string $className */
            $className = explode('@', $discovered->route->handler)[0];
            foreach (self::declaringFiles(new ReflectionClass($className)) as $file) {
                if (!self::isUnderAny($file, $directories)) {
                    $relative = $this->relative($file);
                    $files[$relative] = $relative;
                }
            }
        }

        return array_values($files);
    }

    /**
     * The files of the class, its parents, every trait any of them uses and every interface it implements.
     *
     * @param ReflectionClass<object> $class
     * @return list<string>
     */
    private static function declaringFiles(ReflectionClass $class): array
    {
        $files = [];
        $pending = [$class, ...$class->getInterfaces()];
        while ($pending !== []) {
            $current = array_pop($pending);
            $file = $current->getFileName();
            if ($file !== false) {
                $files[] = $file;
            }

            array_push($pending, ...array_values($current->getTraits()));
            $parent = $current->getParentClass();
            if ($parent !== false) {
                $pending[] = $parent;
            }
        }

        return $files;
    }

    /**
     * @param array<string, string> $directories
     */
    private static function isUnderAny(string $file, array $directories): bool
    {
        foreach ($directories as $directory) {
            if (PathPrefix::isUnder($file, $directory)) {
                return true;
            }
        }

        return false;
    }

    private function relative(string $path): string
    {
        return PathPrefix::isUnder($path, $this->basePath) ? substr($path, strlen($this->basePath) + 1) : $path;
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->basePath . '/' . $path;
    }

    private function logIgnored(string $reason): void
    {
        error_log(sprintf(BootCache::IGNORED_LOG_LINE, $this->path, $reason));
    }

    private static function malformedRoute(int|string $index): CacheRefusal
    {
        return new CacheRefusal(sprintf('its route %s does not have the cached layout', $index));
    }

    /**
     * @phpstan-assert-if-true list<array{string, string}> $value
     */
    private static function isRequirementList(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $pair) {
            if (!is_array($pair) || count($pair) !== 2 || !is_string($pair[0] ?? null) || !is_string($pair[1] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @phpstan-assert-if-true list<string> $value
     */
    private static function isStringList(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!is_string($item)) {
                return false;
            }
        }

        return true;
    }
}
