<?php

declare(strict_types=1);

namespace Zephyrus\FileSystem;

use Zephyrus\Exceptions\ZephyrusRuntimeException;

/**
 * Thrown when a filesystem operation fails.
 */
final class FileSystemException extends ZephyrusRuntimeException
{
    public static function notFound(string $path): self
    {
        return new self(sprintf('Path not found: %s', $path));
    }

    public static function notReadable(string $path): self
    {
        return new self(sprintf('Path is not readable: %s', $path));
    }

    public static function notWritable(string $path): self
    {
        return new self(sprintf('Path is not writable: %s', $path));
    }

    public static function operationFailed(string $operation, string $path, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('Failed to %s: %s', $operation, $path),
            previous: $previous,
        );
    }

    public static function alreadyExists(string $path): self
    {
        return new self(sprintf('Path already exists: %s', $path));
    }
}
