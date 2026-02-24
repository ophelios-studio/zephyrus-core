<?php

declare(strict_types=1);

namespace Tests\Unit\Uploader;

use PHPUnit\Framework\TestCase;
use Zephyrus\Uploader\UploadException;
use Zephyrus\Uploader\UploadedFile;

final class UploadedFileTest extends TestCase
{
    public function testFromFilesArrayBuildsTypedUploadedFile(): void
    {
        $file = UploadedFile::fromFilesArray('avatar', [
            'name' => 'photo.JPG',
            'type' => 'image/jpeg',
            'tmp_name' => '/tmp/php123',
            'error' => UPLOAD_ERR_OK,
            'size' => 1234,
        ]);

        self::assertSame('avatar', $file->field);
        self::assertSame('photo.JPG', $file->originalName);
        self::assertSame('image/jpeg', $file->mimeType);
        self::assertSame('/tmp/php123', $file->tmpPath);
        self::assertSame(1234, $file->size);
        self::assertSame('jpg', $file->clientExtension());
    }

    public function testFromFilesArrayThrowsWhenShapeIsInvalid(): void
    {
        $this->expectException(UploadException::class);

        UploadedFile::fromFilesArray('avatar', ['name' => 'x']);
    }
}
