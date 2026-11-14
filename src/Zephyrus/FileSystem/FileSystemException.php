<?php

declare(strict_types=1);

namespace Zephyrus\FileSystem;

use Zephyrus\Exceptions\MessageValue;
use Zephyrus\Exceptions\ZephyrusRuntimeException;

/**
 * Thrown when a filesystem operation fails; messages show paths quoted and escaped.
 */
final class FileSystemException extends ZephyrusRuntimeException
{
    private const int MAX_PATH_BYTES = 512;
    private const int MAX_REASON_BYTES = 256;

    public static function notFound(string $path): self
    {
        return new self(sprintf('Path not found: %s', self::quotePath($path)));
    }

    public static function notReadable(string $path): self
    {
        return new self(sprintf('Path is not readable: %s', self::quotePath($path)));
    }

    public static function notWritable(string $path): self
    {
        return new self(sprintf('Path is not writable: %s', self::quotePath($path)));
    }

    public static function operationFailed(
        string $operation,
        string $path,
        ?\Throwable $previous = null,
        ?string $reason = null,
    ): self {
        return new self(
            $reason === null
                ? sprintf('Failed to %s: %s', $operation, self::quotePath($path))
                : sprintf(
                    'Failed to %s: %s (%s)',
                    $operation,
                    self::quotePath($path),
                    MessageValue::quote($reason, self::MAX_REASON_BYTES, keepEnd: true),
                ),
            previous: $previous,
        );
    }

    public static function transferFailed(
        string $operation,
        string $source,
        string $destination,
        ?string $reason = null,
    ): self {
        $message = sprintf(
            'Failed to %s %s to %s',
            $operation,
            self::quotePath($source),
            self::quotePath($destination),
        );

        return new self(
            $reason === null
                ? $message
                : sprintf(
                    '%s (%s)',
                    $message,
                    MessageValue::quote($reason, self::MAX_REASON_BYTES, keepEnd: true),
                ),
        );
    }

    public static function notAFileName(string $path): self
    {
        return new self(sprintf('Path does not end with a file name: %s', self::quotePath($path)));
    }

    public static function nulByteRefused(string $path): self
    {
        return new self(sprintf('Path holds a NUL byte, which no file name can: %s', self::quotePath($path)));
    }

    public static function streamWrapperRefused(string $path): self
    {
        return new self(sprintf('Path is a stream wrapper URL, which is not written: %s', self::quotePath($path)));
    }

    public static function symbolicLinkRefused(string $path): self
    {
        return new self(sprintf('Path is a symbolic link, which is not followed: %s', self::quotePath($path)));
    }

    public static function notARegularFile(string $path): self
    {
        return new self(sprintf('Path is not a regular file: %s', self::quotePath($path)));
    }

    public static function notReadableForSafeWrite(string $path): self
    {
        return new self(sprintf('Path is not readable, which a safe write requires: %s', self::quotePath($path)));
    }

    public static function belongsToAnotherUserInSharedDirectory(string $path): self
    {
        return new self(sprintf(
            'Path belongs to another user in a shared directory, so it is not written: %s',
            self::quotePath($path),
        ));
    }

    public static function copyOntoItself(string $source, string $destination): self
    {
        return new self(sprintf(
            'Cannot copy a file onto itself: %s to %s',
            self::quotePath($source),
            self::quotePath($destination),
        ));
    }

    public static function alreadyExists(string $path): self
    {
        return new self(sprintf('Path already exists: %s', self::quotePath($path)));
    }

    private static function quotePath(string $path): string
    {
        return MessageValue::quote($path, self::MAX_PATH_BYTES, keepEnd: true);
    }
}
