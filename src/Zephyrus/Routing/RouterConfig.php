<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use Symfony\Component\Yaml\Tag\TaggedValue;
use Zephyrus\Core\Cache\BootCache;
use Zephyrus\Core\Config\ConfigKeys;
use Zephyrus\Core\Config\ConfigurationException;

/**
 * The router: section, read by Router::fromConfiguration() and by vendor/bin/zephyrus cache:warm.
 *
 * YAML keys (any other key is refused, and no value may carry a tag such as !env):
 * - controllers: PSR-4 namespace => directory of its controllers, discovered in this order. Required.
 * - middleware_groups (middlewareGroups): group name => middleware or group names, as Router::middlewareGroup().
 *   Default none.
 * - cache: the routes cache file, which must stay under the application root. Default var/zephyrus/routes.php.
 *
 * A relative path is resolved against the application root (basePath), never against the current directory.
 */
final readonly class RouterConfig
{
    public const string SECTION = 'router';
    public const string DEFAULT_CACHE = 'var/zephyrus/routes.php';

    private const array SPELLINGS = [
        'controllers' => ['controllers'],
        'middlewareGroups' => ['middleware_groups', 'middlewareGroups'],
        'cache' => ['cache'],
    ];

    private const string NAMESPACE_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D';

    /**
     * @param array<string, string> $controllers
     * @param array<string, list<string>> $middlewareGroups
     */
    private function __construct(
        public array $controllers,
        public array $middlewareGroups,
        public string $cache,
    ) {
    }

    /**
     * @param array<array-key, mixed> $values
     * @throws ConfigurationException when a key is unknown, a value carries a tag, controllers is missing or empty,
     *         a namespace or a path is not a non-empty string, or a middleware group is not a list of names.
     */
    public static function fromArray(array $values): self
    {
        self::refuseTags($values);
        $keys = ConfigKeys::read(self::SECTION, $values, self::SPELLINGS);

        return new self(
            self::controllersOf($keys->value('controllers')),
            self::middlewareGroupsOf($keys->key('middlewareGroups'), $keys->value('middlewareGroups')),
            self::cacheOf($keys->value('cache')),
        );
    }

    /**
     * Refuse a tag anywhere in the section: cache:warm reads it without the environment.
     *
     * @internal Read by ConfigurationFile before it resolves !env.
     * @throws ConfigurationException
     */
    public static function refuseTags(mixed $value, string $path = ''): void
    {
        if ($value instanceof TaggedValue) {
            throw ConfigurationException::invalidType(
                self::SECTION,
                $path === '' ? self::SECTION : $path,
                sprintf(
                    'is written under the !%s tag, but %s reads this section without the environment: write the value itself',
                    $value->getTag(),
                    BootCache::WARM_COMMAND,
                ),
            );
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                self::refuseTags($item, $path === '' ? (string) $key : $path . '.' . $key);
            }
        }
    }

    /**
     * The controller directories by namespace, resolved against the application root.
     *
     * @param string $basePath Absolute path of the application root.
     * @return array<string, string>
     * @throws ConfigurationException when a directory does not exist.
     */
    public function controllerDirectories(string $basePath): array
    {
        $directories = [];
        foreach ($this->controllers as $namespace => $directory) {
            $resolved = self::resolve($directory, $basePath);
            if (!is_dir($resolved)) {
                throw ConfigurationException::invalidValue(
                    self::SECTION,
                    'controllers.' . $namespace,
                    $directory,
                    sprintf('%s is not a directory', $resolved),
                );
            }

            $directories[$namespace] = $resolved;
        }

        return $directories;
    }

    /**
     * The routes cache file, resolved against the application root.
     *
     * @param string $basePath Absolute path of the application root.
     * @throws ConfigurationException when the file would leave the application root.
     */
    public function cachePath(string $basePath): string
    {
        $root = rtrim($basePath, '/');
        $path = self::withoutDotSegments(self::resolve($this->cache, $root));

        if (!str_starts_with($path, $root . '/')) {
            throw ConfigurationException::invalidValue(
                self::SECTION,
                'cache',
                $this->cache,
                sprintf('it resolves outside the application root %s', $root),
            );
        }

        return $path;
    }

    /**
     * The section as written, under the preferred spelling of each key.
     *
     * @return array{controllers: array<string, string>, middleware_groups: array<string, list<string>>, cache: string}
     */
    public function toArray(): array
    {
        return [
            'controllers' => $this->controllers,
            'middleware_groups' => $this->middlewareGroups,
            'cache' => $this->cache,
        ];
    }

    /**
     * @return array<string, string>
     * @throws ConfigurationException
     */
    private static function controllersOf(mixed $value): array
    {
        if ($value === null) {
            throw ConfigurationException::missingRequired(self::SECTION, 'controllers');
        }

        if (!is_array($value) || $value === []) {
            throw ConfigurationException::invalidType(
                self::SECTION,
                'controllers',
                'must map each PSR-4 namespace to its directory, such as App\Controllers: app/Controllers',
            );
        }

        $controllers = [];
        foreach ($value as $namespace => $directory) {
            $trimmed = rtrim((string) $namespace, '\\');
            if (preg_match(self::NAMESPACE_PATTERN, $trimmed) !== 1) {
                throw ConfigurationException::invalidValue(
                    self::SECTION,
                    'controllers',
                    $namespace,
                    'each key must be a PSR-4 namespace, such as App\Controllers',
                );
            }

            if (!is_string($directory) || trim($directory) === '') {
                throw ConfigurationException::invalidType(
                    self::SECTION,
                    'controllers.' . $trimmed,
                    'must be the directory of that namespace',
                );
            }

            $controllers[$trimmed] = $directory;
        }

        return $controllers;
    }

    /**
     * @return array<string, list<string>>
     * @throws ConfigurationException
     */
    private static function middlewareGroupsOf(string $key, mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value)) {
            throw ConfigurationException::invalidType(
                self::SECTION,
                $key,
                'must map each group name to a list of middleware names',
            );
        }

        $groups = [];
        foreach ($value as $name => $middlewares) {
            if (!is_array($middlewares) || !array_is_list($middlewares)) {
                throw ConfigurationException::invalidType(self::SECTION, $key . '.' . $name, 'must be a list of middleware names');
            }

            foreach ($middlewares as $middleware) {
                if (!is_string($middleware) || $middleware === '') {
                    throw ConfigurationException::invalidType(
                        self::SECTION,
                        $key . '.' . $name,
                        'must be a list of middleware names',
                    );
                }
            }

            $groups[(string) $name] = $middlewares;
        }

        return $groups;
    }

    /**
     * @throws ConfigurationException
     */
    private static function cacheOf(mixed $value): string
    {
        if ($value === null) {
            return self::DEFAULT_CACHE;
        }

        if (!is_string($value) || trim($value) === '') {
            throw ConfigurationException::invalidType(self::SECTION, 'cache', 'must be the path of the routes cache file');
        }

        return $value;
    }

    private static function resolve(string $path, string $basePath): string
    {
        return str_starts_with($path, '/') ? $path : rtrim($basePath, '/') . '/' . $path;
    }

    private static function withoutDotSegments(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }
}
