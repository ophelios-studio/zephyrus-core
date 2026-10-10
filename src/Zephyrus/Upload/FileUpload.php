<?php

declare(strict_types=1);

namespace Zephyrus\Upload;

/**
 * Immutable value object for a file submitted through an HTTP multipart upload.
 */
final readonly class FileUpload
{
    /**
     * Builds one or many FileUpload instances from a $_FILES field entry,
     * including nested multi-file arrays such as `photos[]` or `attachments[contracts][]`.
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
     * Builds a FileUpload from a single $_FILES entry such as `$_FILES['avatar']`.
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
     * The lowercased extension of the client-supplied name, or an empty string.
     */
    public function extension(): string
    {
        $ext = pathinfo($this->originalName, PATHINFO_EXTENSION);

        return is_string($ext) ? strtolower($ext) : '';
    }

    /**
     * Every dotted segment of the client-supplied name, lowercased, in order.
     *
     * Unlike extension(), this reports all segments: an allowlist must check each one.
     * `avatar.php.jpg` gives `['php', 'jpg']`, `.htaccess` gives `['htaccess']`.
     *
     * @return list<string>
     */
    public function extensions(): array
    {
        $base = basename(str_replace('\\', '/', $this->originalName));
        $parts = explode('.', $base);
        array_shift($parts);

        return array_values(array_filter(
            array_map(static fn (string $part): string => strtolower($part), $parts),
            static fn (string $part): bool => $part !== '',
        ));
    }

    /**
     * Whether PHP reported no upload error.
     */
    public function isValid(): bool
    {
        return $this->errorCode === UPLOAD_ERR_OK;
    }

    /**
     * @throws UploadException With a description of the PHP upload error code.
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
            return;
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
