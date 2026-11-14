<?php

declare(strict_types=1);

namespace Zephyrus\FileSystem;

/**
 * Object-oriented wrapper for file operations.
 *
 *   $file = new File('/path/to/file.txt');
 *   $file->write('new content');
 *   $copy = $file->copy('/path/to/copy.txt');
 *
 * ## Permissions
 * A file created by this class gets {@see self::DEFAULT_PERMISSIONS} masked by the
 * process umask: the mode is never wider than the umask allows. PHP sets a mode only
 * by path: the path is checked to still name the new file just before, which leaves
 * a window between that check and the chmod.
 *
 * ## Symbolic links
 * create(), write() and copy() replace a symbolic link at the destination with a
 * regular file instead of following it. They write only through a handle to a file
 * they created, or to the regular file the path names once opened, so a path swapped
 * for a link during the call is refused rather than followed. append() still follows
 * the link.
 */
final class File extends FileSystemNode
{
    /** Upper bound of the mode of a file this class creates (the umask can only narrow it). */
    public const int DEFAULT_PERMISSIONS = 0644;

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

        self::replaceContent($path, $content, 'create file');

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
        self::replaceContent($this->path, $content, 'write to');
    }

    /**
     * Append content to the file.
     *
     * @throws FileSystemException if appending fails.
     */
    public function append(string $content): void
    {
        $existed = is_file($this->path);

        if (file_put_contents($this->path, $content, FILE_APPEND) === false) {
            throw FileSystemException::operationFailed('append to', $this->path);
        }

        if (!$existed && !@chmod($this->path, self::DEFAULT_PERMISSIONS & ~umask())) {
            throw FileSystemException::operationFailed('set permissions on', $this->path);
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
     * A new copy never has a wider mode than the source, {@see self::DEFAULT_PERMISSIONS}
     * or the umask allow; an existing destination keeps its permissions.
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

        $failure = fn (): FileSystemException
            => FileSystemException::operationFailed('copy', $this->path . ' to ' . $destination);
        $source = @fopen($this->path, 'r');
        $sourceStat = $source === false ? false : fstat($source);
        if ($source === false || $sourceStat === false) {
            throw $failure();
        }

        try {
            [$handle, $created] = self::openRegularFile($destination, $failure);
            try {
                $destinationStat = fstat($handle);
                if ($destinationStat === false || self::isSameFile($sourceStat, $destinationStat)) {
                    throw $failure();
                }
                if ($created) {
                    self::applyDefaultPermissions($destination, $handle, $sourceStat['mode']);
                }
                if (!ftruncate($handle, 0) || stream_copy_to_stream($source, $handle) === false) {
                    throw $failure();
                }
            } finally {
                fclose($handle);
            }
        } finally {
            fclose($source);
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
            return;
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

    /**
     * @throws FileSystemException
     */
    private static function replaceContent(string $path, string $content, string $operation): void
    {
        $failure = fn (): FileSystemException => FileSystemException::operationFailed($operation, $path);
        [$handle, $created] = self::openRegularFile($path, $failure);
        try {
            if ($created) {
                self::applyDefaultPermissions($path, $handle);
            }
            if (!ftruncate($handle, 0) || fwrite($handle, $content) !== strlen($content)) {
                throw $failure();
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Open `$path` for writing without following a symbolic link: a link already there is
     * replaced, an absent path is created exclusively, and an existing one is kept only if
     * the path still names the regular file the handle opened. Nothing is written here.
     *
     * @param \Closure(): FileSystemException $failure
     * @return array{resource, bool} The handle, and whether this call created the file.
     * @throws FileSystemException
     */
    private static function openRegularFile(string $path, \Closure $failure): array
    {
        clearstatcache(true, $path);
        if (is_link($path) && !@unlink($path)) {
            throw FileSystemException::operationFailed('replace the symbolic link at', $path);
        }

        for ($attempt = 1; ; $attempt++) {
            $exists = self::isOccupied($path);
            // 'x' fails on any existing path, a dangling link included; 'r+' never creates.
            $handle = @fopen($path, $exists ? 'r+' : 'x');
            if ($handle !== false) {
                break;
            }
            if ($attempt === 2 || self::isOccupied($path) === $exists) {
                throw $failure();
            }
        }

        if ($exists && !self::namesHandle($path, $handle)) {
            fclose($handle);
            throw $failure();
        }

        return [$handle, !$exists];
    }

    private static function isOccupied(string $path): bool
    {
        clearstatcache(true, $path);
        return is_link($path) || file_exists($path);
    }

    /**
     * Whether `$path`, its final link not followed, is the regular file open in `$handle`.
     *
     * @param resource $handle
     */
    private static function namesHandle(string $path, $handle): bool
    {
        clearstatcache(true, $path);
        $named = @lstat($path);
        $open = fstat($handle);

        return $named !== false && $open !== false
            && ($named['mode'] & 0o170000) === 0o100000
            && self::isSameFile($named, $open);
    }

    /**
     * @param array{dev: int, ino: int} $first
     * @param array{dev: int, ino: int} $second
     */
    private static function isSameFile(array $first, array $second): bool
    {
        return $first['dev'] === $second['dev'] && $first['ino'] === $second['ino'];
    }

    /**
     * @param resource $handle The file this call created at `$path`.
     * @throws FileSystemException if the path no longer names that file, or chmod fails.
     */
    private static function applyDefaultPermissions(string $path, $handle, int $sourceMode = self::DEFAULT_PERMISSIONS): void
    {
        if (!self::namesHandle($path, $handle) || !@chmod($path, $sourceMode & self::DEFAULT_PERMISSIONS & ~umask())) {
            throw FileSystemException::operationFailed('set permissions on', $path);
        }
    }
}
