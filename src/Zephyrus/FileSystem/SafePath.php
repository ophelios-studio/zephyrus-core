<?php

declare(strict_types=1);

namespace Zephyrus\FileSystem;

/**
 * Resolves a caller-supplied relative path against a trusted root and refuses
 * anything that escapes it.
 *
 * Containment needs two checks: a lexical refusal of `..` segments and null
 * bytes, and a `realpath()` comparison against the root so that a symbolic link
 * inside the root cannot point outside it.
 *
 * @internal
 */
final class SafePath
{
    private const string STREAM_WRAPPER_URL = '~^(?:[a-zA-Z0-9+.-]{2,}://|data:)~';

    /**
     * Resolve `$relativePath` under `$root`.
     *
     * @return string|null The canonical absolute path, or null when the path
     *                     escapes the root, is unreadable, or does not exist,
     *                     or when the root holds a NUL byte.
     */
    public static function within(string $root, string $relativePath): ?string
    {
        if (!self::isSafeRelativePath($relativePath) || self::holdsNulByte($root)) {
            return null;
        }

        $realRoot = @realpath(rtrim($root, '/\\'));
        if ($realRoot === false) {
            return null;
        }

        $realPath = @realpath(rtrim($root, '/\\') . '/' . ltrim($relativePath, '/\\'));
        if ($realPath === false || !@is_readable($realPath)) {
            return null;
        }

        $realRoot = rtrim($realRoot, DIRECTORY_SEPARATOR);

        return str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR) ? $realPath : null;
    }

    /**
     * Whether `$path` is a stream wrapper URL (`scheme://`, `data:`) rather than a local path.
     */
    public static function isStreamWrapperUrl(string $path): bool
    {
        return preg_match(self::STREAM_WRAPPER_URL, $path) === 1;
    }

    /**
     * The scheme of a stream wrapper URL as written (`phar://`, `data:`), or null for a local path.
     */
    public static function streamWrapperScheme(string $path): ?string
    {
        return preg_match(self::STREAM_WRAPPER_URL, $path, $match) === 1 ? $match[0] : null;
    }

    /**
     * Whether `$path` holds a NUL byte, which no file name can.
     */
    public static function holdsNulByte(string $path): bool
    {
        return str_contains($path, "\0");
    }

    /**
     * Whether `$path` is a regular file; a path holding a NUL byte is not one.
     */
    public static function isFile(string $path): bool
    {
        return !self::holdsNulByte($path) && @is_file($path);
    }

    /**
     * Whether `$path` is a directory; a path holding a NUL byte is not one.
     */
    public static function isDirectory(string $path): bool
    {
        return !self::holdsNulByte($path) && @is_dir($path);
    }

    /**
     * Whether `$path` is readable; a path holding a NUL byte is not.
     */
    public static function isReadable(string $path): bool
    {
        return !self::holdsNulByte($path) && @is_readable($path);
    }

    /**
     * Whether a relative path is free of null bytes and of `..` segments.
     */
    public static function isSafeRelativePath(string $relativePath): bool
    {
        if ($relativePath === '' || str_contains($relativePath, "\0")) {
            return false;
        }

        foreach ((array) preg_split('#[/\\\\]+#', $relativePath) as $segment) {
            if ((string) $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
