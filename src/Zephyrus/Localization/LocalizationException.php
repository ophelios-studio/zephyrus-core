<?php

declare(strict_types=1);

namespace Zephyrus\Localization;

use Zephyrus\Exceptions\ZephyrusRuntimeException;

/**
 * Thrown when locale file loading or parsing fails.
 */
final class LocalizationException extends ZephyrusRuntimeException
{
    public static function unreadableFile(string $path): self
    {
        return new self(sprintf('Unable to read locale file "%s".', $path));
    }

    public static function invalidJson(string $path, ?\Throwable $previous = null): self
    {
        $message = sprintf('Invalid JSON in locale file "%s"', $path);
        if ($previous !== null) {
            $message .= ': ' . $previous->getMessage();
        }
        return new self($message, previous: $previous);
    }

    public static function invalidFormat(string $path): self
    {
        return new self(sprintf('Locale file "%s" must decode to an object.', $path));
    }
}
