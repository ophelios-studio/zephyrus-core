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

    public static function notAFileName(string $path): self
    {
        return new self(sprintf('Path does not end with a file name: %s', $path));
    }

    public static function symbolicLinkRefused(string $path): self
    {
        return new self(sprintf('Path is a symbolic link, which is not followed: %s', $path));
    }

    public static function notARegularFile(string $path): self
    {
        return new self(sprintf('Path is not a regular file: %s', $path));
    }

    public static function notReadableForSafeWrite(string $path): self
    {
        return new self(sprintf('Path is not readable, which a safe write requires: %s', $path));
    }

    public static function belongsToAnotherUserInSharedDirectory(string $path): self
    {
        return new self(sprintf('Path belongs to another user in a shared directory, so it is not written: %s', $path));
    }

    public static function copyOntoItself(string $source, string $destination): self
    {
        return new self(sprintf('Cannot copy a file onto itself: %s to %s', $source, $destination));
    }

    public static function alreadyExists(string $path): self
    {
        return new self(sprintf('Path already exists: %s', $path));
    }
}
