<?php

declare(strict_types=1);

namespace Zephyrus\FileSystem;

/**
 * Abstract base for filesystem nodes (files and directories).
 *
 * Provides common metadata accessors for any filesystem entry.
 */
abstract class FileSystemNode
{
    public function __construct(
        protected readonly string $path,
    ) {
    }

    /**
     * Get the absolute path.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Check whether this node exists on disk.
     */
    public function exists(): bool
    {
        return file_exists($this->path);
    }

    /**
     * Get the basename (filename or directory name).
     */
    public function name(): string
    {
        return basename($this->path);
    }

    /**
     * Get the parent directory path.
     */
    public function parent(): string
    {
        return dirname($this->path);
    }

    /**
     * Get the file permissions as an octal integer.
     *
     * @throws FileSystemException if the path does not exist.
     */
    public function permissions(): int
    {
        $this->assertExists();
        return fileperms($this->path) & 0o7777;
    }

    /**
     * Get the last modification timestamp.
     *
     * @throws FileSystemException if the path does not exist.
     */
    public function lastModified(): int
    {
        $this->assertExists();
        $mtime = filemtime($this->path);
        if ($mtime === false) {
            throw FileSystemException::operationFailed('read modification time of', $this->path);
        }
        return $mtime;
    }

    /**
     * Check whether the node is readable.
     */
    public function isReadable(): bool
    {
        return is_readable($this->path);
    }

    /**
     * Check whether the node is writable.
     */
    public function isWritable(): bool
    {
        return is_writable($this->path);
    }

    /**
     * @throws FileSystemException if the path does not exist.
     */
    protected function assertExists(): void
    {
        if (!$this->exists()) {
            throw FileSystemException::notFound($this->path);
        }
    }
}
