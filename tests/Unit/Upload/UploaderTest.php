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

    private function makeValidFile(
        string $originalName = 'test.txt',
        string $mime = 'text/plain',
        string $contents = 'data',
    ): FileUpload {
        return new FileUpload($originalName, $mime, $this->makeTempFile($contents), 4, UPLOAD_ERR_OK);
    }

    /**
     * The Uploader refuses to move a source PHP did not register as an upload,
     * and there is deliberately no fallback for that. Tests therefore inject
     * their own mover instead of the production `move_uploaded_file()` one.
     *
     * @return \Closure(string, string): bool
     */
    private function fileMover(): \Closure
    {
        return static fn (string $source, string $destination): bool => @rename($source, $destination);
    }

    /** Smallest byte sequence libmagic identifies as image/jpeg. */
    private function jpegBytes(): string
    {
        return "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";
    }

    /** Smallest byte sequence libmagic identifies as application/pdf. */
    private function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    // ------------------------------------------------------------------
    // Explicit target name
    // ------------------------------------------------------------------

    public function test_store_moves_file_with_explicit_target_name(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile('hello.txt');

        $uploader = new Uploader($dest, fileMover: $this->fileMover());
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

        $relative = (new Uploader($dest, fileMover: $this->fileMover()))->store($file, 'images', 'avatar.png');

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
        $file = $this->makeValidFile('document.pdf', 'application/pdf', $this->pdfBytes());

        $relative = (new Uploader($dest, fileMover: $this->fileMover()))->store($file);

        // 32 hex chars (128-bit) + ".pdf"
        self::assertMatchesRegularExpression('#^[a-f0-9]{32}\.pdf$#', $relative);
        self::assertFileExists($dest . '/' . $relative);

        $this->removeDir($dest);
    }

    public function test_store_generated_name_omits_extension_when_original_has_none(): void
    {
        $dest = $this->makeTempDir();
        $file = new FileUpload('Makefile', 'text/plain', $this->makeTempFile(), 0);

        $relative = (new Uploader($dest, fileMover: $this->fileMover()))->store($file);

        // Exactly 32 hex chars, no dot.
        self::assertMatchesRegularExpression('#^[a-f0-9]{32}$#', $relative);

        $this->removeDir($dest);
    }

    public function test_store_generated_name_lowercases_extension(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile('Photo.JPG', 'image/jpeg', $this->jpegBytes());

        $relative = (new Uploader($dest, fileMover: $this->fileMover()))->store($file);

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

        $relative = (new Uploader($dest, fileMover: $this->fileMover()))->store($file, 'assets');

        self::assertStringStartsWith('assets/', $relative);
        self::assertDirectoryExists($dest . '/assets');

        $this->removeDir($dest);
    }

    public function test_store_creates_nested_subdirectory(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile();

        $relative = (new Uploader($dest, fileMover: $this->fileMover()))->store($file, 'images/thumbs', 'small.jpg');

        self::assertSame('images/thumbs/small.jpg', $relative);
        self::assertDirectoryExists($dest . '/images/thumbs');

        $this->removeDir($dest);
    }

    public function test_store_ignores_leading_and_trailing_separators_in_subdir(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile();

        // "/uploads/" should be normalised to "uploads"
        $relative = (new Uploader($dest, fileMover: $this->fileMover()))->store($file, '/uploads/', 'file.txt');

        self::assertSame('uploads/file.txt', $relative);

        $this->removeDir($dest);
    }

    public function test_store_creates_missing_destination_directory(): void
    {
        $base = $this->makeTempDir();
        $dest = $base . '/new-root';  // does not exist yet
        $file = $this->makeValidFile('x.bin');

        $relative = (new Uploader($dest, fileMover: $this->fileMover()))->store($file, null, 'x.bin');

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

        $relative1 = (new Uploader($dest, fileMover: $this->fileMover()))->store(new FileUpload('a.png', '', $this->makeTempFile(), 0));
        $relative2 = (new Uploader($dest, fileMover: $this->fileMover()))->store(new FileUpload('b.png', '', $this->makeTempFile(), 0));

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
            (new Uploader($dest, fileMover: $this->fileMover()))->store($file);
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
            (new Uploader($dest, maxSizeBytes: 8, fileMover: $this->fileMover()))->store($file);
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
            (new Uploader($dest, allowedExtensions: ['jpg', '.png'], fileMover: $this->fileMover()))->store($file);
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
            (new Uploader($dest, allowedMimeTypes: ['image/jpeg', 'image/png'], fileMover: $this->fileMover()))->store($file);
        } finally {
            $this->removeDir($dest);
        }
    }

    public function test_store_accepts_file_when_constraints_match(): void
    {
        $dest = $this->makeTempDir();
        $tmp = $this->makeTempFile($this->jpegBytes());
        $file = new FileUpload('avatar.JPG', 'IMAGE/JPEG', $tmp, 2, UPLOAD_ERR_OK);

        $relative = (new Uploader(
            $dest,
            allowedExtensions: ['jpg', 'png'],
            allowedMimeTypes: ['image/jpeg', 'image/png'],
            maxSizeBytes: 100,
            fileMover: $this->fileMover(),
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
            (new Uploader($dest, fileMover: $this->fileMover()))->store($file, '../escape');
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
            (new Uploader($dest, fileMover: $this->fileMover()))->store($file, 'images/../../../etc');
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
            (new Uploader($dest, fileMover: $this->fileMover()))->store($file, null, '../escape.bin');
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
            (new Uploader($dest, fileMover: $this->fileMover()))->store($file, null, 'sub\\escape.bin');
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
            (new Uploader($dest, fileMover: $this->fileMover()))->store($file, null, '');
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
            (new Uploader($dest, fileMover: $this->fileMover()))->store($file, null, '.');
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
            (new Uploader($dest, fileMover: $this->fileMover()))->store($file, null, '..');
        } finally {
            $this->removeDir($dest);
        }
    }

    // ------------------------------------------------------------------
    // The client tells the truth about nothing
    // ------------------------------------------------------------------

    public function test_store_rejects_a_php_payload_announced_as_an_image(): void
    {
        $dest = $this->makeTempDir();
        $tmp = $this->makeTempFile("<?php system(\$_GET['c']); ?>\n");
        // Everything the browser controls says "image": the name, and the type.
        $file = new FileUpload('shell.php', 'image/jpeg', $tmp, 20, UPLOAD_ERR_OK);

        try {
            $this->expectException(UploadException::class);
            $this->expectExceptionMessage('text/x-php');
            (new Uploader($dest, allowedMimeTypes: ['image/jpeg']))->store($file);
        } finally {
            @unlink($tmp);
            self::assertSame([], glob($dest . '/*') ?: [], 'Nothing may be written for a refused upload.');
            $this->removeDir($dest);
        }
    }

    public function test_store_takes_the_stored_extension_from_the_bytes_not_the_client_name(): void
    {
        $dest = $this->makeTempDir();
        $file = new FileUpload('shell.php', 'image/jpeg', $this->makeTempFile($this->jpegBytes()), 20);

        $relative = (new Uploader($dest, fileMover: $this->fileMover()))->store($file);

        self::assertMatchesRegularExpression('#^[a-f0-9]{32}\.jpg$#', $relative);
        self::assertStringEndsNotWith('.php', $relative);

        $this->removeDir($dest);
    }

    public function test_store_stores_no_extension_when_the_bytes_are_unidentifiable(): void
    {
        $dest = $this->makeTempDir();
        // A PHP payload named as HTML, with no allowlist configured at all.
        $file = new FileUpload('page.html', 'text/html', $this->makeTempFile('<?php echo 1; ?>'), 16);

        $relative = (new Uploader($dest, fileMover: $this->fileMover()))->store($file);

        self::assertMatchesRegularExpression('#^[a-f0-9]{32}$#', $relative);

        $this->removeDir($dest);
    }

    public function test_store_rejects_a_double_extension_that_ends_in_an_allowlisted_one(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile('avatar.php.jpg', 'image/jpeg', $this->jpegBytes());

        try {
            $this->expectException(UploadException::class);
            $this->expectExceptionMessage('"php"');
            (new Uploader($dest, allowedExtensions: ['jpg']))->store($file);
        } finally {
            self::assertSame([], glob($dest . '/*') ?: []);
            $this->removeDir($dest);
        }
    }

    public function test_store_applies_the_extension_allowlist_to_an_explicit_target_name(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile('avatar.jpg', 'image/jpeg', $this->jpegBytes());

        try {
            $this->expectException(UploadException::class);
            $this->expectExceptionMessage('"phtml"');
            (new Uploader($dest, allowedExtensions: ['jpg']))->store($file, null, 'shell.phtml');
        } finally {
            self::assertSame([], glob($dest . '/*') ?: []);
            $this->removeDir($dest);
        }
    }

    public function test_store_rejects_a_dotfile_target_name(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile();

        try {
            $this->expectException(UploadException::class);
            $this->expectExceptionMessage('.htaccess');
            (new Uploader($dest))->store($file, null, '.htaccess');
        } finally {
            self::assertFileDoesNotExist($dest . '/.htaccess');
            $this->removeDir($dest);
        }
    }

    public function test_store_rejects_a_target_name_padded_around_a_dot_alias(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile();

        try {
            $this->expectException(UploadException::class);
            (new Uploader($dest))->store($file, null, '  ..  ');
        } finally {
            $this->removeDir($dest);
        }
    }

    public function test_store_measures_the_real_size_and_not_the_declared_one(): void
    {
        $dest = $this->makeTempDir();
        $tmp = $this->makeTempFile(str_repeat('A', 100000));
        // The browser declares ten bytes; a hundred thousand are on disk.
        $file = new FileUpload('payload.txt', 'text/plain', $tmp, 10, UPLOAD_ERR_OK);

        try {
            $this->expectException(UploadException::class);
            $this->expectExceptionMessage('100000 bytes');
            (new Uploader($dest, maxSizeBytes: 1000))->store($file);
        } finally {
            @unlink($tmp);
            self::assertSame([], glob($dest . '/*') ?: []);
            $this->removeDir($dest);
        }
    }

    // ------------------------------------------------------------------
    // Moving is only ever a genuine upload
    // ------------------------------------------------------------------

    public function test_store_refuses_an_arbitrary_source_path_and_leaves_it_in_place(): void
    {
        $dest = $this->makeTempDir();
        $source = $this->makeTempFile('SERVER SIDE SECRET');
        $file = new FileUpload('leaked.txt', 'text/plain', $source, 18, UPLOAD_ERR_OK);

        try {
            (new Uploader($dest))->store($file, null, 'leaked.txt');
            self::fail('A source that is not a genuine PHP upload must not be moved.');
        } catch (UploadException $exception) {
            self::assertStringContainsString('not a genuine PHP upload', $exception->getMessage());
        } finally {
            self::assertFileExists($source, 'The refused source must survive; rename() would have destroyed it.');
            self::assertFileDoesNotExist($dest . '/leaked.txt');
            @unlink($source);
            $this->removeDir($dest);
        }
    }

    // ------------------------------------------------------------------
    // Destination safety
    // ------------------------------------------------------------------

    public function test_store_refuses_to_replace_an_existing_file(): void
    {
        $dest = $this->makeTempDir();
        file_put_contents($dest . '/report.txt', 'TENANT A');
        $file = $this->makeValidFile('report.txt', 'text/plain', 'TENANT B');

        try {
            $this->expectException(UploadException::class);
            $this->expectExceptionMessage('Refusing to overwrite');
            (new Uploader($dest, fileMover: $this->fileMover()))->store($file, null, 'report.txt');
        } finally {
            self::assertSame('TENANT A', (string) file_get_contents($dest . '/report.txt'));
            $this->removeDir($dest);
        }
    }

    public function test_store_accepts_an_explicit_overwrite(): void
    {
        $dest = $this->makeTempDir();
        file_put_contents($dest . '/report.txt', 'OLD');
        $file = $this->makeValidFile('report.txt', 'text/plain', 'NEW');

        (new Uploader($dest, fileMover: $this->fileMover(), overwriteExisting: true))
            ->store($file, null, 'report.txt');

        self::assertSame('NEW', (string) file_get_contents($dest . '/report.txt'));

        $this->removeDir($dest);
    }

    public function test_store_refuses_a_subdirectory_that_escapes_the_root_through_a_symlink(): void
    {
        $dest = $this->makeTempDir();
        $outside = $this->makeTempDir();
        symlink($outside, $dest . '/exports');
        $file = $this->makeValidFile('note.txt', 'text/plain', 'ESCAPED');

        try {
            $this->expectException(UploadException::class);
            $this->expectExceptionMessage('outside the destination root');
            (new Uploader($dest))->store($file, 'exports', 'note.txt');
        } finally {
            self::assertFileDoesNotExist($outside . '/note.txt');
            @unlink($dest . '/exports');
            $this->removeDir($dest);
            $this->removeDir($outside);
        }
    }

    public function test_store_creates_no_directory_beyond_an_escaping_symlink(): void
    {
        $dest = $this->makeTempDir();
        $outside = $this->makeTempDir();
        symlink($outside, $dest . '/exports');
        $file = $this->makeValidFile();

        try {
            $this->expectException(UploadException::class);
            (new Uploader($dest))->store($file, 'exports/2026/q1');
        } finally {
            self::assertDirectoryDoesNotExist(
                $outside . '/2026',
                'mkdir() must not have followed the link before the upload was refused.',
            );
            @unlink($dest . '/exports');
            $this->removeDir($dest);
            $this->removeDir($outside);
        }
    }

    // ------------------------------------------------------------------
    // storeMany
    // ------------------------------------------------------------------

    public function test_store_many_returns_empty_array_for_empty_input(): void
    {
        $dest = $this->makeTempDir();

        $paths = (new Uploader($dest, fileMover: $this->fileMover()))->storeMany([]);

        self::assertSame([], $paths);

        $this->removeDir($dest);
    }

    public function test_store_many_returns_relative_paths_in_order(): void
    {
        $dest = $this->makeTempDir();
        $files = [
            $this->makeValidFile('a.txt'),
            $this->makeValidFile('b.txt'),
            $this->makeValidFile('c.txt'),
        ];

        $paths = (new Uploader($dest, fileMover: $this->fileMover()))->storeMany($files, 'batch');

        self::assertCount(3, $paths);
        foreach ($paths as $path) {
            self::assertStringStartsWith('batch/', $path);
            self::assertFileExists($dest . '/' . $path);
        }
        self::assertCount(3, array_unique($paths), 'Each file should receive a unique name.');

        $this->removeDir($dest);
    }

    public function test_store_many_stops_on_first_invalid_file(): void
    {
        $dest = $this->makeTempDir();
        $files = [
            $this->makeValidFile('first.txt'),
            new FileUpload('bad.txt', '', '/tmp/bad', 0, UPLOAD_ERR_NO_FILE),
            $this->makeValidFile('third.txt'),
        ];

        try {
            $this->expectException(UploadException::class);
            (new Uploader($dest, fileMover: $this->fileMover()))->storeMany($files);
        } finally {
            // Only the first file should have been written.
            $stored = glob($dest . '/*');
            self::assertCount(1, $stored ?: []);
            $this->removeDir($dest);
        }
    }
}
