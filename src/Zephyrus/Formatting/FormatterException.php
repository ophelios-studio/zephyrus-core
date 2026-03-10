<?php

declare(strict_types=1);

namespace Zephyrus\Formatting;

use Zephyrus\Exceptions\ZephyrusRuntimeException;

/**
 * Thrown when a formatting operation fails.
 */
class FormatterException extends ZephyrusRuntimeException
{
    public static function formattingFailed(string $type, string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('Formatting [%s] failed: %s', $type, $reason),
            previous: $previous,
        );
    }

    public static function invalidLocale(string $locale): self
    {
        return new self(sprintf('Invalid locale: %s', $locale));
    }

    public static function unknownFormatter(string $name): self
    {
        return new self(sprintf('Unknown custom formatter: %s', $name));
    }
}
