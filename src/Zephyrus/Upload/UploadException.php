<?php

declare(strict_types=1);

namespace Zephyrus\Upload;

use Zephyrus\Exceptions\ZephyrusRuntimeException;

final class UploadException extends ZephyrusRuntimeException
{
    public static function invalidArrayShape(): self
    {
        return new self('Invalid upload payload: missing required "tmp_name" or "error" keys.');
    }

    public static function uploadFailed(int $code): self
    {
        $descriptions = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds the server maximum upload size (upload_max_filesize).',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form maximum upload size (MAX_FILE_SIZE).',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write the uploaded file to disk.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
        ];

        $description = $descriptions[$code] ?? sprintf('Unknown upload error (code %d).', $code);

        return new self(sprintf('Upload failed: %s', $description));
    }

    public static function pathTraversalDetected(string $segment): self
    {
        return new self(sprintf('Path traversal attempt detected in upload path segment: "%s".', $segment));
    }

    public static function invalidTargetName(string $name): self
    {
        return new self(sprintf('Invalid upload target filename: "%s".', $name));
    }

    public static function directoryCreationFailed(string $dir): self
    {
        return new self(sprintf('Unable to create upload directory: %s', $dir));
    }

    public static function moveFileFailed(string $src, string $dst): self
    {
        return new self(sprintf('Unable to move uploaded file from "%s" to "%s".', $src, $dst));
    }

    /**
     * @param string[] $allowedExtensions
     */
    public static function extensionNotAllowed(string $extension, array $allowedExtensions): self
    {
        return new self(sprintf(
            'Upload extension "%s" is not allowed. Allowed extensions: %s',
            $extension,
            implode(', ', $allowedExtensions),
        ));
    }

    /**
     * @param string[] $allowedMimeTypes
     */
    public static function mimeTypeNotAllowed(string $mimeType, array $allowedMimeTypes): self
    {
        return new self(sprintf(
            'Upload MIME type "%s" is not allowed. Allowed MIME types: %s',
            $mimeType,
            implode(', ', $allowedMimeTypes),
        ));
    }

    public static function fileTooLarge(int $sizeBytes, int $maxSizeBytes): self
    {
        return new self(sprintf(
            'Upload is too large (%d bytes). Maximum allowed size is %d bytes.',
            $sizeBytes,
            $maxSizeBytes,
        ));
    }
}
