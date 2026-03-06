<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Upload;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Upload\FileUpload;
use Zephyrus\Upload\UploadException;

final class FileUploadTest extends TestCase
{
    // ------------------------------------------------------------------
    // Constructor properties
    // ------------------------------------------------------------------

    public function test_constructor_exposes_all_properties(): void
    {
        $file = new FileUpload('photo.JPG', 'image/jpeg', '/tmp/php123', 4096, UPLOAD_ERR_OK);

        self::assertSame('photo.JPG', $file->originalName);
        self::assertSame('image/jpeg', $file->clientMimeType);
        self::assertSame('/tmp/php123', $file->tmpPath);
        self::assertSame(4096, $file->sizeBytes);
        self::assertSame(UPLOAD_ERR_OK, $file->errorCode);
    }

    public function test_error_code_defaults_to_upload_err_ok(): void
    {
        $file = new FileUpload('x.txt', 'text/plain', '/tmp/x', 10);

        self::assertSame(UPLOAD_ERR_OK, $file->errorCode);
    }

    // ------------------------------------------------------------------
    // fromPhpArray
    // ------------------------------------------------------------------

    public function test_from_php_array_builds_complete_value_object(): void
    {
        $file = FileUpload::fromPhpArray([
            'name'     => 'document.pdf',
            'type'     => 'application/pdf',
            'tmp_name' => '/tmp/phpXXX',
            'error'    => UPLOAD_ERR_OK,
            'size'     => 102400,
        ]);

        self::assertSame('document.pdf', $file->originalName);
        self::assertSame('application/pdf', $file->clientMimeType);
        self::assertSame('/tmp/phpXXX', $file->tmpPath);
        self::assertSame(102400, $file->sizeBytes);
        self::assertSame(UPLOAD_ERR_OK, $file->errorCode);
    }

    public function test_from_php_array_defaults_optional_fields_to_empty_or_zero(): void
    {
        $file = FileUpload::fromPhpArray([
            'tmp_name' => '/tmp/phpAAA',
            'error'    => UPLOAD_ERR_OK,
        ]);

        self::assertSame('', $file->originalName);
        self::assertSame('', $file->clientMimeType);
        self::assertSame(0, $file->sizeBytes);
    }

    public function test_from_php_array_casts_string_inputs_to_correct_types(): void
    {
        $file = FileUpload::fromPhpArray([
            'name'     => 42,
            'tmp_name' => '/tmp/php456',
            'error'    => '0',
            'size'     => '2048',
        ]);

        self::assertSame('42', $file->originalName);
        self::assertSame(0, $file->errorCode);
        self::assertSame(2048, $file->sizeBytes);
    }

    public function test_from_php_array_throws_when_tmp_name_key_is_absent(): void
    {
        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('tmp_name');

        FileUpload::fromPhpArray(['error' => UPLOAD_ERR_OK]);
    }

    public function test_from_php_array_throws_when_error_key_is_absent(): void
    {
        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('error');

        FileUpload::fromPhpArray(['tmp_name' => '/tmp/x']);
    }

    public function test_from_php_array_throws_when_array_is_empty(): void
    {
        $this->expectException(UploadException::class);

        FileUpload::fromPhpArray([]);
    }

    public function test_list_from_php_array_wraps_single_entry(): void
    {
        $files = FileUpload::listFromPhpArray([
            'name' => 'avatar.png',
            'type' => 'image/png',
            'tmp_name' => '/tmp/php-single',
            'error' => UPLOAD_ERR_OK,
            'size' => 100,
        ]);

        self::assertCount(1, $files);
        self::assertSame('avatar.png', $files[0]->originalName);
        self::assertSame('/tmp/php-single', $files[0]->tmpPath);
    }

    public function test_list_from_php_array_flattens_multiple_files(): void
    {
        $files = FileUpload::listFromPhpArray([
            'name' => ['first.png', 'second.jpg'],
            'type' => ['image/png', 'image/jpeg'],
            'tmp_name' => ['/tmp/php-a', '/tmp/php-b'],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
            'size' => [10, 20],
        ]);

        self::assertCount(2, $files);
        self::assertSame('first.png', $files[0]->originalName);
        self::assertSame('second.jpg', $files[1]->originalName);
        self::assertSame('/tmp/php-a', $files[0]->tmpPath);
        self::assertSame('/tmp/php-b', $files[1]->tmpPath);
    }

