<?php

declare(strict_types=1);

namespace Zephyrus\Core\Cache;

/**
 * Lexical checks on absolute paths and namespaces, which never read the filesystem.
 *
 * @internal
 */
final class PathPrefix
{
    /**
     * Whether the path lies strictly below the base: "/app/var" is below "/app", "/application" is not.
     *
     * @param string $separator "/" for paths, "\\" for namespaces.
     */
    public static function isUnder(string $path, string $base, string $separator = '/'): bool
    {
        $prefix = rtrim($base, $separator) . $separator;

        return strlen($path) > strlen($prefix) && str_starts_with($path, $prefix);
    }

    /**
     * The absolute path with its empty, "." and ".." segments resolved.
     */
    public static function withoutDotSegments(string $absolutePath): string
    {
        $segments = [];
        foreach (explode('/', $absolutePath) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }

        return '/' . implode('/', $segments);
    }
}
