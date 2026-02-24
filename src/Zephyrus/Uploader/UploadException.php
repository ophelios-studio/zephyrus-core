<?php

declare(strict_types=1);

namespace Zephyrus\Uploader;

use Zephyrus\Exceptions\ZephyrusRuntimeException;

final class UploadException extends ZephyrusRuntimeException
{
    public static function invalidUploadArrayShape(string $field): self
    {
        return new self(sprintf('Invalid upload payload for field "%s".', $field));
    }

    public static function uploadError(string $field, int $code): self
    {
        return new self(sprintf('Upload error for field "%s" (code: %d).', $field, $code));
    }

    public static function sourceFileNotReadable(string $path): self
    {
        return new self(sprintf('Upload source file is not readable: %s', $path));
    }

    public static function unableToCreateDirectory(string $path): self
    {
        return new self(sprintf('Unable to create upload directory: %s', $path));
    }

    public static function invalidTargetFilename(string $filename): self
    {
        return new self(sprintf('Invalid target filename: %s', $filename));
    }

    public static function unableToMoveFile(string $source, string $destination): self
    {
        return new self(sprintf('Unable to move uploaded file from %s to %s', $source, $destination));
    }
}
