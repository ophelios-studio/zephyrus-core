<?php

declare(strict_types=1);

namespace Zephyrus\Uploader;

final class FileUpload
{
    public function save(UploadedFile $file, string $directory, ?string $targetName = null): string
    {
        if ($file->error !== UPLOAD_ERR_OK) {
            throw UploadException::uploadError($file->field, $file->error);
        }

        if (!is_file($file->tmpPath) || !is_readable($file->tmpPath)) {
            throw UploadException::sourceFileNotReadable($file->tmpPath);
        }

        $this->ensureDirectory($directory);

        $name = $targetName ?? $this->generateFilename($file);
        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\')) {
            throw UploadException::invalidTargetFilename($name);
        }

        $destination = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;

        if (!@rename($file->tmpPath, $destination)) {
            throw UploadException::unableToMoveFile($file->tmpPath, $destination);
        }

        return $destination;
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw UploadException::unableToCreateDirectory($directory);
        }
    }

    private function generateFilename(UploadedFile $file): string
    {
        $base = bin2hex(random_bytes(16));
        $extension = $file->clientExtension();

        if ($extension === '') {
            return $base;
        }

        return $base . '.' . $extension;
    }
}
