<?php

declare(strict_types=1);

namespace Zephyrus\FileSystem;

/**
 * Wraps PHP's native filesystem calls and turns their warnings into FileSystemException.
 *
 * @internal
 */
final class NativeFileSystem
{
    /**
     * Create `$directory` and its parents unless it already exists.
     *
     * @throws FileSystemException if the directory cannot be created.
     */
    public static function ensureDirectory(
        string $directory,
        int $permissions,
        string $operation,
        string $subject,
    ): void {
        if (@is_dir($directory)) {
            return;
        }

        error_clear_last();
        $created = @mkdir($directory, $permissions, true);
        $reason = FileSystemException::lastError();
        if (!$created && !@is_dir($directory)) {
            throw FileSystemException::operationFailed($operation, $subject, reason: $reason);
        }
    }
}
