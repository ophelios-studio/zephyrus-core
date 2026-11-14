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
 * by path, so it is set on the temporary file before that file is put in place.
 *
 * ## Symbolic links
 * A new file is never opened by its final path: it is written as a temporary file in
 * the same directory, then renamed over the path (create(), write(), copy()) or hard
 * linked to it (append(), which fails on any existing path; renamed where no hard link
 * can be made, and on a thread-safe build, whose link() follows a link at the path).
 * An existing regular file is written in place through a handle, kept only if the path
 * still names it once opened. A symbolic link at the path, there before or swapped in
 * during the call, is therefore replaced (refused by append()), never followed. This
 * holds for the last segment of the path: a link among its parent directories is
 * followed.
 *
 * ## Shared directories
 * An existing file in a sticky directory such as /tmp is refused when it belongs to neither
 * the directory owner nor the effective user, the Linux fs.protected_regular rule, which
 * applies here even where the system does not enforce it (fs.protected_regular off).
 */
final class File extends FileSystemNode
{
    /** Upper bound of the mode of a file this class creates (the umask can only narrow it). */
    public const int DEFAULT_PERMISSIONS = 0644;

    private const int FILE_TYPE_MASK = 0o170000;
    private const int REGULAR_FILE_TYPE = 0o100000;
    private const int SYMBOLIC_LINK_TYPE = 0o120000;
    private const int STICKY_BIT = 0o1000;
    private const int PERMISSION_BITS = 0o777;
    private const string TEMPORARY_PREFIX = '.zephyrus-';

    private static ?int $createdFileOwner = null;

