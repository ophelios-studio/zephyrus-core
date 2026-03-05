<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Upload;

use PHPUnit\Framework\TestCase;
use Zephyrus\Upload\FileUpload;
use Zephyrus\Upload\UploadException;
use Zephyrus\Upload\Uploader;

final class UploaderTest extends TestCase
{
    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeTempFile(string $contents = 'data'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zep-upload-');
        if ($path === false) {
            self::fail('Unable to create temp file for upload tests.');
        }
        file_put_contents($path, $contents);

        return $path;
    }

    private function makeTempDir(): string
    {
        $path = sys_get_temp_dir() . '/zep-uploader-' . uniqid('', true);
        mkdir($path, 0775, true);

        return $path;
    }

    /** Recursively removes a directory and its contents. */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($full) ? $this->removeDir($full) : unlink($full);
        }

        rmdir($dir);
    }

    private function makeValidFile(string $originalName = 'test.txt', string $mime = 'text/plain'): FileUpload
    {
        return new FileUpload($originalName, $mime, $this->makeTempFile(), 4, UPLOAD_ERR_OK);
    }

    // ------------------------------------------------------------------
    // Explicit target name
    // ------------------------------------------------------------------

    public function test_store_moves_file_with_explicit_target_name(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile('hello.txt');

        $uploader = new Uploader($dest);
        $relative = $uploader->store($file, null, 'final.txt');

        self::assertSame('final.txt', $relative);
        self::assertFileExists($dest . '/final.txt');
        self::assertSame('data', (string) file_get_contents($dest . '/final.txt'));

        $this->removeDir($dest);
    }

    public function test_store_with_explicit_name_and_subdirectory_returns_relative_path(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile('photo.png', 'image/png');

        $relative = (new Uploader($dest))->store($file, 'images', 'avatar.png');

        self::assertSame('images/avatar.png', $relative);
        self::assertFileExists($dest . '/images/avatar.png');

        $this->removeDir($dest);
    }

    // ------------------------------------------------------------------
    // Auto-generated name
    // ------------------------------------------------------------------

    public function test_store_generates_random_hex_name_when_target_absent(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile('document.pdf', 'application/pdf');

        $relative = (new Uploader($dest))->store($file);

        // 32 hex chars (128-bit) + ".pdf"
        self::assertMatchesRegularExpression('#^[a-f0-9]{32}\.pdf$#', $relative);
        self::assertFileExists($dest . '/' . $relative);

        $this->removeDir($dest);
    }

    public function test_store_generated_name_omits_extension_when_original_has_none(): void
    {
        $dest = $this->makeTempDir();
        $file = new FileUpload('Makefile', 'text/plain', $this->makeTempFile(), 0);

        $relative = (new Uploader($dest))->store($file);

        // Exactly 32 hex chars, no dot.
        self::assertMatchesRegularExpression('#^[a-f0-9]{32}$#', $relative);

        $this->removeDir($dest);
    }

    public function test_store_generated_name_lowercases_extension(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile('Photo.JPG', 'image/jpeg');

        $relative = (new Uploader($dest))->store($file);

        self::assertStringEndsWith('.jpg', $relative);

        $this->removeDir($dest);
    }

    // ------------------------------------------------------------------
    // Sub-directory handling
    // ------------------------------------------------------------------

    public function test_store_creates_subdirectory_under_destination_root(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile();

        $relative = (new Uploader($dest))->store($file, 'assets');

        self::assertStringStartsWith('assets/', $relative);
        self::assertDirectoryExists($dest . '/assets');

        $this->removeDir($dest);
    }

    public function test_store_creates_nested_subdirectory(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile();

        $relative = (new Uploader($dest))->store($file, 'images/thumbs', 'small.jpg');

        self::assertSame('images/thumbs/small.jpg', $relative);
        self::assertDirectoryExists($dest . '/images/thumbs');

        $this->removeDir($dest);
    }

    public function test_store_ignores_leading_and_trailing_separators_in_subdir(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile();

        // "/uploads/" should be normalised to "uploads"
        $relative = (new Uploader($dest))->store($file, '/uploads/', 'file.txt');

        self::assertSame('uploads/file.txt', $relative);

        $this->removeDir($dest);
    }

    public function test_store_creates_missing_destination_directory(): void
    {
        $base = $this->makeTempDir();
        $dest = $base . '/new-root';  // does not exist yet
        $file = $this->makeValidFile('x.bin');

        $relative = (new Uploader($dest))->store($file, null, 'x.bin');

        self::assertSame('x.bin', $relative);
        self::assertFileExists($dest . '/x.bin');

        $this->removeDir($base);
    }

    // ------------------------------------------------------------------
    // Two unique calls produce distinct random names
    // ------------------------------------------------------------------

    public function test_store_generates_unique_names_on_successive_calls(): void
    {
        $dest = $this->makeTempDir();

        $relative1 = (new Uploader($dest))->store(new FileUpload('a.png', '', $this->makeTempFile(), 0));
        $relative2 = (new Uploader($dest))->store(new FileUpload('b.png', '', $this->makeTempFile(), 0));

        self::assertNotSame($relative1, $relative2);

        $this->removeDir($dest);
    }

    // ------------------------------------------------------------------
    // Validation failures
    // ------------------------------------------------------------------

    public function test_store_throws_when_file_has_upload_error(): void
    {
        $dest = $this->makeTempDir();
        $file = new FileUpload('x.bin', '', '/tmp/x', 0, UPLOAD_ERR_INI_SIZE);

        try {
            $this->expectException(UploadException::class);
            $this->expectExceptionMessage('upload_max_filesize');
            (new Uploader($dest))->store($file);
        } finally {
            $this->removeDir($dest);
        }
    }

    public function test_store_rejects_file_when_size_exceeds_configured_maximum(): void
    {
        $dest = $this->makeTempDir();
        $tmp = $this->makeTempFile('1234567890');
        $file = new FileUpload('payload.txt', 'text/plain', $tmp, 10, UPLOAD_ERR_OK);

        try {
            $this->expectException(UploadException::class);
            $this->expectExceptionMessage('too large');
            (new Uploader($dest, maxSizeBytes: 8))->store($file);
        } finally {
            @unlink($tmp);
            $this->removeDir($dest);
        }
    }

    public function test_store_rejects_file_when_extension_not_allowlisted(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile('avatar.gif', 'image/gif');

        try {
            $this->expectException(UploadException::class);
            $this->expectExceptionMessage('Allowed extensions: jpg, png');
            (new Uploader($dest, allowedExtensions: ['jpg', '.png']))->store($file);
        } finally {
            $this->removeDir($dest);
        }
    }

    public function test_store_rejects_file_when_mime_type_not_allowlisted(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile('avatar.jpg', 'image/gif');

        try {
            $this->expectException(UploadException::class);
            $this->expectExceptionMessage('Allowed MIME types: image/jpeg, image/png');
            (new Uploader($dest, allowedMimeTypes: ['image/jpeg', 'image/png']))->store($file);
        } finally {
            $this->removeDir($dest);
        }
    }

    public function test_store_accepts_file_when_constraints_match(): void
    {
        $dest = $this->makeTempDir();
        $tmp = $this->makeTempFile('ok');
        $file = new FileUpload('avatar.JPG', 'IMAGE/JPEG', $tmp, 2, UPLOAD_ERR_OK);

        $relative = (new Uploader(
            $dest,
            allowedExtensions: ['jpg', 'png'],
            allowedMimeTypes: ['image/jpeg', 'image/png'],
            maxSizeBytes: 100,
        ))->store($file, 'avatars');

        self::assertMatchesRegularExpression('#^avatars/[a-f0-9]{32}\.jpg$#', $relative);
        self::assertFileExists($dest . '/' . $relative);

        $this->removeDir($dest);
    }

    // ------------------------------------------------------------------
    // Path traversal rejection — sub-directory
    // ------------------------------------------------------------------

    public function test_store_rejects_double_dot_in_subdirectory(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile();

        try {
            $this->expectException(UploadException::class);
            $this->expectExceptionMessage('traversal');
            (new Uploader($dest))->store($file, '../escape');
        } finally {
            $this->removeDir($dest);
        }
    }

    public function test_store_rejects_double_dot_segment_within_subdirectory(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile();

        try {
            $this->expectException(UploadException::class);
            (new Uploader($dest))->store($file, 'images/../../../etc');
        } finally {
            $this->removeDir($dest);
        }
    }

    // ------------------------------------------------------------------
    // Path traversal rejection — target name
    // ------------------------------------------------------------------

    public function test_store_rejects_forward_slash_in_target_name(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile();

        try {
            $this->expectException(UploadException::class);
            (new Uploader($dest))->store($file, null, '../escape.bin');
        } finally {
            $this->removeDir($dest);
        }
    }

    public function test_store_rejects_backslash_in_target_name(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile();

        try {
            $this->expectException(UploadException::class);
            (new Uploader($dest))->store($file, null, 'sub\\escape.bin');
        } finally {
            $this->removeDir($dest);
        }
    }

    public function test_store_rejects_empty_target_name(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile();

        try {
            $this->expectException(UploadException::class);
            (new Uploader($dest))->store($file, null, '');
        } finally {
            $this->removeDir($dest);
        }
    }

    public function test_store_rejects_dot_as_target_name(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile();

        try {
            $this->expectException(UploadException::class);
            (new Uploader($dest))->store($file, null, '.');
        } finally {
            $this->removeDir($dest);
        }
    }

    public function test_store_rejects_double_dot_as_target_name(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile();

        try {
            $this->expectException(UploadException::class);
            (new Uploader($dest))->store($file, null, '..');
        } finally {
            $this->removeDir($dest);
        }
    }
}
