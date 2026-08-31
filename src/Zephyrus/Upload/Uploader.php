<?php

declare(strict_types=1);

namespace Zephyrus\Upload;

use Closure;
use finfo;

/**
 * Service responsible for persisting a validated upload to the filesystem.
 *
 * The `$destinationRoot` passed at construction is the absolute base directory
 * under which all files are stored.  The service returns a relative path from
 * that root so callers can store it in a database without coupling to the
 * server's directory layout.
 *
 * ## What is trusted, and what is not
 * Everything the browser sends is attacker-controlled: the original filename,
 * its extension, the declared MIME type and the declared size.  None of them is
 * used to decide whether a file is acceptable.
 *
 * - The MIME allowlist is matched against the type **sniffed from the bytes**
 *   on disk (`finfo`), never against `$_FILES['x']['type']`.
 * - The size limit is matched against `filesize()` of the temporary file, never
 *   against the declared size.
 * - The extension allowlist is matched against **every** dotted segment of the
 *   client filename, so `avatar.php.jpg` does not slip past an allowlist of
 *   `['jpg']`.
 * - The stored extension is derived from the sniffed type, so a PHP payload
 *   announced as a JPEG can never land with a `.php` name.
 * - An explicit `$targetName` is subjected to the same extension allowlist and
 *   may not be a dotfile.
 *
 * ## Path safety
 * - `$subDirectory` segments are checked for `..` traversal; invalid segments
 *   raise `UploadException::pathTraversalDetected()`.
 * - `$targetName` must be a bare filename (no slashes); any separator character
 *   triggers `UploadException::pathTraversalDetected()`.
 * - Null bytes are stripped and both inputs are trimmed before evaluation.
 * - The resolved destination directory must still sit under `$destinationRoot`
 *   once `realpath()` has collapsed symbolic links, so a sub-directory that
 *   traverses a pre-existing symlink cannot escape the root.
 * - An existing destination file is never silently replaced unless the caller
 *   opts in with `$overwriteExisting`.
 *
 * ## File moving
 * The default mover requires a genuine PHP upload (`is_uploaded_file()`) and
 * then uses `move_uploaded_file()`.  There is deliberately no fallback: the one
 * reason `move_uploaded_file()` fails is that the source is not a registered
 * upload, so falling back would defeat the exact check it performs.  Tests and
 * other non-HTTP callers inject their own `$fileMover` instead.
 */
final class Uploader
{
    /**
     * Sniffed MIME type to the extension the stored file receives.
     *
     * Types that are dangerous to serve back (text/html, text/x-php,
     * application/x-httpd-php, shell scripts, ...) are deliberately absent, so
     * they can never contribute a stored extension.
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
     * Persists multiple uploads in order and returns their relative paths.
     *
     * Each file is validated and stored via `store()`, sharing the same optional
     * `$subDirectory`.  Processing stops immediately on the first failure and the
     * same `UploadException` is re-thrown, leaving previously stored files in place.
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
     * Validates optional extension/MIME/size constraints configured at construction.
     *
     * Size and MIME are judged on the bytes actually present in the temporary
     * file; only the extension allowlist looks at the client-supplied name, and
     * it looks at every dotted segment of it.
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

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

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
     * The real size on disk, which is the only size the client cannot lie about.
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
     * Every dotted segment of a filename must be allowlisted, otherwise
     * `avatar.php.jpg` passes an allowlist that only permits `jpg`.
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
     * Confirms the deepest part of the destination path that already exists is
     * contained, BEFORE anything is created.
     *
     * Without this, a sub-directory whose first segment is a pre-existing
     * symlink out of the root would have `mkdir()` follow the link and create
     * the remaining segments outside, even though the upload itself is then
     * refused by {@see self::assertContainedDirectory()}.
     */
    private function assertContainedAncestor(string $absoluteDir): void
    {
        $probe = $absoluteDir;

        while (!file_exists($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                return; // Walked past the filesystem root; nothing exists to check.
            }
            $probe = $parent;
        }

        $realRoot = realpath(rtrim($this->destinationRoot, '/\\'));
        if ($realRoot === false) {
            return; // The root itself is not created yet; the post-check covers it.
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
     * Validates a caller-supplied target filename: must be a bare, trimmed name
     * with no directory separators, must not be empty, a dot-alias or a dotfile,
     * and must satisfy the extension allowlist just like a generated name.
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

        // A leading dot makes the file a configuration dotfile such as
        // ".htaccess", which never belongs in an upload destination.
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
     * An upload whose name carries no extension keeps none. Otherwise the
     * sniffed type decides. When the bytes cannot be mapped to a known type the
     * client extension is used only if the application declared a closed
     * extension allowlist that contains it, which the upload has already been
     * validated against; failing that, the file is stored without an extension.
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