    /**
     * Create a new file with optional initial content.
     *
     * @throws FileSystemException if the path is a stream wrapper URL or does not end with a file
     *         name, or creation fails.
     */
    public static function create(string $path, string $content = ''): self
    {
        self::assertPlainFilePath($path);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw FileSystemException::operationFailed('create directory for', $path);
            }
        }

        self::replaceContent($path, self::contentWriter($content), self::failure('create file', $path));

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
     * @throws FileSystemException if the path is a stream wrapper URL or does not end with a file
     *         name, or writing fails.
     */
    public function write(string $content): void
    {
        self::assertPlainFilePath($this->path);
        self::replaceContent($this->path, self::contentWriter($content), self::failure('write to', $this->path));
    }

    /**
     * Append content to the file, creating it when absent.
     *
     * Each append holds an exclusive flock() on the file: concurrent append() calls never
     * overwrite each other, but they can overwrite and lose lines of a writer that does not
     * take that lock (file_put_contents() with FILE_APPEND, error_log(), a default Monolog
     * StreamHandler).
     * On a thread-safe PHP build or a filesystem without hard links, two calls that create
     * the file at the same instant may leave only one of their contents. The lock is awaited
     * without limit, so any process that can open the file (one readable by other users
     * included) can hold append() back.
     *
     * @throws FileSystemException if the path is a stream wrapper URL, does not end with a file
     *         name, is a symbolic link, or appending fails.
     */
    public function append(string $content): void
    {
        self::assertPlainFilePath($this->path);
        self::appendContent($this->path, $content);
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
     * @throws FileSystemException if the source is not a readable regular file, the destination
     *         is a stream wrapper URL, does not end with a file name or is the source itself, or copying fails.
     */
    public function copy(string $destination): self
    {
        self::assertPlainFilePath($destination);
        $this->assertExists();
        if (!is_file($this->path)) {
            throw FileSystemException::notARegularFile($this->path);
        }
        $source = @fopen($this->path, 'r');
        if ($source === false) {
            throw FileSystemException::notReadable($this->path);
        }

        try {
            $sourceStat = fstat($source);
            if ($sourceStat === false || !self::isOfType($sourceStat, self::REGULAR_FILE_TYPE)) {
                throw FileSystemException::notARegularFile($this->path);
            }

            $dir = dirname($destination);
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw FileSystemException::operationFailed('create directory for', $destination);
                }
            }

            self::replaceContent(
                $destination,
                static function ($handle) use ($source): bool {
                    error_clear_last();

                    return @stream_copy_to_stream($source, $handle) !== false;
                },
                self::failure('copy', $this->path . ' to ' . $destination),
                $sourceStat['mode'],
                function (array $existing) use ($sourceStat, $destination): void {
                    if (self::isSameFile($sourceStat, $existing)) {
                        throw FileSystemException::copyOntoItself($this->path, $destination);
                    }
                },
            );
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
     * A stream wrapper URL is never written, and a path whose last segment is empty, "." or
     * ".." names a directory, never a file.
     *
     * @throws FileSystemException
     */
    private static function assertPlainFilePath(string $path): void
    {
        if (preg_match('~^(?:[a-zA-Z0-9+.-]{2,}://|data:)~', $path) === 1) {
            throw FileSystemException::streamWrapperRefused($path);
        }
        if (preg_match('~(?:^|/)\.{0,2}$~D', $path) === 1) {
            throw FileSystemException::notAFileName($path);
        }
    }

    /**
     * @return \Closure(resource): bool
     */
    private static function contentWriter(string $content): \Closure
    {
        return static function ($handle) use ($content): bool {
            error_clear_last();

            return @fwrite($handle, $content) === strlen($content);
        };
    }

    /**
     * @return \Closure(?string=): FileSystemException
     */
    private static function failure(string $operation, string $path): \Closure
    {
        return static fn (?string $reason = null): FileSystemException
            => FileSystemException::operationFailed($operation, $path, reason: $reason);
    }

    /**
     * The message of the error raised since the last error_clear_last(): call that right
     * before the silenced call whose failure is being explained.
     */
    private static function lastError(): ?string
    {
        return error_get_last()['message'] ?? null;
    }

    /**
     * Replace the content of `$path` without following a symbolic link at it.
     *
     * @param \Closure(resource): bool $write Writes the new content into an empty file.
     * @param \Closure(?string=): FileSystemException $failure
     * @param (\Closure(array{dev: int, ino: int}): void)|null $refuseExisting Throws to leave an existing file alone.
     * @throws FileSystemException
     */
    private static function replaceContent(
        string $path,
        \Closure $write,
        \Closure $failure,
        int $mode = self::DEFAULT_PERMISSIONS,
        ?\Closure $refuseExisting = null,
    ): void {
        $handle = self::openInPlace($path, $failure, refuseLink: false);
        if ($handle === null) {
            self::createThroughTemporary($path, $write, $failure, replace: true, mode: $mode);
            return;
        }

        try {
            $existing = fstat($handle);
            if ($existing === false) {
                throw $failure();
            }
            if ($refuseExisting !== null) {
                $refuseExisting($existing);
            }
            error_clear_last();
            if (!ftruncate($handle, 0) || !$write($handle)) {
                throw $failure(self::lastError());
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @throws FileSystemException
     */
    private static function appendContent(string $path, string $content): void
    {
        $failure = self::failure('append to', $path);
        $handle = self::openInPlace($path, $failure, refuseLink: true);
        if ($handle === null) {
            $writer = self::contentWriter($content);
            // A thread-safe build resolves the new path of link(), following a link planted there.
            if (!PHP_ZTS && self::createThroughTemporary($path, $writer, $failure, replace: false)) {
                return;
            }
            $handle = self::openInPlace($path, $failure, refuseLink: true);
            if ($handle === null) {
                self::createThroughTemporary($path, $writer, $failure, replace: true);
                return;
            }
        }

        try {
            if (!self::appendUnderLock($handle, $content)) {
                throw $failure(self::lastError());
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     */
    private static function appendUnderLock($handle, string $content): bool
    {
        error_clear_last();
        if (!flock($handle, LOCK_EX)) {
            return false;
        }
        try {
            return fseek($handle, 0, SEEK_END) === 0
                && @fwrite($handle, $content) === strlen($content)
                && fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
        }
    }

    /**
     * Open the regular file `$path` names for reading and writing, never creating it: null
     * when the path is absent or is a symbolic link to replace.
     *
     * @param \Closure(?string=): FileSystemException $failure
     * @return resource|null
     * @throws FileSystemException
     */
    private static function openInPlace(string $path, \Closure $failure, bool $refuseLink)
    {
        for ($attempt = 1; ; $attempt++) {
            clearstatcache(true, $path);
            $named = @lstat($path);
            if ($named === false) {
                return null;
            }
            if (self::isOfType($named, self::SYMBOLIC_LINK_TYPE)) {
                if ($refuseLink) {
                    throw FileSystemException::symbolicLinkRefused($path);
                }
                return null;
            }
            if (!self::isOfType($named, self::REGULAR_FILE_TYPE)) {
                throw FileSystemException::notARegularFile($path);
            }

            error_clear_last();
            $handle = @fopen($path, 'r+');
            if ($handle !== false) {
                break;
            }
            $reason = self::lastError();
            clearstatcache(true, $path);
            $now = @lstat($path);
            if ($attempt === 2 || ($now !== false && self::isSameFile($named, $now))) {
                throw is_readable($path) ? $failure($reason) : FileSystemException::notReadableForSafeWrite($path);
            }
        }

        try {
            if (!self::namesHandle($path, $handle)) {
                throw $failure();
            }
            self::refuseAnotherUsersFileInStickyDirectory($path, $handle, $failure);
        } catch (FileSystemException $e) {
            fclose($handle);
            throw $e;
        }

        return $handle;
    }

    /**
     * Refuse what fs.protected_regular refuses to an open that may create the file, which an
     * open in place escapes: a file in a sticky directory owned by neither its owner nor us.
     *
     * @param resource $handle
     * @param \Closure(?string=): FileSystemException $failure
     * @throws FileSystemException
     */
    private static function refuseAnotherUsersFileInStickyDirectory(string $path, $handle, \Closure $failure): void
    {
        $file = fstat($handle);
        $directory = @stat(dirname($path));
        if ($file === false || $directory === false) {
            throw $failure();
        }
        if (($directory['mode'] & self::STICKY_BIT) === 0 || $file['uid'] === $directory['uid']) {
            return;
        }
        if ($file['uid'] !== self::effectiveUserId()) {
            throw FileSystemException::belongsToAnotherUserInSharedDirectory($path);
        }
    }

    /**
     * The effective user id, null when it cannot be known. Without ext-posix it is the owner
     * of a file just created, the id the kernel compares.
     */
    private static function effectiveUserId(): ?int
    {
        if (function_exists('posix_geteuid')) {
            return posix_geteuid();
        }
        if (self::$createdFileOwner === null) {
            $handle = @tmpfile();
            $stat = $handle === false ? false : fstat($handle);
            if ($handle !== false) {
                fclose($handle);
            }
            self::$createdFileOwner = $stat === false ? null : $stat['uid'];
        }

        return self::$createdFileOwner;
    }

    /**
     * Write a new file through a temporary file in the directory of `$path`, then put it in
     * place: rename() replaces whatever the path names without following it, link() (when
     * `$replace` is false) fails on any existing path. The temporary file never outlives the
     * call.
     *
     * @param \Closure(resource): bool $write
     * @param \Closure(?string=): FileSystemException $failure
     * @return bool False when link() failed.
     * @throws FileSystemException
     */
    private static function createThroughTemporary(
        string $path,
        \Closure $write,
        \Closure $failure,
        bool $replace,
        int $mode = self::DEFAULT_PERMISSIONS,
    ): bool {
        $directory = dirname($path);
        error_clear_last();
        $temporary = @tempnam($directory, self::TEMPORARY_PREFIX);
        if ($temporary === false) {
            throw $failure(self::lastError());
        }

        $temporaryLeft = true;
        try {
            // tempnam() falls back to the system directory when this one is unusable.
            if (dirname($temporary) !== realpath($directory)) {
                throw $failure();
            }
            error_clear_last();
            $handle = @fopen($temporary, 'r+');
            if ($handle === false) {
                throw $failure(self::lastError());
            }
            try {
                if (!self::namesHandle($temporary, $handle)) {
                    throw $failure();
                }
                if (!self::applyPermissions($temporary, $handle, $mode)) {
                    throw FileSystemException::operationFailed('set permissions on', $path);
                }
                if (!$write($handle)) {
                    throw $failure(self::lastError());
                }
            } finally {
                fclose($handle);
            }

            if (!$replace) {
                return @link($temporary, $path);
            }
            error_clear_last();
            if (!@rename($temporary, $path)) {
                throw $failure(self::lastError());
            }
            $temporaryLeft = false;

            return true;
        } finally {
            if ($temporaryLeft) {
                @unlink($temporary);
            }
        }
    }

    /**
     * Give the file open in `$handle`, which `$path` names, the mode `$mode` narrowed by
     * {@see self::DEFAULT_PERMISSIONS} and the umask; no chmod when it already has it.
     *
     * @param resource $handle
     */
    private static function applyPermissions(string $path, $handle, int $mode): bool
    {
        $wanted = $mode & self::DEFAULT_PERMISSIONS & ~umask();
        $current = fstat($handle);
        if ($current !== false && ($current['mode'] & self::PERMISSION_BITS) === $wanted) {
            return true;
        }

        return self::namesHandle($path, $handle) && @chmod($path, $wanted);
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
            && self::isOfType($named, self::REGULAR_FILE_TYPE)
            && self::isSameFile($named, $open);
    }

    /**
     * @param array{mode: int} $stat
     */
    private static function isOfType(array $stat, int $type): bool
    {
        return ($stat['mode'] & self::FILE_TYPE_MASK) === $type;
    }

    /**
     * @param array{dev: int, ino: int} $first
     * @param array{dev: int, ino: int} $second
     */
    private static function isSameFile(array $first, array $second): bool
    {
        return $first['dev'] === $second['dev'] && $first['ino'] === $second['ino'];
    }
}
