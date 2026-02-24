<?php

declare(strict_types=1);

namespace Zephyrus\Upload;

/**
 * Immutable value object representing a single file submitted via an HTTP multipart upload.
 *
 * Wraps the raw PHP `$_FILES` entry shape and exposes typed accessors.
 * Use `fromPhpArray()` to build from a single `$_FILES['field']` entry.
 */
final readonly class FileUpload
{
    public function __construct(
        public string $originalName,
        public string $clientMimeType,
        public string $tmpPath,
        public int $sizeBytes,
        public int $errorCode = UPLOAD_ERR_OK,
    ) {
    }

    /**
     * Builds a FileUpload from a single PHP `$_FILES` entry (i.e. `$_FILES['avatar']`).
     *
     * @param array{name?: mixed, type?: mixed, tmp_name?: mixed, error?: mixed, size?: mixed} $entry
     *
     * @throws UploadException When the array is missing the required `tmp_name` or `error` keys.
     */
    public static function fromPhpArray(array $entry): self
    {
        if (!array_key_exists('tmp_name', $entry) || !array_key_exists('error', $entry)) {
            throw UploadException::invalidArrayShape();
        }

        return new self(
            originalName: (string) ($entry['name'] ?? ''),
            clientMimeType: (string) ($entry['type'] ?? ''),
            tmpPath: (string) $entry['tmp_name'],
            sizeBytes: (int) ($entry['size'] ?? 0),
            errorCode: (int) $entry['error'],
        );
    }

    /**
     * Returns the lowercased file extension derived from the client-supplied original name.
     * Returns an empty string when no extension is present.
     */
    public function extension(): string
    {
        $ext = pathinfo($this->originalName, PATHINFO_EXTENSION);

        return is_string($ext) ? strtolower($ext) : '';
    }

    /**
     * Returns true when the PHP upload pipeline reported no error.
     */
    public function isValid(): bool
    {
        return $this->errorCode === UPLOAD_ERR_OK;
    }

    /**
     * Asserts that the upload completed without error.
     *
     * @throws UploadException With a human-readable description of the PHP upload error code.
     */
    public function assertValid(): void
    {
        if (!$this->isValid()) {
            throw UploadException::uploadFailed($this->errorCode);
        }
    }
}
