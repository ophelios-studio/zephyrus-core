<?php

declare(strict_types=1);

namespace Zephyrus\FileSystem;

/**
 * Resolves a caller-supplied relative path against a trusted root and refuses
 * anything that escapes it.
 *
 * Stripping leading separators is not a containment check: it stops
 * `/etc/passwd` from becoming an absolute path but does nothing about
 * `../../etc/passwd`. Containment needs two things, and this helper does both:
 *
 * 1. A lexical refusal of `..` segments and null bytes, so a traversal never
 *    reaches the filesystem at all.
 * 2. A `realpath()` comparison against the root, so a symbolic link inside the
 *    root cannot point outside it.
 *
 * @internal Shared by the rendering engines and the asset manager.
 */
final class SafePath
{
    /**
     * Resolve `$relativePath` under `$root`.
     *
     * @return string|null The canonical absolute path, or null when the path
     *                     escapes the root, is unreadable, or does not exist.
     */
    public static function within(string $root, string $relativePath): ?string
    {
        if (!self::isSafeRelativePath($relativePath)) {
            return null;
        }

        $realRoot = realpath(rtrim($root, '/\\'));
        if ($realRoot === false) {
            return null;
        }

        $realPath = realpath(rtrim($root, '/\\') . '/' . ltrim($relativePath, '/\\'));
        if ($realPath === false || !is_readable($realPath)) {
            return null;
        }

        $realRoot = rtrim($realRoot, DIRECTORY_SEPARATOR);

        return str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR) ? $realPath : null;
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
