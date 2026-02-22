<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

use Zephyrus\Exceptions\ZephyrusException;

/**
 * Thrown when a configuration section receives an invalid or missing required value.
 *
 * Use the named factory methods for consistent, contextual messages.
 */
final class ConfigurationException extends ZephyrusException
{
    public static function missingRequired(string $section, string $field): self
    {
        return new self(
            sprintf("Configuration section '%s' requires field '%s' but none was provided.", $section, $field),
        );
    }

    public static function invalidValue(string $section, string $field, mixed $value, string $reason): self
    {
        return new self(
            sprintf(
                "Configuration section '%s' field '%s' has invalid value '%s': %s.",
                $section,
                $field,
                $value,
                $reason,
            ),
        );
    }
}
