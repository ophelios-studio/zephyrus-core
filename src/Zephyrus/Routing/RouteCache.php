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
 * config.yml, the router: section, a controller directory, or a file a controller inherits from, has changed.
 *
 * @internal
 */
final readonly class RouteCache implements WarmableCache
{
    public const string CONFIGURATION_FILE = 'config.yml';
    public const string MISSING_LOG_LINE =
        'Zephyrus: no routes cache at %s, so the controllers were discovered for this request. Run %s to write it.';

    private const string FINGERPRINT_ALGORITHM = 'xxh128';
    private const int ENTRY_SIZE = 8;

    private string $basePath;
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
        string $basePath,
        private string $configurationFile,
        ?\Closure $ownerOf = null,
    ) {
        $this->basePath = rtrim($basePath, '/');
        $this->path = $config->cachePath($this->basePath);
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
                'Configuration file %s declares router:, but Router::fromConfiguration() checks the routes cache '
                . 'against the %s of the application root: move the router: section to that file.',
                basename($source),
                self::CONFIGURATION_FILE,
            ));
        }

        return new self($config, dirname($source), $source);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function has(): bool
    {
        return file_exists($this->path) || is_link($this->path);
    }

    /**
     * Discover every route, whatever the environment, and write them with their #[RequiresEnv] pairs.
     *
     * @throws ConfigurationException when a controller directory is missing or holds no class of its namespace.
     * @throws RouteAttributeException when a #[RequiresEnv] names a variable that is never read, or two routes of
     *                                 one name can be registered together.
     * @throws RouteMiddlewareException when a #[MiddlewareGroup] is not declared under router.middleware_groups.
     * @throws RouteSignatureException when a route path is malformed.
     * @throws RouteCacheException when no route is found.
     * @throws BootCacheException when the cache cannot be written safely.
     */
    public function warm(): string
    {
        $routes = (new RouteDiscovery($this->config, $this->basePath))->routes();
        if ($routes === []) {
            throw RouteCacheException::noRoutes($this->path);
        }

        $this->save($routes);

        return sprintf('Wrote %s (%d %s)', $this->path, count($routes), count($routes) === 1 ? 'route' : 'routes');
    }

    /**
     * Write the routes with their #[RequiresEnv] pairs and the files their controllers inherit from.
     *
     * @param list<DiscoveredRoute> $routes
     * @throws BootCacheException when the cache cannot be written safely.
     */
    public function save(array $routes): void
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
                array_values($route->middlewares),
                $route->name,
                $route->excludedMiddlewares,
                $conditionIndexes[$key],
            ];
        }

        $payload = [
            'conditions' => $conditions,
            'routes' => $entries,
            'dependencies' => $this->dependencies($routes),
        ];

        $this->cache->store($payload, $this->fingerprint($payload));
    }

    /**
     * The cached routes the environment meets, in discovery order, or null when the cache is absent or unusable,
     * with one error_log line when it is unusable.
     */
    public function load(): ?RouteCollection
    {
        if (!is_file($this->configurationFile)) {
            $this->logIgnored(sprintf('it is checked against %s, which does not exist', $this->configurationFile));

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

    public function clear(): bool
    {
        return $this->cache->clear();
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

        $active = [];
        foreach ($entries as $index => $entry) {
            if (!is_array($entry) || count($entry) !== self::ENTRY_SIZE || !array_is_list($entry)) {
                throw self::malformedRoute($index);
            }

            [$method, $path, $handler, $constraints, $middlewares, $name, $excluded, $condition] = $entry;
            if (
                !is_string($method) || !is_string($path) || !is_string($handler) || !self::isStringMap($constraints)
                || !self::isStringList($middlewares) || ($name !== null && !is_string($name))
                || !self::isStringList($excluded) || !is_int($condition) || !isset($met[$condition])
            ) {
                throw self::malformedRoute($index);
            }

            if (!$this->isConfiguredHandler($handler)) {
                throw new CacheRefusal(sprintf('its route %d has a handler outside router.controllers', $index));
            }

            if ($met[$condition]) {
                /** @var list<class-string<MiddlewareInterface>> $excluded */
                $active[] = [$method, $path, $handler, $constraints, $middlewares, $name, $excluded];
            }
        }

        return Route::restoreAll($active);
    }

    private function isConfiguredHandler(string $handler): bool
    {
        foreach (array_keys($this->config->controllers) as $namespace) {
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
            array_push($lines, ...$this->directoryLines($directory));
        }

        foreach ($dependencies as $dependency) {
            $lines[] = $this->fileLine($this->absolute($dependency));
        }

        sort($lines);
        array_unshift(
            $lines,
            (string) hash_file(self::FINGERPRINT_ALGORITHM, $this->configurationFile),
            var_export($this->config->toArray(), true),
        );

        return hash(self::FINGERPRINT_ALGORITHM, implode("\n", $lines));
    }

    /**
     * One line per directory (its mtime) and per file (its mtime and size), the directory included.
     *
     * @return list<string>
     */
    private function directoryLines(string $directory): array
    {
        $lines = [$this->entryLine(new SplFileInfo($directory))];
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($entries as $entry) {
            $lines[] = $this->entryLine($entry);
        }

        return $lines;
    }

    private function fileLine(string $path): string
    {
        return is_file($path) ? $this->entryLine(new SplFileInfo($path)) : $this->relative($path) . ' missing';
    }

    private function entryLine(SplFileInfo $entry): string
    {
        return $entry->isDir()
            ? sprintf('%s/ %d', $this->relative($entry->getPathname()), $entry->getMTime())
            : sprintf('%s %d %d', $this->relative($entry->getPathname()), $entry->getMTime(), $entry->getSize());
    }

    /**
     * The files outside the controller directories that declare a parent class or a trait of a controller.
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
            $className = strstr($discovered->route->handler, '@', true) ?: $discovered->route->handler;
            foreach (self::declaringFiles(new ReflectionClass($className)) as $file) {
                if (!self::isUnderAny($file, $directories)) {
                    $files[$this->relative($file)] = true;
                }
            }
        }

        $files = array_keys($files);
        sort($files);

        return $files;
    }

    /**
     * The files of the class, its parents and every trait any of them uses.
     *
     * @param ReflectionClass<object> $class
     * @return list<string>
     */
    private static function declaringFiles(ReflectionClass $class): array
    {
        $files = [];
        $pending = [$class];
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
        error_log(sprintf(BootCache::IGNORED_LOG_LINE, $this->path, $reason, BootCache::WARM_COMMAND));
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

    /**
     * @phpstan-assert-if-true array<string, string> $value
     */
    private static function isStringMap(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $key => $item) {
            if (!is_string($key) || !is_string($item)) {
                return false;
            }
        }

        return true;
    }
}
