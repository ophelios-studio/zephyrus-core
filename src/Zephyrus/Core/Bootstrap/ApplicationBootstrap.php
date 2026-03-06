<?php

declare(strict_types=1);

namespace Zephyrus\Core\Bootstrap;

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
     * Build an application from a conventional config directory layout.
     *
     * Required file:   <configDir>/<baseName>.php
     * Optional files:  <configDir>/<baseName>.local.php
     *                  <configDir>/<baseName>.<APP_ENV>.php (when APP_ENV is set)
     */
    public static function fromConfigDirectory(string $configDir, string $baseName = 'app'): Application
    {
        $configDir = rtrim($configDir, '/\\');
        $baseFile = $configDir . '/' . $baseName . '.php';

        $optional = [
            $configDir . '/' . $baseName . '.local.php',
        ];

        $appEnv = getenv('APP_ENV');
        if (is_string($appEnv) && trim($appEnv) !== '') {
            $optional[] = $configDir . '/' . $baseName . '.' . trim($appEnv) . '.php';
        }

        return self::fromConfigFiles([$baseFile], $optional);
    }
}
