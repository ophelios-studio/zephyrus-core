<?php

declare(strict_types=1);

namespace Zephyrus\Upload;

use Closure;
use finfo;

/**
 * Persists a validated upload under a destination root.
 *
 * Returns paths relative to that root, so callers need not know the server layout.
 *
 * Every browser-supplied value (filename, extension, declared MIME type and size)
 * is untrusted and never decides acceptance on its own:
 * - the MIME allowlist is matched against the type sniffed from the bytes;
 * - the size limit is matched against the real size of the temporary file;
 * - the extension allowlist is matched against every dotted segment of the name;
 * - the stored extension comes from the sniffed type, so a PHP payload announced
 *   as a JPEG never lands with a `.php` name;
 * - an explicit `$targetName` is subject to the same extension allowlist and may
 *   not be a dotfile.
 *
 * `..` segments are refused, null bytes are stripped, and the destination must
 * stay under the root after `realpath()`. An existing file is replaced only with
 * `$overwriteExisting`.
 *
 * The default mover accepts only genuine HTTP uploads and has no fallback.
 * Non-HTTP callers and tests inject their own `$fileMover`.
 */
final class Uploader
{
    /**
     * Sniffed MIME type to the extension the stored file receives.
     *
     * Types dangerous to serve back (HTML, PHP, shell scripts) are absent on purpose.
     */
    private const array MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/bmp' => 'bmp',
        'image/tiff' => 'tiff',
        'image/svg+xml' => 'svg',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'text/markdown' => 'md',
        'application/json' => 'json',
        'application/xml' => 'xml',
        'text/xml' => 'xml',
        'application/zip' => 'zip',
        'application/gzip' => 'gz',
        'application/x-tar' => 'tar',
        'audio/mpeg' => 'mp3',
        'audio/ogg' => 'ogg',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/quicktime' => 'mov',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.oasis.opendocument.text' => 'odt',
        'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
        'font/woff' => 'woff',
        'font/woff2' => 'woff2',
        'font/ttf' => 'ttf',
        'font/otf' => 'otf',
    ];

    /** @var array<string, string> Sniffed MIME type to stored extension. */
    private array $mimeExtensions;

    /** @var Closure(string, string): bool Moves a source path to a destination path. */
    private Closure $fileMover;

    /**
     * @param string                $destinationRoot   Absolute base directory for stored files.
     * @param string[]              $allowedExtensions Lowercased extension allowlist without dot (empty = accept any).
     * @param string[]              $allowedMimeTypes  Lowercased sniffed-MIME allowlist (empty = accept any).
     * @param int|null              $maxSizeBytes      Maximum real size on disk, null for no limit.
     * @param Closure|null          $fileMover         Move strategy `fn(string $source, string $destination): bool`.
     *                                                 Defaults to a genuine-upload-only `move_uploaded_file()`.
     *                                                 Non-HTTP callers and tests inject their own.
     * @param array<string, string> $mimeExtensionMap  Extra or overriding sniffed-MIME to extension mappings.
     * @param bool                  $overwriteExisting Whether an existing destination file may be replaced.
     */
    public function __construct(
        private string $destinationRoot,
        private array $allowedExtensions = [],
        private array $allowedMimeTypes = [],
        private ?int $maxSizeBytes = null,
        ?Closure $fileMover = null,
        array $mimeExtensionMap = [],
        private bool $overwriteExisting = false,
    ) {
        $this->allowedExtensions = array_values(array_filter(array_map(static function (string $extension): string {
            return ltrim(strtolower(trim($extension)), '.');
        }, $this->allowedExtensions), static fn (string $extension): bool => $extension !== ''));

        $this->allowedMimeTypes = array_values(array_filter(array_map(static function (string $mimeType): string {
            return strtolower(trim($mimeType));
        }, $this->allowedMimeTypes), static fn (string $mimeType): bool => $mimeType !== ''));

        $normalizedMap = [];
        foreach ($mimeExtensionMap as $mimeType => $extension) {
            $normalizedMap[strtolower(trim($mimeType))] = ltrim(strtolower(trim($extension)), '.');
        }
        $this->mimeExtensions = array_replace(self::MIME_EXTENSIONS, $normalizedMap);

        $this->fileMover = $fileMover ?? static function (string $source, string $destination): bool {
            if (!is_uploaded_file($source)) {
                throw UploadException::notAnUploadedFile($source);
            }

            return move_uploaded_file($source, $destination);
        };
    }

    /**
     * Stores the uploads in order and returns their relative paths.
     *
     * Stops at the first failure, leaving earlier files in place.
     *
     * @param list<FileUpload> $files       Uploads to persist.
     * @param string|null      $subDirectory Optional sub-path applied to every file.
     *
     * @return list<string> Relative paths in the same order as `$files`.
     *
     * @throws UploadException On the first validation or move failure.
     */
    public function storeMany(array $files, ?string $subDirectory = null): array
    {
        $paths = [];

        foreach ($files as $file) {
            $paths[] = $this->store($file, $subDirectory);
        }

        return $paths;
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
        $sniffedMimeType = $this->sniffMimeType($file);
        $this->assertConstraints($file, $sniffedMimeType);

        $normalizedSubDir = $subDirectory !== null ? $this->normalizeSubDirectory($subDirectory) : null;
        $absoluteDir = $this->resolveDirectory($normalizedSubDir);
        $this->assertContainedAncestor($absoluteDir);
        $this->ensureDirectory($absoluteDir);
        $absoluteDir = $this->assertContainedDirectory($absoluteDir);

        $name = $targetName !== null
            ? $this->validateTargetName($targetName)
            : $this->generateName($file, $sniffedMimeType);

        $absolutePath = $absoluteDir . DIRECTORY_SEPARATOR . $name;

        if (!$this->overwriteExisting && file_exists($absolutePath)) {
            throw UploadException::destinationAlreadyExists($absolutePath);
        }

        if (!($this->fileMover)($file->tmpPath, $absolutePath)) {
            throw UploadException::moveFileFailed($file->tmpPath, $absolutePath);
        }

        return $normalizedSubDir !== null && $normalizedSubDir !== ''
            ? $normalizedSubDir . '/' . $name
            : $name;
    }

    /**
     * Applies the size, extension and MIME constraints.
     */
    private function assertConstraints(FileUpload $file, string $sniffedMimeType): void
    {
        if ($this->maxSizeBytes !== null) {
            $actualSize = $this->actualSize($file);
            if ($actualSize > $this->maxSizeBytes) {
                throw UploadException::fileTooLarge($actualSize, $this->maxSizeBytes);
            }
        }

        if ($this->allowedExtensions !== []) {
            $this->assertExtensionsAllowed($file->extensions());
        }

        if ($this->allowedMimeTypes !== [] && !in_array($sniffedMimeType, $this->allowedMimeTypes, true)) {
            throw UploadException::mimeTypeNotAllowed($sniffedMimeType, $this->allowedMimeTypes);
        }
    }

    /**
     * Reads the real MIME type from the bytes of the temporary file.
     *
     * @throws UploadException When the temporary file is missing, unreadable, or cannot be identified.
     */
    private function sniffMimeType(FileUpload $file): string
    {
        if ($file->tmpPath === '' || !is_file($file->tmpPath) || !is_readable($file->tmpPath)) {
            throw UploadException::unreadableSource($file->tmpPath);
        }

        $sniffed = (new finfo(FILEINFO_MIME_TYPE))->file($file->tmpPath);
        if ($sniffed === false) {
            throw UploadException::mimeTypeSniffFailed($file->tmpPath);
        }

        return strtolower(trim($sniffed));
    }

    /**
     * The real size of the temporary file.
     */
    private function actualSize(FileUpload $file): int
    {
        $size = @filesize($file->tmpPath);
        if ($size === false) {
            throw UploadException::unreadableSource($file->tmpPath);
        }

        return $size;
    }

    /**
     * Every dotted segment of a filename must be allowlisted.
     *
     * @param list<string> $extensions
     */
    private function assertExtensionsAllowed(array $extensions): void
    {
        if ($extensions === []) {
            throw UploadException::extensionNotAllowed('', $this->allowedExtensions);
        }

        foreach ($extensions as $extension) {
            if (!in_array($extension, $this->allowedExtensions, true)) {
                throw UploadException::extensionNotAllowed($extension, $this->allowedExtensions);
            }
        }
    }

    private function resolveDirectory(?string $normalizedSubDir): string
    {
        $root = rtrim($this->destinationRoot, '/\\');

        if ($normalizedSubDir === null || $normalizedSubDir === '') {
            return $root;
        }

        return $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalizedSubDir);
    }

    /**
     * Checks the deepest existing ancestor of the destination before anything is created,
     * so that `mkdir()` cannot follow a symlink out of the root.
     */
    private function assertContainedAncestor(string $absoluteDir): void
    {
        $probe = $absoluteDir;

        while (!file_exists($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                return;
            }
            $probe = $parent;
        }

        $realRoot = realpath(rtrim($this->destinationRoot, '/\\'));
        if ($realRoot === false) {
            return;
        }

        $realProbe = realpath($probe);
        $realRoot = rtrim($realRoot, DIRECTORY_SEPARATOR);

        if ($realProbe === false
            || ($realProbe !== $realRoot && !str_starts_with($realProbe, $realRoot . DIRECTORY_SEPARATOR))) {
            throw UploadException::destinationNotContained($absoluteDir);
        }
    }

    /**
     * Confirms the destination directory still lives under the destination root
     * once symbolic links are collapsed, and returns its canonical path.
     */
    private function assertContainedDirectory(string $absoluteDir): string
    {
        $realRoot = realpath(rtrim($this->destinationRoot, '/\\'));
        $realDir = realpath($absoluteDir);

        if ($realRoot === false || $realDir === false) {
            throw UploadException::destinationNotContained($absoluteDir);
        }

        $realRoot = rtrim($realRoot, DIRECTORY_SEPARATOR);

        if ($realDir !== $realRoot && !str_starts_with($realDir, $realRoot . DIRECTORY_SEPARATOR)) {
            throw UploadException::destinationNotContained($absoluteDir);
        }

        return $realDir;
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
     * Validates a caller-supplied target filename: a bare name, not a dot-alias or dotfile,
     * that satisfies the extension allowlist.
     */
    private function validateTargetName(string $name): string
    {
        $name = trim(str_replace("\0", '', $name));

        if ($name === '' || $name === '.' || $name === '..') {
            throw UploadException::invalidTargetName($name);
        }

        if (str_contains($name, '/') || str_contains($name, '\\')) {
            throw UploadException::pathTraversalDetected($name);
        }

        if (str_starts_with($name, '.')) {
            throw UploadException::invalidTargetName($name);
        }

        if ($this->allowedExtensions !== []) {
            $this->assertExtensionsAllowed(self::extensionSegments($name));
        }

        return $name;
    }

    /**
     * Builds the stored filename: a random 128-bit stem plus an extension taken
     * from the sniffed type, never from the client-supplied name.
     */
    private function generateName(FileUpload $file, string $sniffedMimeType): string
    {
        $base = bin2hex(random_bytes(16));
        $extension = $this->storedExtension($file, $sniffedMimeType);

        return $extension !== '' ? "{$base}.{$extension}" : $base;
    }

    /**
     * The extension the stored file receives.
     *
     * The sniffed type decides. An unmapped type falls back to the client
     * extension only when the allowlist contains it, otherwise the file has none.
     */
    private function storedExtension(FileUpload $file, string $sniffedMimeType): string
    {
        $clientExtensions = $file->extensions();
        if ($clientExtensions === []) {
            return '';
        }

        $mapped = $this->mimeExtensions[$sniffedMimeType] ?? null;
        if ($mapped !== null && $mapped !== '') {
            return $mapped;
        }

        $last = $clientExtensions[array_key_last($clientExtensions)];

        return $this->allowedExtensions !== [] && in_array($last, $this->allowedExtensions, true) ? $last : '';
    }

    /**
     * Every dotted segment of a bare filename, lowercased.
     *
     * @return list<string>
     */
    private static function extensionSegments(string $name): array
    {
        $parts = explode('.', $name);
        array_shift($parts);

        return array_values(array_filter(
            array_map(static fn (string $part): string => strtolower($part), $parts),
            static fn (string $part): bool => $part !== '',
        ));
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
