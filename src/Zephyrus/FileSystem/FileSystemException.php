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

    /**
     * The function and the reason of the error raised since the last error_clear_last(), as
     * `rename(): No such file or directory`, without the call's arguments that PHP may print in front of
     * the reason. A message of another shape gives no reason (null), so the arguments never leak. Call
     * error_clear_last() right before the silenced call whose failure is being explained.
     *
     * @internal
     */
    public static function lastError(): ?string
    {
        $message = error_get_last()['message'] ?? null;
        if ($message === null) {
            return null;
        }

        $prefix = '^([A-Za-z_][A-Za-z0-9_]*)\(.*\)(?: \[<a href=\'.*\'>[^<]*<\/a>\])?: ';
        $reasons = [
            '(open_basedir restriction in effect\. .*File\(.*\) is not within the allowed path\(s\): \(.*\))$',
            '(.*)$',
        ];
        foreach ($reasons as $reason) {
            if (preg_match('/' . $prefix . $reason . '/s', $message, $parts) === 1) {
                return $parts[1] . '(): ' . $parts[2];
            }
        }

        return null;
    }

    /**
     * Refuse `$operation` on a path that an open_basedir restriction hides, which no check can tell from an absent one.
     *
     * @internal
     *
     * @throws self if `$path` lies outside the restriction.
     */
    public static function assertNotRestricted(string $operation, string $path): void
    {
        if (in_array(ini_get('open_basedir'), ['', false], true)) {
            return;
        }

        set_error_handler(static fn (): bool => false);
        try {
            if (@is_file(dirname($path))) {
                return;
            }
            error_clear_last();
            $message = @is_link($path) ? '' : (error_get_last()['message'] ?? '');
            $probe = '/^is_link\(\)(?: \[<a href=\'[^\']*\'>[^<]*<\/a>\])?: open_basedir restriction in effect\. /';
            if (preg_match($probe, $message) === 1) {
                throw self::operationFailed($operation, $path, reason: self::lastError());
            }
            error_clear_last();
        } finally {
            restore_error_handler();
        }
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