    public function test_list_from_php_array_flattens_nested_named_files(): void
    {
        $files = FileUpload::listFromPhpArray([
            'name' => ['contracts' => ['a.pdf', 'b.pdf']],
            'type' => ['contracts' => ['application/pdf', 'application/pdf']],
            'tmp_name' => ['contracts' => ['/tmp/php-c1', '/tmp/php-c2']],
            'error' => ['contracts' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK]],
            'size' => ['contracts' => [11, 12]],
        ]);

        self::assertCount(2, $files);
        self::assertSame('a.pdf', $files[0]->originalName);
        self::assertSame('b.pdf', $files[1]->originalName);
    }

    public function test_list_from_php_array_skips_malformed_nested_branches(): void
    {
        $files = FileUpload::listFromPhpArray([
            'name' => ['x.txt', 'y.txt'],
            'tmp_name' => ['/tmp/php-x'],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
            'size' => [1, 2],
        ]);

        self::assertCount(1, $files);
        self::assertSame('/tmp/php-x', $files[0]->tmpPath);
    }

    // ------------------------------------------------------------------
    // extension()
    // ------------------------------------------------------------------

    public function test_extension_lowercases_the_extension(): void
    {
        $file = new FileUpload('Photo.JPG', 'image/jpeg', '/tmp/x', 0);

        self::assertSame('jpg', $file->extension());
    }

    public function test_extension_preserves_lowercase(): void
    {
        $file = new FileUpload('archive.tar.gz', 'application/gzip', '/tmp/x', 0);

        self::assertSame('gz', $file->extension());
    }

    public function test_extension_returns_empty_string_for_file_without_extension(): void
    {
        $file = new FileUpload('Makefile', 'text/plain', '/tmp/x', 0);

        self::assertSame('', $file->extension());
    }

    public function test_extension_returns_extension_for_dotfile(): void
    {
        // PHP's pathinfo treats ".htaccess" as basename="" + extension="htaccess".
        $file = new FileUpload('.htaccess', 'text/plain', '/tmp/x', 0);

        self::assertSame('htaccess', $file->extension());
    }

    public function test_extension_returns_empty_string_for_empty_original_name(): void
    {
        $file = new FileUpload('', 'application/octet-stream', '/tmp/x', 0);

        self::assertSame('', $file->extension());
    }

    // ------------------------------------------------------------------
    // isValid()
    // ------------------------------------------------------------------

    public function test_is_valid_returns_true_when_error_is_ok(): void
    {
        $file = new FileUpload('x.bin', '', '/tmp/x', 0, UPLOAD_ERR_OK);

        self::assertTrue($file->isValid());
    }

    public function test_is_valid_returns_false_when_error_is_not_ok(): void
    {
        $file = new FileUpload('x.bin', '', '/tmp/x', 0, UPLOAD_ERR_PARTIAL);

        self::assertFalse($file->isValid());
    }

    // ------------------------------------------------------------------
    // assertValid()
    // ------------------------------------------------------------------

    public function test_assert_valid_does_not_throw_for_upload_err_ok(): void
    {
        $file = new FileUpload('x.bin', '', '/tmp/x', 0, UPLOAD_ERR_OK);

        // Must not throw.
        $file->assertValid();
        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{int, string}> */
    public static function uploadErrorCodeProvider(): array
    {
        return [
            'ini_size'           => [UPLOAD_ERR_INI_SIZE,   'upload_max_filesize'],
            'form_size'          => [UPLOAD_ERR_FORM_SIZE,   'MAX_FILE_SIZE'],
            'partial'            => [UPLOAD_ERR_PARTIAL,     'partially uploaded'],
            'no_file'            => [UPLOAD_ERR_NO_FILE,     'No file was uploaded'],
            'no_tmp_dir'         => [UPLOAD_ERR_NO_TMP_DIR,  'temporary folder'],
            'cant_write'         => [UPLOAD_ERR_CANT_WRITE,  'write'],
            'extension_blocked'  => [UPLOAD_ERR_EXTENSION,   'PHP extension'],
            'unknown_code'       => [99,                     'code 99'],
        ];
    }

    #[DataProvider('uploadErrorCodeProvider')]
    public function test_assert_valid_throws_with_meaningful_message(int $code, string $expectedSubstring): void
    {
        $file = new FileUpload('x.bin', '', '/tmp/x', 0, $code);

        try {
            $file->assertValid();
            self::fail('Expected UploadException was not thrown.');
        } catch (UploadException $e) {
            self::assertStringContainsString($expectedSubstring, $e->getMessage());
        }
    }
}
