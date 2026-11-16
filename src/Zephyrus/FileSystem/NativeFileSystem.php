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
        $reason = self::lastError();
        if (!$created && !@is_dir($directory)) {
            throw FileSystemException::operationFailed($operation, $subject, reason: $reason);
        }
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
     * @throws FileSystemException if `$path` lies outside the restriction.
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
                throw FileSystemException::operationFailed($operation, $path, reason: self::lastError());
            }
            error_clear_last();
        } finally {
            restore_error_handler();
        }
    }
}
