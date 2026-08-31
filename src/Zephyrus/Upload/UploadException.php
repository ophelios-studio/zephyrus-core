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
     * The source is not a file PHP registered as an HTTP upload.
     *
     * Raised by the default mover. Non-HTTP callers and tests inject their own
     * `$fileMover` rather than relying on a fallback, because the only reason
     * `move_uploaded_file()` fails is precisely this check.
     */
    public static function notAnUploadedFile(string $path): self
    {
        return new self(sprintf(
            'Refusing to move "%s": it is not a genuine PHP upload. '
            . 'Inject a $fileMover when storing files that did not arrive over HTTP.',
            $path,
        ));
    }

    public static function unreadableSource(string $path): self
    {
        return new self(sprintf('Upload temporary file "%s" is missing or unreadable.', $path));
    }

    public static function mimeTypeSniffFailed(string $path): self
    {
        return new self(sprintf('Unable to determine the real MIME type of "%s".', $path));
    }

    public static function destinationAlreadyExists(string $path): self
    {
        return new self(sprintf(
            'Refusing to overwrite the existing file "%s". Pass $overwriteExisting to allow replacement.',
            $path,
        ));
    }

    public static function destinationNotContained(string $path): self
    {
        return new self(sprintf('Upload destination "%s" resolves outside the destination root.', $path));
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
