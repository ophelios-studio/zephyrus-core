<?php

declare(strict_types=1);

namespace Tests\Unit\Uploader;

use PHPUnit\Framework\TestCase;
use Zephyrus\Uploader\FileUpload;
use Zephyrus\Uploader\UploadException;
use Zephyrus\Uploader\UploadedFile;

final class FileUploadTest extends TestCase
{
    public function testSaveMovesFileToTargetDirectory(): void
    {
        $source = $this->createTempFile('hello');
        $directory = $this->createTempDirectory();

        $uploader = new FileUpload();
        $path = $uploader->save(new UploadedFile('doc', 'hello.txt', 'text/plain', $source, 5), $directory, 'final.txt');

        self::assertSame($directory . '/final.txt', $path);
        self::assertFileExists($path);
        self::assertSame('hello', (string) file_get_contents($path));

        @unlink($path);
        @rmdir($directory);
    }

    public function testSaveGeneratesRandomFileNameWithExtension(): void
    {
        $source = $this->createTempFile('data');
        $directory = $this->createTempDirectory();

        $uploader = new FileUpload();
        $path = $uploader->save(new UploadedFile('asset', 'photo.png', 'image/png', $source, 4), $directory);

        self::assertMatchesRegularExpression('#^[a-f0-9]{32}\\.png$#', basename($path));
        self::assertFileExists($path);

        @unlink($path);
        @rmdir($directory);
    }

    public function testSaveCreatesTargetDirectoryWhenMissing(): void
    {
        $source = $this->createTempFile('x');
        $base = $this->createTempDirectory();
        $directory = $base . '/nested';

        $uploader = new FileUpload();
        $path = $uploader->save(new UploadedFile('file', 'x.bin', 'application/octet-stream', $source, 1), $directory, 'x.bin');

        self::assertFileExists($path);

        @unlink($path);
        @rmdir($directory);
        @rmdir($base);
    }

    public function testSaveThrowsWhenUploadErrorIsPresent(): void
    {
        $uploader = new FileUpload();

        $this->expectException(UploadException::class);
        $uploader->save(new UploadedFile('file', 'x.bin', 'application/octet-stream', '/tmp/does-not-matter', 1, UPLOAD_ERR_INI_SIZE), '/tmp');
    }

    public function testSaveThrowsOnInvalidTargetFileName(): void
    {
        $source = $this->createTempFile('x');
        $directory = $this->createTempDirectory();

        $uploader = new FileUpload();

        try {
            $this->expectException(UploadException::class);
            $uploader->save(new UploadedFile('file', 'x.bin', 'application/octet-stream', $source, 1), $directory, '../escape.bin');
        } finally {
            @unlink($source);
            @rmdir($directory);
        }
    }

    private function createTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zep-upload-');
        if ($path === false) {
            self::fail('Unable to create temp file for tests.');
        }

        file_put_contents($path, $contents);

        return $path;
    }

    private function createTempDirectory(): string
    {
        $path = sys_get_temp_dir() . '/zep-upload-dir-' . uniqid('', true);
        mkdir($path);

        return $path;
    }
}
