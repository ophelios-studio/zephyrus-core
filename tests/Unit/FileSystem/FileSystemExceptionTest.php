<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\FileSystem;

use PHPUnit\Framework\TestCase;
use Zephyrus\Exceptions\ZephyrusRuntimeException;
use Zephyrus\FileSystem\FileSystemException;

final class FileSystemExceptionTest extends TestCase
{
    public function testExtendsZephyrusRuntimeException(): void
    {
        $exception = new FileSystemException('test');
        self::assertInstanceOf(ZephyrusRuntimeException::class, $exception);
    }

    public function testNotFound(): void
    {
        $exception = FileSystemException::notFound('/missing/path');
        self::assertStringContainsString('/missing/path', $exception->getMessage());
    }

    public function testNotReadable(): void
    {
        $exception = FileSystemException::notReadable('/path');
        self::assertStringContainsString('not readable', $exception->getMessage());
    }

    public function testNotWritable(): void
    {
        $exception = FileSystemException::notWritable('/path');
        self::assertStringContainsString('not writable', $exception->getMessage());
    }

    public function testOperationFailed(): void
    {
        $exception = FileSystemException::operationFailed('delete', '/path/to/file');
        self::assertStringContainsString('delete', $exception->getMessage());
        self::assertStringContainsString('/path/to/file', $exception->getMessage());
    }

    public function testOperationFailedWithPrevious(): void
    {
        $previous = new \RuntimeException('disk error');
        $exception = FileSystemException::operationFailed('write', '/path', $previous);
        self::assertSame($previous, $exception->getPrevious());
    }

    public function testAlreadyExists(): void
    {
        $exception = FileSystemException::alreadyExists('/existing/path');
        self::assertStringContainsString('already exists', $exception->getMessage());
    }
}
