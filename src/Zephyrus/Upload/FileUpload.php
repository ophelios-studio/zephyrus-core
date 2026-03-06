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
    /**
     * Builds one-or-many FileUpload instances from a PHP $_FILES field entry.
     *
     * Supports both single uploads and nested/multi file arrays such as:
     * - <input type="file" name="avatar">
     * - <input type="file" name="photos[]" multiple>
     * - <input type="file" name="attachments[contracts][]" multiple>
     *
     * @param array{name?: mixed, type?: mixed, tmp_name?: mixed, error?: mixed, size?: mixed} $entry
     * @return list<FileUpload>
     */
    public static function listFromPhpArray(array $entry): array
    {
        if (!array_key_exists('tmp_name', $entry) || !array_key_exists('error', $entry)) {
            throw UploadException::invalidArrayShape();
        }

        if (!is_array($entry['tmp_name']) && !is_array($entry['error'])) {
            return [self::fromPhpArray($entry)];
        }

        $entries = [];
        self::flattenPhpEntry($entry, [], $entries);

        return $entries;
    }

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

    /**
     * @param array{name?: mixed, type?: mixed, tmp_name?: mixed, error?: mixed, size?: mixed} $entry
     * @param list<int|string> $path
     * @param list<FileUpload> $entries
     */
    private static function flattenPhpEntry(array $entry, array $path, array &$entries): void
    {
        $tmp = self::valueAtPath($entry['tmp_name'], $path);
        $error = self::valueAtPath($entry['error'], $path);

        if (is_array($tmp) || is_array($error)) {
            $keys = [];
            if (is_array($tmp)) {
                $keys = array_merge($keys, array_keys($tmp));
            }
            if (is_array($error)) {
                $keys = array_merge($keys, array_keys($error));
            }

            foreach (array_values(array_unique($keys, SORT_REGULAR)) as $key) {
                self::flattenPhpEntry($entry, [...$path, $key], $entries);
            }

            return;
        }

        if ($tmp === null || $error === null) {
            throw UploadException::invalidArrayShape();
        }

        $entries[] = self::fromPhpArray([
            'name' => self::valueAtPath($entry['name'] ?? null, $path),
            'type' => self::valueAtPath($entry['type'] ?? null, $path),
            'tmp_name' => $tmp,
            'error' => $error,
            'size' => self::valueAtPath($entry['size'] ?? null, $path),
        ]);
    }

    /**
     * @param mixed $value
     * @param list<int|string> $path
     */
    private static function valueAtPath(mixed $value, array $path): mixed
    {
        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
