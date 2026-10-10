<?php

declare(strict_types=1);

namespace Zephyrus\Core\Bootstrap;

use Zephyrus\Core\Application;
use Zephyrus\Core\ApplicationBuilder;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Core\Config\EnvironmentVariable;

final class ApplicationBootstrap
{
    /**
     * Builds from the required files and the optional files that exist.
     *
     * @param string[] $requiredConfigFiles
     * @param string[] $optionalConfigFiles
     * @throws ConfigurationException when a file is missing, unreadable or invalid, or a declared security setting is not wired.
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
     * @throws ConfigurationException when a section value is invalid or a declared security setting is not wired.
     */
    public static function fromConfigurationArray(#[\SensitiveParameter] array $configuration): Application
    {
        return ApplicationBuilder::buildFromConfigurationArray($configuration);
    }

    /**
     * @throws ConfigurationException when the file is missing, unreadable or invalid, or a declared security setting is not wired.
     */
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
     *
     * @throws ConfigurationException as fromConfigDirectory().
     */
    public static function fromEnvironment(): Application
    {
        $dir = trim(EnvironmentVariable::read('APP_CONFIG_DIR') ?? '');
        $base = trim(EnvironmentVariable::read('APP_CONFIG_BASE') ?? '');
        $extra = trim(EnvironmentVariable::read('APP_CONFIG_EXTRA') ?? '');

        $configDir = $dir !== '' ? $dir : rtrim((string) getcwd(), '/\\') . '/config';
        $baseName = $base !== '' ? $base : 'app';
        $environment = EnvironmentVariable::read('APP_ENV');

        $extraOptionalNames = [];
        if ($extra !== '') {
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
     * A null $environment falls back to APP_ENV; pass an empty string to disable
     * environment-specific optional loading.
     *
     * @throws ConfigurationException on an empty or invalid directory, base or extra name, when the files cannot be
     *                                loaded, or when a declared security setting is not wired.
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
     * @throws ConfigurationException on an empty required path or a malformed optional entry, when the files cannot
     *                                be loaded, or when a declared security setting is not wired.
     */
    public static function fromResolvedPaths(array $paths): Application
    {
        $required = $paths['required'] ?? '';
        if (!is_string($required) || trim($required) === '') {
            throw ConfigurationException::invalidPath('Resolved config paths must include a non-empty "required" entry.');
        }

        $optional = $paths['optional'] ?? [];
        if (!is_array($optional)) {
            throw ConfigurationException::invalidPath('Resolved config "optional" entry must be an array of file paths.');
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
     * @throws ConfigurationException on an empty directory or base name, or a name containing a path separator.
     */
    public static function configPathsForDirectory(
        string $configDir,
        string $baseName = 'app',
        ?string $environment = null,
        array $extraOptionalNames = [],
    ): array {
        $configDir = trim($configDir);
        if ($configDir === '') {
            throw ConfigurationException::invalidPath('Config directory must not be empty.');
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
            $resolvedEnvironment = EnvironmentVariable::read('APP_ENV') ?? '';
        }

        $resolvedEnvironment = self::normalizeEnvironmentName($resolvedEnvironment);
        if ($resolvedEnvironment !== '') {
            $optional[] = $configDir . '/' . $baseName . '.' . $resolvedEnvironment . '.php';
        }

        $optional = self::uniqueStrings($optional);

        return [
            'required' => $required,
            'optional' => $optional,
        ];
    }

    /**
     * Trims the environment name and rejects path separators. APP_ENV comes from outside the file, so it is checked
     * as strictly as the base name. An empty value disables environment-specific loading.
     */
    private static function normalizeEnvironmentName(string $environment): string
    {
        $environment = trim($environment);

        if ($environment === '') {
            return '';
        }

        if (str_contains($environment, '/') || str_contains($environment, '\\')) {
            throw ConfigurationException::invalidPath('Config environment name must not contain path separators.');
        }

        return $environment;
    }

    private static function normalizeBaseName(string $baseName): string
    {
        $baseName = trim($baseName);
        if ($baseName === '') {
            throw ConfigurationException::invalidPath('Config base name must not be empty.');
        }

        if (str_contains($baseName, '/') || str_contains($baseName, '\\')) {
            throw ConfigurationException::invalidPath('Config base name must not contain path separators.');
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
                throw ConfigurationException::invalidPath(sprintf('Optional config name at index %d must be a string.', $index));
            }

            $trimmed = trim($name);
            if ($trimmed === '') {
                throw ConfigurationException::invalidPath(sprintf('Optional config name at index %d must not be empty.', $index));
            }

            if (str_contains($trimmed, '/') || str_contains($trimmed, '\\')) {
                throw ConfigurationException::invalidPath(sprintf('Optional config name at index %d must not contain path separators.', $index));
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
                throw ConfigurationException::invalidPath(sprintf('Resolved optional path at index %d must be a string.', $index));
            }

            $trimmed = trim($path);
            if ($trimmed === '') {
                throw ConfigurationException::invalidPath(sprintf('Resolved optional path at index %d must not be empty.', $index));
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
