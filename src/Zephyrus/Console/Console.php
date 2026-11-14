<?php

declare(strict_types=1);

namespace Zephyrus\Console;

use Composer\InstalledVersions;
use Zephyrus\Core\Cache\ConfigurationCache;
use Zephyrus\Core\Cache\WarmableCache;
use Zephyrus\Exceptions\MessageValue;
use Zephyrus\Exceptions\ZephyrusException;

/**
 * The vendor/bin/zephyrus command line: cache:warm and cache:clear over one or more configuration files.
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

        foreach ($configurations ?: [$this->projectRoot . '/' . self::DEFAULT_CONFIG] as $configuration) {
            try {
                foreach ($this->cachesOf($configuration) as $cache) {
                    $this->say($command === self::WARM ? $this->warm($cache) : $this->clear($cache));
                }
            } catch (ZephyrusException $exception) {
                fwrite($this->errorOutput, sprintf("%s failed for %s: %s\n", $command, $configuration, $exception->getMessage()));

                return self::REFUSED;
            }
        }

        return self::SUCCESS;
    }

    /**
     * The caches built from one configuration file.
     *
     * @return list<WarmableCache>
     */
    private function cachesOf(string $configuration): array
    {
        return [ConfigurationCache::forSource($configuration)];
    }

    private function warm(WarmableCache $cache): string
    {
        $cache->warm();

        return 'Wrote ' . $cache->path();
    }

    private function clear(WarmableCache $cache): string
    {
        return ($cache->clear() ? 'Removed ' : 'Nothing to remove at ') . $cache->path();
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
