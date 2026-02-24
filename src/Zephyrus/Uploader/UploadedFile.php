<?php

declare(strict_types=1);

namespace Zephyrus\Uploader;

final readonly class UploadedFile
{
    public function __construct(
        public string $field,
        public string $originalName,
        public string $mimeType,
        public string $tmpPath,
        public int $size,
        public int $error = UPLOAD_ERR_OK,
    ) {
    }

    /**
     * @param array{name?: mixed, type?: mixed, tmp_name?: mixed, error?: mixed, size?: mixed} $entry
     */
    public static function fromFilesArray(string $field, array $entry): self
    {
        if (!array_key_exists('tmp_name', $entry) || !array_key_exists('error', $entry)) {
            throw UploadException::invalidUploadArrayShape($field);
        }

        return new self(
            field: $field,
            originalName: (string) ($entry['name'] ?? ''),
            mimeType: (string) ($entry['type'] ?? ''),
            tmpPath: (string) $entry['tmp_name'],
            size: (int) ($entry['size'] ?? 0),
            error: (int) $entry['error'],
        );
    }

    public function clientExtension(): string
    {
        $extension = pathinfo($this->originalName, PATHINFO_EXTENSION);

        return is_string($extension) ? strtolower($extension) : '';
    }
}
