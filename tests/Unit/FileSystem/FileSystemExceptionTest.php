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
        self::assertSame('Path not found: "/missing/path"', $exception->getMessage());
    }

    public function testNotReadable(): void
    {
        $exception = FileSystemException::notReadable('/path');
        self::assertSame('Path is not readable: "/path"', $exception->getMessage());
    }

    public function testNotWritable(): void
    {
        $exception = FileSystemException::notWritable('/path');
        self::assertSame('Path is not writable: "/path"', $exception->getMessage());
    }

    public function testOperationFailed(): void
    {
        $exception = FileSystemException::operationFailed('delete', '/path/to/file');
        self::assertSame('Failed to delete: "/path/to/file"', $exception->getMessage());
    }

    public function testStreamWrapperRefused(): void
    {
        $exception = FileSystemException::streamWrapperRefused('phar:///a/b.phar/c');
        self::assertSame('Path is a stream wrapper URL, which is not written: "phar:///a/b.phar/c"', $exception->getMessage());
    }

    public function testOperationFailedWithAReasonQuotesItAfterThePath(): void
    {
        $exception = FileSystemException::operationFailed('write to', '/path/to/file', reason: 'No space left on device');
        self::assertSame('Failed to write to: "/path/to/file" ("No space left on device")', $exception->getMessage());
    }

    public function testOperationFailedWithoutAReasonNamesOnlyThePath(): void
    {
        $exception = FileSystemException::operationFailed('write to', '/path/to/file');
        self::assertSame('Failed to write to: "/path/to/file"', $exception->getMessage());
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
        self::assertSame('Path already exists: "/existing/path"', $exception->getMessage());
    }

    public function testALineBreakInAPathShowsEscaped(): void
    {
        $exception = FileSystemException::notFound("/tmp/a\r\nForged: line");
        self::assertSame('Path not found: "/tmp/a\\r\\nForged: line"', $exception->getMessage());
    }

    public function testAPathOver512BytesKeepsItsEndFollowedByItsLength(): void
    {
        $path = '/start' . str_repeat('d/', 300) . 'end.txt';
        $exception = FileSystemException::notFound($path);
        self::assertSame(
            'Path not found: "...' . substr($path, -512) . '" (' . strlen($path) . ' bytes)',
            $exception->getMessage(),
        );
    }

    public function testAReasonHoldingALineBreakShowsEscaped(): void
    {
        $exception = FileSystemException::operationFailed('write to', '/p', reason: "boom\nForged: line");
        self::assertSame('Failed to write to: "/p" ("boom\\nForged: line")', $exception->getMessage());
    }

    public function testATransferFailedQuotesAndEscapesEachPath(): void
    {
        $exception = FileSystemException::transferFailed('copy', "/a\nb", '/c');
        self::assertSame('Failed to copy "/a\nb" to "/c"', $exception->getMessage());
    }

    public function testATransferFailedWithAReasonQuotesItLast(): void
    {
        $exception = FileSystemException::transferFailed('copy', '/a', '/c', "x\ny");
        self::assertSame('Failed to copy "/a" to "/c" ("x\ny")', $exception->getMessage());
    }

    public function testAReasonOver256BytesKeepsItsStartAndEndFollowedByItsLength(): void
    {
        $reason = 'mkdir(): open_basedir restriction in effect. File(/srv/data/' . str_repeat('a', 200)
            . ') is not within the allowed path(s): (' . str_repeat('/opt/x:', 40) . ')';
        $exception = FileSystemException::operationFailed('create the directory', '/p', reason: $reason);
        self::assertSame(
            'Failed to create the directory: "/p" ("' . substr($reason, 0, 128) . '...' . substr($reason, -128)
                . '" (' . strlen($reason) . ' bytes))',
            $exception->getMessage(),
        );
    }

    public function testATransferReasonOver256BytesKeepsItsStartAndEndFollowedByItsLength(): void
    {
        $reason = 'rename(/srv/data/' . str_repeat('a', 300) . ',/srv/out/b): Permission denied';
        $exception = FileSystemException::transferFailed('move', '/a', '/c', $reason);
        self::assertSame(
            'Failed to move "/a" to "/c" ("' . substr($reason, 0, 128) . '...' . substr($reason, -128)
                . '" (' . strlen($reason) . ' bytes))',
            $exception->getMessage(),
        );
    }

    public function testALockWaitIsWrittenWithoutExponentOrTrailingZeros(): void
    {
        self::assertSame(
            'Lock on the file not acquired within the 10-second wait: "/p"',
            FileSystemException::fileLockTimedOut('/p', 10.0)->getMessage(),
        );
        self::assertSame(
            'Lock on the directory not acquired within the 0.25-second wait: "/d"',
            FileSystemException::directoryLockTimedOut('/d', 0.25)->getMessage(),
        );
        self::assertSame(
            'Lock on the file not acquired within the 0-second wait: "/p"',
            FileSystemException::fileLockTimedOut('/p', 0.0)->getMessage(),
        );
        self::assertSame(
            'Lock on the file not acquired within the 1500000-second wait: "/p"',
            FileSystemException::fileLockTimedOut('/p', 1500000.0)->getMessage(),
        );
    }

    public function testBothPathsOfACopyOntoItselfAreQuoted(): void
    {
        $exception = FileSystemException::copyOntoItself('/a', '/b');
        self::assertSame('Cannot copy a file onto itself: "/a" to "/b"', $exception->getMessage());
    }
}
