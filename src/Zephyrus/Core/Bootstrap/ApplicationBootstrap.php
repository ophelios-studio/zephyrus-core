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
        $existingOptional = array_values(array_filter(
            $optionalConfigFiles,
            static fn (string $path): bool => is_file($path),
        ));

        $allPaths = array_merge($requiredConfigFiles, $existingOptional);

        return $allPaths !== []
            ? ApplicationBuilder::buildFromConfigurationFiles($allPaths)
            : ApplicationBuilder::create()->build();
    }
}
