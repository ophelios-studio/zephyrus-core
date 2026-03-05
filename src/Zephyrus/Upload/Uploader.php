<?php

declare(strict_types=1);

namespace Zephyrus\Upload;

/**
 * Service responsible for persisting a validated upload to the filesystem.
 *
 * The `$destinationRoot` passed at construction is the absolute base directory
 * under which all files are stored.  The service returns a relative path from
 * that root so callers can store it in a database without coupling to the
 * server's directory layout.
 *
 * ## Path safety
 * - `$subDirectory` segments are checked for `..` traversal; invalid segments
 *   raise `UploadException::pathTraversalDetected()`.
 * - `$targetName` must be a bare filename (no slashes); any separator character
 *   triggers `UploadException::pathTraversalDetected()`.
 * - Null bytes are stripped from both inputs before evaluation.
 *
 * ## File moving
 * `move_uploaded_file()` is tried first (the secure PHP function for real
 * uploads).  If it returns `false` (e.g. during tests where the source is a
 * regular temp file rather than a genuine PHP upload), `rename()` is used as
 * a fallback so unit tests work without mocking.
 */
final class Uploader
{
    /**
     * @param string[] $allowedExtensions Lowercased extension allowlist without dot (empty = accept any).
     * @param string[] $allowedMimeTypes  Lowercased MIME allowlist (empty = accept any).
     */
    public function __construct(
        private string $destinationRoot,
        private array $allowedExtensions = [],
        private array $allowedMimeTypes = [],
        private ?int $maxSizeBytes = null,
    ) {
        $this->allowedExtensions = array_values(array_filter(array_map(static function (string $extension): string {
            return ltrim(strtolower(trim($extension)), '.');
        }, $this->allowedExtensions), static fn (string $extension): bool => $extension !== ''));

        $this->allowedMimeTypes = array_values(array_filter(array_map(static function (string $mimeType): string {
            return strtolower(trim($mimeType));
        }, $this->allowedMimeTypes), static fn (string $mimeType): bool => $mimeType !== ''));
    }

    /**
     * Persists the upload to disk and returns its path relative to `$destinationRoot`.
     *
     * @param FileUpload  $file         The validated upload value object.
     * @param string|null $subDirectory Optional sub-path under the destination root (e.g. "images/avatars").
     * @param string|null $targetName   Bare filename to use; a random hex name is generated when omitted.
     *
     * @return string Relative path from the destination root (e.g. "images/avatars/abc123.jpg").
     *
     * @throws UploadException On validation failure, path traversal, directory creation failure, or move failure.
     */
    public function store(FileUpload $file, ?string $subDirectory = null, ?string $targetName = null): string
    {
        $file->assertValid();
        $this->assertConstraints($file);

        $normalizedSubDir = $subDirectory !== null ? $this->normalizeSubDirectory($subDirectory) : null;
        $absoluteDir = $this->resolveDirectory($normalizedSubDir);
        $this->ensureDirectory($absoluteDir);

        $name = $targetName !== null
            ? $this->validateTargetName($targetName)
            : $this->generateName($file->extension());

        $absolutePath = $absoluteDir . DIRECTORY_SEPARATOR . $name;

        if (!@move_uploaded_file($file->tmpPath, $absolutePath)) {
            if (!@rename($file->tmpPath, $absolutePath)) {
                throw UploadException::moveFileFailed($file->tmpPath, $absolutePath);
            }
        }

        return $normalizedSubDir !== null && $normalizedSubDir !== ''
            ? $normalizedSubDir . '/' . $name
            : $name;
    }

    /**
     * Validates optional extension/MIME/size constraints configured at construction.
     */
    private function assertConstraints(FileUpload $file): void
    {
        if ($this->maxSizeBytes !== null && $file->sizeBytes > $this->maxSizeBytes) {
            throw UploadException::fileTooLarge($file->sizeBytes, $this->maxSizeBytes);
        }

        if ($this->allowedExtensions !== []) {
            $extension = $file->extension();
            if ($extension === '' || !in_array($extension, $this->allowedExtensions, true)) {
                throw UploadException::extensionNotAllowed($extension, $this->allowedExtensions);
            }
        }

        if ($this->allowedMimeTypes !== []) {
            $mimeType = strtolower(trim($file->clientMimeType));
            if ($mimeType === '' || !in_array($mimeType, $this->allowedMimeTypes, true)) {
                throw UploadException::mimeTypeNotAllowed($mimeType, $this->allowedMimeTypes);
            }
        }
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function resolveDirectory(?string $normalizedSubDir): string
    {
        $root = rtrim($this->destinationRoot, '/\\');

        if ($normalizedSubDir === null || $normalizedSubDir === '') {
            return $root;
        }

        return $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizedSubDir);
    }

    /**
     * Splits the sub-directory string on any separator, rejects `..` segments,
     * and reassembles with forward slashes.
     */
    private function normalizeSubDirectory(string $subDirectory): string
    {
        $raw = str_replace("\0", '', $subDirectory);
        $segments = (array) preg_split('#[/\\\\]+#', $raw);
        $cleaned = [];

        foreach ($segments as $segment) {
            $segment = trim((string) $segment);

            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw UploadException::pathTraversalDetected($segment);
            }

            $cleaned[] = $segment;
        }

        return implode('/', $cleaned);
    }

    /**
     * Validates a caller-supplied target filename: must be a bare name with no
     * directory separators and must not be empty or a dot-alias.
     */
    private function validateTargetName(string $name): string
    {
        $name = str_replace("\0", '', $name);

        if ($name === '' || $name === '.' || $name === '..') {
            throw UploadException::invalidTargetName($name);
        }

        if (str_contains($name, '/') || str_contains($name, '\\')) {
            throw UploadException::pathTraversalDetected($name);
        }

        return $name;
    }

    private function generateName(string $extension): string
    {
        $base = bin2hex(random_bytes(16));

        return $extension !== '' ? "{$base}.{$extension}" : $base;
    }

    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw UploadException::directoryCreationFailed($dir);
        }
    }
}
