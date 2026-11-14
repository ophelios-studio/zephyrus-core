<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Cache;

/** A throwaway directory for boot cache tests, removed with everything under it. */
final class CacheDirectory
{
    public readonly string $path;

    public function __construct()
    {
        $path = sys_get_temp_dir() . '/zephyrus-cache-' . bin2hex(random_bytes(8));
        mkdir($path);
        chmod($path, 0o755);
        $this->path = (string) realpath($path);
    }

    public function file(string $relativePath, string $contents, int $mode = 0o644): string
    {
        $this->directory(dirname($relativePath));
        $path = $this->path . '/' . $relativePath;
        if (is_file($path)) {
            chmod($path, 0o644);
        }
        file_put_contents($path, $contents);
        chmod($path, $mode);

        return $path;
    }

    public function directory(string $relativePath, int $mode = 0o755): string
    {
        $path = $this->path;
        foreach (explode('/', $relativePath) as $segment) {
            if ($segment === '.' || $segment === '') {
                continue;
            }

            $path .= '/' . $segment;
            if (!is_dir($path)) {
                mkdir($path);
            }
            chmod($path, 0o755);
        }
        chmod($path, $mode);

        return $path;
    }

    /** @return list<string> The entries of a directory, recursively, relative to this directory. */
    public function entries(string $relativePath = ''): array
    {
        $entries = [];
        $directory = rtrim($this->path . '/' . $relativePath, '/');
        foreach (array_diff((array) scandir($directory), ['.', '..']) as $entry) {
            $relative = ltrim($relativePath . '/' . $entry, '/');
            $entries[] = $relative;
            if (is_dir($directory . '/' . $entry) && !is_link($directory . '/' . $entry)) {
                array_push($entries, ...$this->entries($relative));
            }
        }

        return $entries;
    }

    public function remove(): void
    {
        self::removeTree($this->path);
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        chmod($path, 0o755);
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            self::removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }
}
