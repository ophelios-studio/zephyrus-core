<?php

declare(strict_types=1);

namespace Zephyrus\FileSystem;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Object-oriented wrapper for directory operations.
 *
 *   $dir = new Directory('/path/to/dir');
 *   $files = $dir->files('*.php');
 */
final class Directory extends FileSystemNode
{
    /**
     * Ensure a directory exists, creating it if necessary.
     *
     * @return self The directory instance.
     * @throws FileSystemException if creation fails.
     */
    public static function ensure(string $path, int $permissions = 0755): self
    {
        if (!is_dir($path)) {
            if (!@mkdir($path, $permissions, true) && !is_dir($path)) {
                throw FileSystemException::operationFailed('create directory', $path);
            }
        }

        return new self($path);
    }

    /**
     * List files in this directory matching a glob pattern.
     *
     * @param string $pattern Glob pattern (default '*' = all files).
     * @return File[]
     * @throws FileSystemException if the directory does not exist.
     */
    public function files(string $pattern = '*'): array
    {
        $this->assertExists();

        $matches = glob($this->path . '/' . $pattern);
        if ($matches === false) {
            return [];
        }

        $files = [];
        foreach ($matches as $match) {
            if (is_file($match)) {
                $files[] = new File($match);
            }
        }

        return $files;
    }

    /**
     * List immediate subdirectories.
     *
     * @return Directory[]
     * @throws FileSystemException if the directory does not exist.
     */
    public function directories(): array
    {
        $this->assertExists();

        $dirs = [];
        $iterator = new FilesystemIterator($this->path, FilesystemIterator::SKIP_DOTS);

        foreach ($iterator as $item) {
            if ($item instanceof SplFileInfo && $item->isDir()) {
                $dirs[] = new self($item->getPathname());
            }
        }

        return $dirs;
    }

    /**
     * Find files matching a glob pattern (non-recursive).
     *
     * @return string[] Matching file paths.
     */
    public function glob(string $pattern): array
    {
        $this->assertExists();

        $matches = glob($this->path . '/' . $pattern);
        return $matches !== false ? $matches : [];
    }

    /**
     * Find files matching a glob pattern recursively.
     *
     * @return string[] Matching file paths.
     */
    public function recursiveGlob(string $pattern): array
    {
        $this->assertExists();

        $results = [];
        $this->recursiveGlobInternal($this->path, $pattern, $results);
        return $results;
    }

    /**
     * Create the directory.
     *
     * @throws FileSystemException if creation fails.
     */
    public function create(int $permissions = 0755): void
    {
        if (is_dir($this->path)) {
            return;
        }

        if (!@mkdir($this->path, $permissions, true) && !is_dir($this->path)) {
            throw FileSystemException::operationFailed('create directory', $this->path);
        }
    }

    /**
     * Delete the directory.
     *
     * A recursive delete throws on the first entry it cannot remove, so a
     * partial purge is never reported as a success.
     *
     * @param bool $recursive If true, delete all contents first.
     * @throws FileSystemException if any file or directory could not be removed.
     */
    public function delete(bool $recursive = false): void
    {
        if (!$this->exists()) {
            return;
        }

        if ($recursive) {
            $this->deleteRecursive($this->path);
            return;
        }

        if (!@rmdir($this->path)) {
            throw FileSystemException::operationFailed('delete directory', $this->path);
        }
    }

    /**
     * Compute the total size of all files in this directory (recursive).
     *
     * @return int Total size in bytes.
     * @throws FileSystemException if the directory does not exist.
     */
    public function size(): int
    {
        $this->assertExists();

        $total = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $total += $item->getSize();
            }
        }

        return $total;
    }

    /**
     * Check whether the directory is empty.
     *
     * @throws FileSystemException if the directory does not exist.
     */
    public function isEmpty(): bool
    {
        $this->assertExists();

        $iterator = new FilesystemIterator($this->path, FilesystemIterator::SKIP_DOTS);
        return !$iterator->valid();
    }

    /**
     * Recursively delete a directory and all its contents.
     *
     * @throws FileSystemException naming the first entry that could not be removed.
     */
    private function deleteRecursive(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();

            // isDir() follows links: a link is unlinked, never rmdir()ed, so its target survives.
            $removed = $item->isDir() && !$item->isLink() ? @rmdir($path) : @unlink($path);

            if (!$removed) {
                throw FileSystemException::operationFailed('delete', $path);
            }
        }

        if (!@rmdir($dir)) {
            throw FileSystemException::operationFailed('delete directory', $dir);
        }
    }

    /**
     * Internal recursive glob implementation.
     *
     * @param string[] $results Collected results (by reference).
     */
    private function recursiveGlobInternal(string $dir, string $pattern, array &$results): void
    {
        $matches = glob($dir . '/' . $pattern);
        if ($matches !== false) {
            $results = array_merge($results, $matches);
        }

        $subdirs = glob($dir . '/*', GLOB_ONLYDIR);
        if ($subdirs !== false) {
            foreach ($subdirs as $subdir) {
                $this->recursiveGlobInternal($subdir, $pattern, $results);
            }
        }
    }
}
