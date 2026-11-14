<?php

declare(strict_types=1);

namespace Zephyrus\Console;

use Composer\InstalledVersions;
use Zephyrus\Core\Cache\ConfigurationCache;
use Zephyrus\Core\Cache\PathPrefix;
use Zephyrus\Core\Cache\WarmableCache;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Core\Config\ConfigurationFile;
use Zephyrus\Core\Config\RouterConfig;
use Zephyrus\Exceptions\MessageValue;
use Zephyrus\Exceptions\ZephyrusException;
use Zephyrus\Routing\RouteCache;

/**
 * The vendor/bin/zephyrus command line: cache:warm and cache:clear over one or more configuration files, each with
 * the routes cache of its router: section when it declares one.
 *
 * @internal
 */
final readonly class Console
{
    public const int SUCCESS = 0;
    public const int REFUSED = 1;
    public const int USAGE_ERROR = 2;
    public const string USAGE = <<<'TEXT'
        Usage:
          vendor/bin/zephyrus cache:warm [--config=PATH]...
          vendor/bin/zephyrus cache:clear [--config=PATH]...
        PATH defaults to config.yml at the project root; a relative PATH is read from the current directory.

        TEXT;

    private const string WARM = 'cache:warm';
    private const string CLEAR = 'cache:clear';
    private const string CONFIG_OPTION = '--config';
    private const string DEFAULT_CONFIG = 'config.yml';

    /**
     * @param resource $output
     * @param resource $errorOutput
     */
    public function __construct(
        private string $workingDirectory,
        private string $projectRoot,
        private mixed $output,
        private mixed $errorOutput,
    ) {
    }

    /**
     * The console of this process: its working directory, the Composer root package, STDOUT and STDERR.
     */
    public static function fromEnvironment(): self
    {
        $root = InstalledVersions::getRootPackage()['install_path'];

        return new self((string) getcwd(), realpath($root) ?: $root, STDOUT, STDERR);
    }

    /**
     * Run one command line and return its exit code: SUCCESS, REFUSED or USAGE_ERROR.
     *
     * @param list<string> $arguments The arguments after the program name.
     */
    public function run(array $arguments): int
    {
        $command = array_shift($arguments);
        if ($command !== self::WARM && $command !== self::CLEAR) {
            return $this->usageError(
                $command === null ? 'No command given.' : sprintf('Unknown command %s.', MessageValue::quote($command)),
            );
        }

        $configurations = [];
        foreach ($arguments as $argument) {
            $path = $this->optionValue($argument);
            if ($path === null) {
                return $this->usageError(sprintf('Unknown option %s.', MessageValue::quote($argument)));
            }

            if ($path === '') {
                return $this->usageError('The --config option needs a path, as in --config=PATH.');
            }

            $configurations[] = str_starts_with($path, '/') ? $path : $this->workingDirectory . '/' . $path;
        }

        $steps = [];
        $warmed = [];
        $seen = [];
        foreach ($configurations ?: [$this->projectRoot . '/' . self::DEFAULT_CONFIG] as $configuration) {
            try {
                $configurationCache = ConfigurationCache::forSource($configuration);
                if (isset($seen[$configurationCache->source()])) {
                    continue;
                }

                $seen[$configurationCache->source()] = true;
                $routeCache = $this->routeCacheOf($configurationCache->source());
                foreach ([$configurationCache, ...($routeCache === null ? [] : [$routeCache])] as $cache) {
                    if ($command === self::WARM) {
                        if ($cache === $configurationCache) {
                            $gitIgnore = dirname($cache->path()) . '/' . ConfigurationCache::GIT_IGNORE;
                            $this->refuseOverlap($gitIgnore, $warmed, true);
                            $warmed[$gitIgnore] = true;
                        }

                        $this->refuseOverlap($cache->path(), $warmed);
                        $warmed[$cache->path()] = false;
                    }

                    $steps[] = [$configuration, $command === self::WARM
                        ? $this->preparing($cache, dirname($configurationCache->source()))
                        : self::clearing($cache)];
                }

                if ($command === self::WARM && $routeCache === null) {
                    $line = sprintf('No router: section in %s, so no routes cache was written', $configuration);
                    $steps[] = [$configuration, static fn (): string => $line];
                }
            } catch (ZephyrusException $exception) {
                return $this->refused($command, $configuration, $exception);
            }
        }

        foreach ($steps as [$configuration, $step]) {
            try {
                $this->say($step());
            } catch (ZephyrusException $exception) {
                return $this->refused($command, $configuration, $exception);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string, bool> $planned Planned path => whether it is a shared .gitignore.
     * @throws ConfigurationException when the path is at, under or above a path already planned.
     */
    private function refuseOverlap(string $path, array $planned, bool $isGitIgnore = false): void
    {
        foreach ($planned as $other => $otherIsGitIgnore) {
            if ($other === $path) {
                if ($isGitIgnore && $otherIsGitIgnore) {
                    continue;
                }

                throw new ConfigurationException(sprintf(
                    'Two caches would be written to %s: give router.cache another path.',
                    $path,
                ));
            }

            $below = PathPrefix::isUnder($path, $other);
            if ($below || PathPrefix::isUnder($other, $path)) {
                [$inner, $outer] = $below ? [$path, $other] : [$other, $path];

                throw new ConfigurationException(sprintf(
                    'The cache %s would be written under the cache %s: give router.cache another path.',
                    $inner,
                    $outer,
                ));
            }
        }
    }

    /**
     * The routes cache of a configuration file, read from its YAML with no tag resolved, or null without router:.
     *
     * @throws ZephyrusException when the file does not parse or its router: section is refused.
     */
    private function routeCacheOf(string $source): ?RouteCache
    {
        $values = ConfigurationFile::parseYaml($source);
        if (!array_key_exists(RouterConfig::SECTION, $values)) {
            return null;
        }

        return RouteCache::forConfigurationFile($source, RouterConfig::fromArray($values[RouterConfig::SECTION]));
    }

    /**
     * @return \Closure(): string
     * @throws ZephyrusException when the cache cannot be written safely, with the root rule added when a routes cache
     *                           is warmed from a configuration file outside the project root.
     */
    private function preparing(WarmableCache $cache, string $root): \Closure
    {
        try {
            return $cache->prepare();
        } catch (ConfigurationException $exception) {
            if (!$cache instanceof RouteCache || $root === $this->projectRoot) {
                throw $exception;
            }

            throw new ConfigurationException(sprintf(
                '%s Note that %s, and its paths were resolved against %s.',
                $exception->getMessage(),
                RouteCache::ROOT_ONLY,
                $root,
            ), previous: $exception);
        }
    }

    /**
     * @return \Closure(): string
     * @throws ZephyrusException when the cache file cannot be deleted safely.
     */
    private static function clearing(WarmableCache $cache): \Closure
    {
        $remove = $cache->prepareClear();

        return static fn (): string => ($remove() ? 'Removed ' : 'Nothing to remove at ') . $cache->path();
    }

    /**
     * The value of a --config argument, or null when the argument is not --config.
     */
    private function optionValue(string $argument): ?string
    {
        if ($argument === self::CONFIG_OPTION) {
            return '';
        }

        return str_starts_with($argument, self::CONFIG_OPTION . '=')
            ? substr($argument, strlen(self::CONFIG_OPTION) + 1)
            : null;
    }

    private function refused(string $command, string $configuration, ZephyrusException $exception): int
    {
        fwrite($this->errorOutput, sprintf("%s failed for %s: %s\n", $command, $configuration, $exception->getMessage()));

        return self::REFUSED;
    }

    private function say(string $line): void
    {
        fwrite($this->output, $line . "\n");
    }

    private function usageError(string $message): int
    {
        fwrite($this->errorOutput, $message . "\n" . self::USAGE);

        return self::USAGE_ERROR;
    }
}
