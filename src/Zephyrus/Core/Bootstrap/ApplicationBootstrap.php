<?php

declare(strict_types=1);

namespace Zephyrus\Core\Bootstrap;

use RuntimeException;
use Zephyrus\Core\Application;
use Zephyrus\Core\ApplicationBuilder;

final class ApplicationBootstrap
{
    /**
     * @param string[] $requiredConfigFiles
     * @param string[] $optionalConfigFiles
     */
    public static function fromConfigFiles(
        array $requiredConfigFiles = [],
        array $optionalConfigFiles = [],
    ): Application {
        if ($requiredConfigFiles === [] && $optionalConfigFiles === []) {
            return ApplicationBuilder::create()->build();
        }

        $existingOptional = array_values(array_filter(
            $optionalConfigFiles,
            static fn (mixed $path): bool => is_string($path) && is_file($path),
        ));

        return ApplicationBuilder::buildFromConfigurationFiles(array_merge($requiredConfigFiles, $existingOptional));
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public static function fromConfigurationArray(array $configuration): Application
    {
        return ApplicationBuilder::buildFromConfigurationArray($configuration);
    }

    public static function fromConfigurationFile(string $path): Application
    {
        return ApplicationBuilder::buildFromConfigurationFile($path);
    }

    /**
     * Build an application using environment variables as bootstrap inputs.
     *
     * Supported variables:
     * - APP_CONFIG_DIR   (default: getcwd() . '/config')
     * - APP_CONFIG_BASE  (default: 'app')
     * - APP_ENV          (optional environment suffix)
     * - APP_CONFIG_EXTRA (comma-separated extra optional names)
     */
    public static function fromEnvironment(): Application
    {
        $dir = getenv('APP_CONFIG_DIR');
        $base = getenv('APP_CONFIG_BASE');
        $env = getenv('APP_ENV');
        $extra = getenv('APP_CONFIG_EXTRA');

        $configDir = is_string($dir) && trim($dir) !== ''
            ? trim($dir)
            : rtrim((string) getcwd(), '/\\') . '/config';

        $baseName = is_string($base) && trim($base) !== '' ? trim($base) : 'app';
        $environment = is_string($env) ? $env : null;

        $extraOptionalNames = [];
        if (is_string($extra) && trim($extra) !== '') {
            $extraOptionalNames = array_values(array_filter(array_map(
                static fn (string $name): string => trim($name),
                explode(',', $extra),
            ), static fn (string $name): bool => $name !== ''));
        }

        return self::fromConfigDirectory(
            configDir: $configDir,
            baseName: $baseName,
            environment: $environment,
            extraOptionalNames: $extraOptionalNames,
        );
    }

    /**
     * Build an application from a conventional config directory layout.
     *
     * Required file:   <configDir>/<baseName>.php
     * Optional files:  <configDir>/<baseName>.local.php
     *                  <configDir>/<baseName>.<environment>.php
     *
     * $environment defaults to APP_ENV (when set); pass null to disable
     * environment-specific optional loading.
     */
    public static function fromConfigDirectory(
        string $configDir,
        string $baseName = 'app',
        ?string $environment = null,
        array $extraOptionalNames = [],
    ): Application {
        return self::fromResolvedPaths(self::configPathsForDirectory(
            configDir: $configDir,
            baseName: $baseName,
            environment: $environment,
            extraOptionalNames: $extraOptionalNames,
        ));
    }

    /**
     * Build an application from pre-resolved required/optional path groups.
     *
     * @param array{required: string, optional?: string[]} $paths
     */
    public static function fromResolvedPaths(array $paths): Application
    {
        $required = $paths['required'] ?? '';
        if (!is_string($required) || trim($required) === '') {
            throw new RuntimeException('Resolved config paths must include a non-empty "required" entry.');
        }

        $optional = $paths['optional'] ?? [];
        if (!is_array($optional)) {
            throw new RuntimeException('Resolved config "optional" entry must be an array of file paths.');
        }

        return self::fromConfigFiles(
            requiredConfigFiles: [trim($required)],
            optionalConfigFiles: self::normalizeOptionalPaths($optional),
        );
    }

    /**
     * Resolve required + optional config file paths for a conventional config directory.
     *
     * @return array{required: string, optional: string[]}
     */
    public static function configPathsForDirectory(
        string $configDir,
        string $baseName = 'app',
        ?string $environment = null,
        array $extraOptionalNames = [],
    ): array {
        $configDir = trim($configDir);
        if ($configDir === '') {
            throw new RuntimeException('Config directory must not be empty.');
        }

        $baseName = self::normalizeBaseName($baseName);
        $configDir = rtrim($configDir, '/\\');
        $required = $configDir . '/' . $baseName . '.php';

        $optional = [
            $configDir . '/' . $baseName . '.local.php',
        ];

        foreach (self::normalizeOptionalNames($extraOptionalNames) as $name) {
            $optional[] = $configDir . '/' . $baseName . '.' . $name . '.php';
        }

        $resolvedEnvironment = $environment;
        if ($resolvedEnvironment === null) {
            $appEnv = getenv('APP_ENV');
            $resolvedEnvironment = is_string($appEnv) ? $appEnv : '';
        }

        $resolvedEnvironment = trim($resolvedEnvironment);
        if ($resolvedEnvironment !== '') {
            $optional[] = $configDir . '/' . $baseName . '.' . $resolvedEnvironment . '.php';
        }

        $optional = self::uniqueStrings($optional);

        return [
            'required' => $required,
            'optional' => $optional,
        ];
    }

    private static function normalizeBaseName(string $baseName): string
    {
        $baseName = trim($baseName);
        if ($baseName === '') {
            throw new RuntimeException('Config base name must not be empty.');
        }

        if (str_contains($baseName, '/') || str_contains($baseName, '\\')) {
            throw new RuntimeException('Config base name must not contain path separators.');
        }

        return $baseName;
    }

    /**
     * @param mixed[] $optionalNames
     * @return string[]
     */
    private static function normalizeOptionalNames(array $optionalNames): array
    {
        $normalized = [];

        foreach ($optionalNames as $index => $name) {
            if (!is_string($name)) {
                throw new RuntimeException(sprintf('Optional config name at index %d must be a string.', $index));
            }

            $trimmed = trim($name);
            if ($trimmed === '') {
                throw new RuntimeException(sprintf('Optional config name at index %d must not be empty.', $index));
            }

            if (str_contains($trimmed, '/') || str_contains($trimmed, '\\')) {
                throw new RuntimeException(sprintf('Optional config name at index %d must not contain path separators.', $index));
            }

            if (in_array($trimmed, $normalized, true)) {
                continue;
            }

            $normalized[] = $trimmed;
        }

        return $normalized;
    }

    /**
     * @param mixed[] $optional
     * @return string[]
     */
    private static function normalizeOptionalPaths(array $optional): array
    {
        $normalized = [];

        foreach ($optional as $index => $path) {
            if (!is_string($path)) {
                throw new RuntimeException(sprintf('Resolved optional path at index %d must be a string.', $index));
            }

            $trimmed = trim($path);
            if ($trimmed === '') {
                throw new RuntimeException(sprintf('Resolved optional path at index %d must not be empty.', $index));
            }

            $normalized[] = $trimmed;
        }

        return self::uniqueStrings($normalized);
    }

    /**
     * @param string[] $values
     * @return string[]
     */
    private static function uniqueStrings(array $values): array
    {
        $unique = [];

        foreach ($values as $value) {
            if (in_array($value, $unique, true)) {
                continue;
            }

            $unique[] = $value;
        }

        return $unique;
    }
}
