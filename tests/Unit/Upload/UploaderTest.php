<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Upload;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Upload\FileUpload;
use Zephyrus\Upload\UploadException;
use Zephyrus\Upload\Uploader;

final class UploaderTest extends TestCase
{
    /** @var list<string> */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
    }

    private function makeTempFile(string $contents = 'data'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zep-upload-');
        if ($path === false) {
            self::fail('Unable to create temp file for upload tests.');
        }
        file_put_contents($path, $contents);
        $this->tempPaths[] = $path;

        return $path;
    }

    private function makeTempDir(): string
    {
        $path = sys_get_temp_dir() . '/zep-uploader-' . bin2hex(random_bytes(8));
        mkdir($path, 0775, true);
        $this->tempPaths[] = $path;

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

    public function test_store_moves_file_with_explicit_target_name(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile('hello.txt');

        $uploader = new Uploader($dest, fileMover: $this->fileMover());
        $relative = $uploader->store($file, null, 'final.txt');

        self::assertSame('final.txt', $relative);
        self::assertFileExists($dest . '/final.txt');
        self::assertSame('data', (string) file_get_contents($dest . '/final.txt'));
    }

    public function test_store_with_explicit_name_and_subdirectory_returns_relative_path(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile('photo.png', 'image/png', $this->pngBytes());

        $relative = (new Uploader($dest, fileMover: $this->fileMover()))->store($file, 'images', 'avatar.png');

        self::assertSame('images/avatar.png', $relative);
        self::assertFileExists($dest . '/images/avatar.png');
    }

    public function test_store_generates_random_hex_name_when_target_absent(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile('document.pdf', 'application/pdf', $this->pdfBytes());

        $relative = (new Uploader($dest, fileMover: $this->fileMover()))->store($file);

        // 32 hex chars (128-bit) + ".pdf"
        self::assertMatchesRegularExpression('#^[a-f0-9]{32}\.pdf$#', $relative);
        self::assertFileExists($dest . '/' . $relative);
    }

    public function test_store_generated_name_omits_extension_when_original_has_none(): void
    {
        $dest = $this->makeTempDir();
        $file = new FileUpload('Makefile', 'text/plain', $this->makeTempFile(), 0);

        $relative = (new Uploader($dest, fileMover: $this->fileMover()))->store($file);

        // Exactly 32 hex chars, no dot.
        self::assertMatchesRegularExpression('#^[a-f0-9]{32}$#', $relative);
    }

    public function test_store_generated_name_lowercases_extension(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile('Photo.JPG', 'image/jpeg', $this->jpegBytes());

        $relative = (new Uploader($dest, fileMover: $this->fileMover()))->store($file);

        self::assertStringEndsWith('.jpg', $relative);
    }

    public function test_store_creates_subdirectory_under_destination_root(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile();

        $relative = (new Uploader($dest, fileMover: $this->fileMover()))->store($file, 'assets');

        self::assertStringStartsWith('assets/', $relative);
        self::assertDirectoryExists($dest . '/assets');
    }

    public function test_store_creates_nested_subdirectory(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile('small.jpg', 'image/jpeg', $this->jpegBytes());

        $relative = (new Uploader($dest, fileMover: $this->fileMover()))->store($file, 'images/thumbs', 'small.jpg');

        self::assertSame('images/thumbs/small.jpg', $relative);
        self::assertDirectoryExists($dest . '/images/thumbs');
    }

    public function test_store_ignores_leading_and_trailing_separators_in_subdir(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile();

        // "/uploads/" should be normalised to "uploads"
        $relative = (new Uploader($dest, fileMover: $this->fileMover()))->store($file, '/uploads/', 'file.txt');

        self::assertSame('uploads/file.txt', $relative);
    }

    public function test_store_creates_missing_destination_directory(): void
    {
        $base = $this->makeTempDir();
        $dest = $base . '/new-root';  // does not exist yet
        $file = $this->makeValidFile('x.txt');

        $relative = (new Uploader($dest, fileMover: $this->fileMover()))->store($file, null, 'x.txt');

        self::assertSame('x.txt', $relative);
        self::assertFileExists($dest . '/x.txt');
    }

    public function test_store_generates_unique_names_on_successive_calls(): void
    {
        $dest = $this->makeTempDir();

        $relative1 = (new Uploader($dest, fileMover: $this->fileMover()))->store(new FileUpload('a.png', '', $this->makeTempFile(), 0));
        $relative2 = (new Uploader($dest, fileMover: $this->fileMover()))->store(new FileUpload('b.png', '', $this->makeTempFile(), 0));

        self::assertNotSame($relative1, $relative2);
    }

    public function test_store_throws_when_file_has_upload_error(): void
    {
        $dest = $this->makeTempDir();
        $file = new FileUpload('x.bin', '', '/tmp/x', 0, UPLOAD_ERR_INI_SIZE);

        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('upload_max_filesize');
        (new Uploader($dest, fileMover: $this->fileMover()))->store($file);
    }

    public function test_store_rejects_file_when_size_exceeds_configured_maximum(): void
    {
        $dest = $this->makeTempDir();
        $tmp = $this->makeTempFile('1234567890');
        $file = new FileUpload('payload.txt', 'text/plain', $tmp, 10, UPLOAD_ERR_OK);

        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('too large');
        (new Uploader($dest, maxSizeBytes: 8, fileMover: $this->fileMover()))->store($file);
    }

    public function test_store_rejects_file_when_extension_not_allowlisted(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile('avatar.gif', 'image/gif');

        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('Allowed extensions: jpg, png');
        (new Uploader($dest, allowedExtensions: ['jpg', '.png'], fileMover: $this->fileMover()))->store($file);
    }

    public function test_store_rejects_file_when_mime_type_not_allowlisted(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile('avatar.jpg', 'image/gif');

        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('Allowed MIME types: image/jpeg, image/png');
        (new Uploader($dest, allowedMimeTypes: ['image/jpeg', 'image/png'], fileMover: $this->fileMover()))->store($file);
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
    }

    public function test_store_rejects_double_dot_in_subdirectory(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile();

        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('traversal');
        (new Uploader($dest, fileMover: $this->fileMover()))->store($file, '../escape');
    }

    public function test_store_rejects_double_dot_segment_within_subdirectory(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile();

        $this->expectException(UploadException::class);
        (new Uploader($dest, fileMover: $this->fileMover()))->store($file, 'images/../../../etc');
    }

    public function test_store_rejects_forward_slash_in_target_name(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile();

        $this->expectException(UploadException::class);
        (new Uploader($dest, fileMover: $this->fileMover()))->store($file, null, '../escape.bin');
    }

    public function test_store_rejects_backslash_in_target_name(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile();

        $this->expectException(UploadException::class);
        (new Uploader($dest, fileMover: $this->fileMover()))->store($file, null, 'sub\\escape.bin');
    }

    public function test_store_rejects_empty_target_name(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile();

        $this->expectException(UploadException::class);
        (new Uploader($dest, fileMover: $this->fileMover()))->store($file, null, '');
    }

    public function test_store_rejects_dot_as_target_name(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile();

        $this->expectException(UploadException::class);
        (new Uploader($dest, fileMover: $this->fileMover()))->store($file, null, '.');
    }

    public function test_store_rejects_double_dot_as_target_name(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile();

        $this->expectException(UploadException::class);
        (new Uploader($dest, fileMover: $this->fileMover()))->store($file, null, '..');
    }

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
        }
    }

    public function test_store_takes_the_stored_extension_from_the_bytes_not_the_client_name(): void
    {
        $dest = $this->makeTempDir();
        $file = new FileUpload('shell.php', 'image/jpeg', $this->makeTempFile($this->jpegBytes()), 20);

        $relative = (new Uploader($dest, fileMover: $this->fileMover()))->store($file);

        self::assertMatchesRegularExpression('#^[a-f0-9]{32}\.jpg$#', $relative);
        self::assertStringEndsNotWith('.php', $relative);
    }

    public function test_store_stores_no_extension_when_the_bytes_are_unidentifiable(): void
    {
        $dest = $this->makeTempDir();
        // A PHP payload named as HTML, with no allowlist configured at all.
        $file = new FileUpload('page.html', 'text/html', $this->makeTempFile('<?php echo 1; ?>'), 16);

        $relative = (new Uploader($dest, fileMover: $this->fileMover()))->store($file);

        self::assertMatchesRegularExpression('#^[a-f0-9]{32}$#', $relative);
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
        }
    }

    public function test_store_rejects_a_target_name_padded_around_a_dot_alias(): void
    {
        $dest = $this->makeTempDir();
        $file = $this->makeValidFile();

        $this->expectException(UploadException::class);
        (new Uploader($dest))->store($file, null, '  ..  ');
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
        }
    }

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
        }
    }

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
        }
    }

    public function test_store_many_returns_empty_array_for_empty_input(): void
    {
        $dest = $this->makeTempDir();

        $paths = (new Uploader($dest, fileMover: $this->fileMover()))->storeMany([]);

        self::assertSame([], $paths);
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
        }
    }

    public function test_store_refusal_escapes_the_client_extension(): void
    {
        $dest = $this->makeTempDir();
        $file = new FileUpload("report.p\x1b[8mdf", 'application/pdf', $this->makeTempFile($this->pdfBytes()), 20);

        try {
            (new Uploader($dest, allowedExtensions: ['pdf'], fileMover: $this->fileMover()))->store($file);
            self::fail('An extension outside the allowlist must be refused.');
        } catch (UploadException $e) {
            self::assertSame(
                "Upload extension \"p\\u001b[8mdf\" is not allowed. Allowed extensions: pdf",
                $e->getMessage(),
            );
        } finally {
            $this->removeDir($dest);
        }
    }

    private const string SVG_WITH_SCRIPT = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

    public function test_store_gives_no_extension_to_an_svg_by_default(): void
    {
        $dest = $this->makeTempDir();
        $file = new FileUpload('avatar.png', 'image/png', $this->makeTempFile(self::SVG_WITH_SCRIPT), 20);

        $relative = (new Uploader($dest, fileMover: $this->fileMover()))->store($file);

        self::assertMatchesRegularExpression('#^[a-f0-9]{32}$#', $relative);
    }

    public function test_store_gives_no_extension_to_an_xml_document_by_default(): void
    {
        $dest = $this->makeTempDir();
        $xhtml = '<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml"><script>1</script></html>';
        $file = new FileUpload('notes.xml', 'text/xml', $this->makeTempFile($xhtml), 20);

        $relative = (new Uploader($dest, fileMover: $this->fileMover()))->store($file);

        self::assertMatchesRegularExpression('#^[a-f0-9]{32}$#', $relative);
    }

    public function test_store_gives_an_svg_its_extension_when_the_application_maps_it(): void
    {
        $dest = $this->makeTempDir();
        $file = new FileUpload('logo.svg', 'image/svg+xml', $this->makeTempFile(self::SVG_WITH_SCRIPT), 20);

        $relative = (new Uploader(
            $dest,
            fileMover: $this->fileMover(),
            mimeExtensionMap: ['image/svg+xml' => 'svg'],
        ))->store($file);

        self::assertMatchesRegularExpression('#^[a-f0-9]{32}\.svg$#', $relative);
    }

    public function test_store_refuses_a_target_extension_the_detected_type_does_not_give(): void
    {
        $dest = $this->makeTempDir();
        $file = new FileUpload('avatar.php', 'image/png', $this->makeTempFile($this->pngBytes() . "<?php echo 1;"), 20);

        try {
            (new Uploader($dest, fileMover: $this->fileMover()))->store($file, null, $file->originalName);
            self::fail('expected a refusal');
        } catch (UploadException $e) {
            self::assertSame(
                'Upload target extension "php" does not match the detected MIME type "image/png". Expected extension: png.',
                $e->getMessage(),
            );
        } finally {
            self::assertSame([], glob($dest . '/*') ?: []);
        }
    }

    public function test_store_refuses_a_target_name_with_an_executable_inner_segment(): void
    {
        $dest = $this->makeTempDir();
        $file = new FileUpload('a.png', 'image/png', $this->makeTempFile($this->pngBytes()), 20);

        try {
            (new Uploader($dest, fileMover: $this->fileMover()))->store($file, null, 'shell.PHP.png');
            self::fail('expected a refusal');
        } catch (UploadException $e) {
            self::assertSame(
                'Upload target name carries the executable extension "php" before its final extension.',
                $e->getMessage(),
            );
        } finally {
            self::assertSame([], glob($dest . '/*') ?: []);
        }
    }

    public function test_store_accepts_a_harmless_inner_segment_in_a_target_name(): void
    {
        $dest = $this->makeTempDir();
        $file = new FileUpload('a.pdf', 'application/pdf', $this->makeTempFile($this->pdfBytes()), 20);

        $relative = (new Uploader($dest, fileMover: $this->fileMover()))->store($file, null, 'report.v2.pdf');

        self::assertSame('report.v2.pdf', $relative);
    }

    public function test_store_accepts_a_tar_gz_target_name_for_gzip_bytes(): void
    {
        $dest = $this->makeTempDir();
        $gzip = (string) gzencode('data');
        $file = new FileUpload('a.tar.gz', 'application/gzip', $this->makeTempFile($gzip), 20);

        $relative = (new Uploader($dest, fileMover: $this->fileMover()))->store($file, null, 'archive.tar.gz');

        self::assertSame('archive.tar.gz', $relative);
    }

    public function test_store_refuses_a_target_extension_when_the_detected_type_has_no_known_extension(): void
    {
        $dest = $this->makeTempDir();
        $file = new FileUpload('logo.svg', 'image/svg+xml', $this->makeTempFile(self::SVG_WITH_SCRIPT), 20);

        try {
            (new Uploader($dest, fileMover: $this->fileMover()))->store($file, null, 'logo.svg');
            self::fail('expected a refusal');
        } catch (UploadException $e) {
            self::assertSame(
                'Upload target extension "svg" does not match the detected MIME type "image/svg+xml": '
                . 'no extension is accepted for this type. Map it with $mimeExtensionMap to accept one.',
                $e->getMessage(),
            );
        }
    }

    public function test_store_accepts_a_target_extension_given_by_the_detected_type_in_any_case(): void
    {
        $dest = $this->makeTempDir();
        $file = new FileUpload('a.png', 'image/png', $this->makeTempFile($this->pngBytes()), 20);

        $relative = (new Uploader($dest, fileMover: $this->fileMover()))->store($file, null, 'Avatar.PNG');

        self::assertSame('Avatar.PNG', $relative);
    }

    public function test_store_keeps_a_target_name_without_extension_as_given(): void
    {
        $dest = $this->makeTempDir();
        $file = new FileUpload('a.png', 'image/png', $this->makeTempFile($this->pngBytes()), 20);

        $relative = (new Uploader($dest, fileMover: $this->fileMover()))->store($file, null, 'avatar');

        self::assertSame('avatar', $relative);
    }

    /** Smallest byte sequence libmagic identifies as image/png. */
    private function pngBytes(): string
    {
        return "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89";
    }

    /** @return array<string, array{string, string}> */
    public static function aliasProvider(): array
    {
        return [
            'jpeg on a jpeg' => ['photo.jpeg', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9"],
            'jpe on a jpeg' => ['photo.jpe', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9"],
            'tif on a tiff' => ['scan.tif', "II*\x00\x08\x00\x00\x00\x00\x00"],
            'md on plain text' => ['notes.md', "# Title\n\nSome text\n"],
        ];
    }

    #[DataProvider('aliasProvider')]
    public function test_store_accepts_a_common_alias_of_the_detected_type_in_a_target_name(
        string $targetName,
        string $bytes,
    ): void {
        $dest = $this->makeTempDir();
        $file = new FileUpload($targetName, 'application/octet-stream', $this->makeTempFile($bytes), 20);

        $relative = (new Uploader($dest, fileMover: $this->fileMover()))->store($file, null, $targetName);

        self::assertSame($targetName, $relative);
    }

    public function test_store_lists_every_accepted_extension_when_a_target_extension_is_refused(): void
    {
        $dest = $this->makeTempDir();
        $file = new FileUpload('a.jpg', 'image/jpeg', $this->makeTempFile($this->jpegBytes()), 20);

        try {
            (new Uploader($dest, fileMover: $this->fileMover()))->store($file, null, 'photo.png');
            self::fail('expected a refusal');
        } catch (UploadException $e) {
            self::assertSame(
                'Upload target extension "png" does not match the detected MIME type "image/jpeg". '
                . 'Expected extensions: jpg, jpeg, jpe.',
                $e->getMessage(),
            );
        }
    }

    public function test_store_gives_a_generated_name_the_mapped_extension_and_never_an_alias(): void
    {
        $dest = $this->makeTempDir();
        $file = new FileUpload('photo.jpeg', 'image/jpeg', $this->makeTempFile($this->jpegBytes()), 20);

        $relative = (new Uploader($dest, fileMover: $this->fileMover()))->store($file);

        self::assertMatchesRegularExpression('#^[a-f0-9]{32}\.jpg$#', $relative);
    }
}
