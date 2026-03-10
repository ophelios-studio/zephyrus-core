<?php

declare(strict_types=1);

namespace Zephyrus\FileSystem;

/**
 * Object-oriented wrapper for file operations.
 *
 * Provides a clean API for reading, writing, copying, moving, and
 * inspecting individual files.
 *
 * Usage:
 *
 *   $file = new File('/path/to/file.txt');
 *   $content = $file->read();
 *   $file->write('new content');
 *   $file->append("\nmore content");
 *   $copy = $file->copy('/path/to/copy.txt');
 *   $file->delete();
 */
final class File extends FileSystemNode
{
    /**
     * Create a new file with optional initial content.
     *
     * @throws FileSystemException if creation fails.
     */
    public static function create(string $path, string $content = ''): self
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw FileSystemException::operationFailed('create directory for', $path);
            }
        }

        if (file_put_contents($path, $content) === false) {
            throw FileSystemException::operationFailed('create file', $path);
        }

        return new self($path);
    }

    /**
     * Read the entire file contents.
     *
     * @throws FileSystemException if the file cannot be read.
     */
    public function read(): string
    {
        $this->assertExists();

        $content = file_get_contents($this->path);
        if ($content === false) {
            throw FileSystemException::notReadable($this->path);
        }

        return $content;
    }

    /**
     * Overwrite the file with new content.
     *
     * @throws FileSystemException if writing fails.
     */
    public function write(string $content): void
    {
        if (file_put_contents($this->path, $content) === false) {
            throw FileSystemException::operationFailed('write to', $this->path);
        }
    }

    /**
     * Append content to the file.
     *
     * @throws FileSystemException if appending fails.
     */
    public function append(string $content): void
    {
        if (file_put_contents($this->path, $content, FILE_APPEND) === false) {
            throw FileSystemException::operationFailed('append to', $this->path);
        }
    }

    /**
     * Get the file size in bytes.
     *
     * @throws FileSystemException if the file does not exist.
     */
    public function size(): int
    {
        $this->assertExists();

        $size = filesize($this->path);
        if ($size === false) {
            throw FileSystemException::operationFailed('read size of', $this->path);
        }

        return $size;
    }

    /**
     * Get the MIME type of the file.
     *
     * @throws FileSystemException if the file does not exist.
     */
    public function mimeType(): string
    {
        $this->assertExists();

        $mime = @mime_content_type($this->path);
        return $mime !== false ? $mime : 'application/octet-stream';
    }

    /**
     * Get the file extension (without leading dot).
     */
    public function extension(): string
    {
        return pathinfo($this->path, PATHINFO_EXTENSION);
    }

    /**
     * Compute a hash of the file contents.
     */
    public function hash(string $algo = 'sha256'): string
    {
        $this->assertExists();

        $hash = hash_file($algo, $this->path);
        if ($hash === false) {
            throw FileSystemException::operationFailed('hash', $this->path);
        }

        return $hash;
    }

    /**
     * Copy the file to a new destination.
     *
     * @return File The new file at the destination path.
     * @throws FileSystemException if copying fails.
     */
    public function copy(string $destination): self
    {
        $this->assertExists();

        $dir = dirname($destination);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw FileSystemException::operationFailed('create directory for', $destination);
            }
        }

        if (!@copy($this->path, $destination)) {
            throw FileSystemException::operationFailed('copy', $this->path . ' to ' . $destination);
        }

        return new self($destination);
    }

    /**
     * Move (rename) the file to a new location.
     *
     * @return File The file at the new location.
     * @throws FileSystemException if moving fails.
     */
    public function move(string $destination): self
    {
        $this->assertExists();

        $dir = dirname($destination);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw FileSystemException::operationFailed('create directory for', $destination);
            }
        }

        if (!@rename($this->path, $destination)) {
            throw FileSystemException::operationFailed('move', $this->path . ' to ' . $destination);
        }

        return new self($destination);
    }

    /**
     * Delete the file.
     *
     * @throws FileSystemException if deletion fails.
     */
    public function delete(): void
    {
        if (!$this->exists()) {
            return; // Already gone — idempotent.
        }

        if (!@unlink($this->path)) {
            throw FileSystemException::operationFailed('delete', $this->path);
        }
    }

    /**
     * Read the file as an array of lines.
     *
     * @param bool $trimNewlines Whether to strip trailing newlines from each line.
     * @return string[]
     * @throws FileSystemException if the file cannot be read.
     */
    public function lines(bool $trimNewlines = true): array
    {
        $this->assertExists();

        $flags = FILE_IGNORE_NEW_LINES;
        if (!$trimNewlines) {
            $flags = 0;
        }

        $lines = file($this->path, $flags);
        if ($lines === false) {
            throw FileSystemException::notReadable($this->path);
        }

        return $lines;
    }
}
