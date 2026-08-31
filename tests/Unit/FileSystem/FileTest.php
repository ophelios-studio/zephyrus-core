<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\FileSystem;

use PHPUnit\Framework\TestCase;
use Zephyrus\FileSystem\File;
use Zephyrus\FileSystem\FileSystemException;
use Zephyrus\FileSystem\FileSystemNode;

final class FileTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/zephyrus-file-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->cleanDir($this->tempDir);
    }

    public function testExtendsFileSystemNode(): void
    {
        $file = new File($this->tempDir . '/test.txt');
        self::assertInstanceOf(FileSystemNode::class, $file);
    }

    public function testCreateCreatesNewFile(): void
    {
        $path = $this->tempDir . '/created.txt';
        $file = File::create($path, 'hello');

        self::assertTrue($file->exists());
        self::assertSame('hello', $file->read());
    }

    public function testCreateCreatesParentDirectories(): void
    {
        $path = $this->tempDir . '/sub/dir/file.txt';
        $file = File::create($path, 'nested');

        self::assertTrue($file->exists());
        self::assertSame('nested', $file->read());
    }

    public function testCreateWithEmptyContent(): void
    {
        $file = File::create($this->tempDir . '/empty.txt');
        self::assertSame('', $file->read());
    }

    public function testReadReturnsContent(): void
    {
        $path = $this->tempDir . '/read.txt';
        file_put_contents($path, 'content');

        $file = new File($path);
        self::assertSame('content', $file->read());
    }

    public function testReadThrowsForMissingFile(): void
    {
        $file = new File($this->tempDir . '/missing.txt');

        $this->expectException(FileSystemException::class);
        $file->read();
    }

    public function testWriteOverwritesContent(): void
    {
        $path = $this->tempDir . '/write.txt';
        file_put_contents($path, 'old');

        $file = new File($path);
        $file->write('new');
        self::assertSame('new', file_get_contents($path));
    }

    public function testAppendAddsContent(): void
    {
        $path = $this->tempDir . '/append.txt';
        file_put_contents($path, 'hello');

        $file = new File($path);
        $file->append(' world');
        self::assertSame('hello world', file_get_contents($path));
    }

    public function testSizeReturnsFileSize(): void
    {
        $path = $this->tempDir . '/size.txt';
        file_put_contents($path, 'twelve chars');

        $file = new File($path);
        self::assertSame(strlen('twelve chars'), $file->size());
    }

    public function testSizeThrowsForMissingFile(): void
    {
        $file = new File($this->tempDir . '/missing.txt');

        $this->expectException(FileSystemException::class);
        $file->size();
    }

    public function testMimeTypeReturnsCorrectType(): void
    {
        $path = $this->tempDir . '/test.txt';
        file_put_contents($path, 'plain text content');

        $file = new File($path);
        self::assertStringContainsString('text', $file->mimeType());
    }

    public function testExtensionReturnsFileExtension(): void
    {
        $file = new File('/path/to/file.txt');
        self::assertSame('txt', $file->extension());

        $file2 = new File('/path/to/archive.tar.gz');
        self::assertSame('gz', $file2->extension());

        $file3 = new File('/path/to/noext');
        self::assertSame('', $file3->extension());
    }

    public function testHashReturnsConsistentHash(): void
    {
        $path = $this->tempDir . '/hash.txt';
        file_put_contents($path, 'hash me');

        $file = new File($path);
        $hash1 = $file->hash();
        $hash2 = $file->hash();

        self::assertSame($hash1, $hash2);
        self::assertSame(hash('sha256', 'hash me'), $hash1);
    }

    public function testHashWithCustomAlgorithm(): void
    {
        $path = $this->tempDir . '/hash-md5.txt';
        file_put_contents($path, 'test');

        $file = new File($path);
        self::assertSame(md5('test'), $file->hash('md5'));
    }

    public function testCopyCreatesNewFile(): void
    {
        $src = $this->tempDir . '/original.txt';
        file_put_contents($src, 'original content');

        $file = new File($src);
        $copy = $file->copy($this->tempDir . '/copy.txt');

        self::assertTrue($copy->exists());
        self::assertSame('original content', $copy->read());
        self::assertTrue($file->exists()); // Original still exists.
    }

    public function testCopyCreatesParentDirectories(): void
    {
        $src = $this->tempDir . '/src.txt';
        file_put_contents($src, 'content');

        $file = new File($src);
        $copy = $file->copy($this->tempDir . '/sub/dir/copy.txt');

        self::assertTrue($copy->exists());
    }

    public function testMoveMovesFile(): void
    {
        $src = $this->tempDir . '/moveme.txt';
        file_put_contents($src, 'movable');

        $file = new File($src);
        $moved = $file->move($this->tempDir . '/moved.txt');

        self::assertTrue($moved->exists());
        self::assertSame('movable', $moved->read());
        self::assertFalse(file_exists($src)); // Original is gone.
    }

    public function testDeleteRemovesFile(): void
    {
        $path = $this->tempDir . '/delete-me.txt';
        file_put_contents($path, 'bye');

        $file = new File($path);
        $file->delete();

        self::assertFalse(file_exists($path));
    }

    public function testDeleteIsIdempotent(): void
    {
        $file = new File($this->tempDir . '/already-gone.txt');
        $file->delete(); // Should not throw.
        self::assertFalse($file->exists());
    }

    public function testLinesReturnsArrayOfLines(): void
    {
        $path = $this->tempDir . '/lines.txt';
        file_put_contents($path, "line1\nline2\nline3");

        $file = new File($path);
        $lines = $file->lines();

        self::assertSame(['line1', 'line2', 'line3'], $lines);
    }

    public function testLinesWithoutTrimming(): void
    {
        $path = $this->tempDir . '/lines-raw.txt';
        file_put_contents($path, "line1\nline2\n");

        $file = new File($path);
        $lines = $file->lines(trimNewlines: false);

        self::assertSame("line1\n", $lines[0]);
        self::assertSame("line2\n", $lines[1]);
    }

    public function testPathReturnsPath(): void
    {
        $file = new File('/some/path.txt');
        self::assertSame('/some/path.txt', $file->path());
    }

    public function testNameReturnsBasename(): void
    {
        $file = new File('/some/path/file.txt');
        self::assertSame('file.txt', $file->name());
    }

    public function testParentReturnsDirectory(): void
    {
        $file = new File('/some/path/file.txt');
        self::assertSame('/some/path', $file->parent());
    }

    public function testExistsWorks(): void
    {
        $path = $this->tempDir . '/exists.txt';
        $file = new File($path);
        self::assertFalse($file->exists());

        file_put_contents($path, 'yes');
        self::assertTrue($file->exists());
    }

    public function testPermissionsReturnsOctal(): void
    {
        $path = $this->tempDir . '/perms.txt';
        file_put_contents($path, 'test');
        chmod($path, 0644);

        $file = new File($path);
        self::assertSame(0644, $file->permissions());
    }

    public function testLastModifiedReturnsTimestamp(): void
    {
        $path = $this->tempDir . '/mtime.txt';
        file_put_contents($path, 'test');

        $file = new File($path);
        $mtime = $file->lastModified();

        self::assertIsInt($mtime);
        self::assertGreaterThan(0, $mtime);
    }

    public function testIsReadableAndWritable(): void
    {
        $path = $this->tempDir . '/rw.txt';
        file_put_contents($path, 'test');

        $file = new File($path);
        self::assertTrue($file->isReadable());
        self::assertTrue($file->isWritable());
    }

    // ------------------------------------------------------------------
    // Permissions do not depend on the process umask
    // ------------------------------------------------------------------

    public function testCreateAppliesRestrictivePermissionsUnderAPermissiveUmask(): void
    {
        $previous = umask(0);
        $path = $this->tempDir . '/umask-create.txt';

        try {
            File::create($path, 'content');

            self::assertSame(0644, fileperms($path) & 0777);
        } finally {
            umask($previous);
        }
    }

    public function testWriteAppliesRestrictivePermissionsUnderAPermissiveUmask(): void
    {
        $previous = umask(0);
        $path = $this->tempDir . '/umask-write.txt';

        try {
            (new File($path))->write('content');

            self::assertSame(0644, fileperms($path) & 0777);
        } finally {
            umask($previous);
        }
    }

    public function testWriteLeavesAnExistingFilePermissionsAlone(): void
    {
        $path = $this->tempDir . '/preset.txt';
        file_put_contents($path, 'old');
        chmod($path, 0600);

        (new File($path))->write('new');

        self::assertSame(0600, fileperms($path) & 0777);
        self::assertSame('new', (string) file_get_contents($path));
    }

    // ------------------------------------------------------------------
    // Replacing content never follows a symbolic link
    // ------------------------------------------------------------------

    public function testWriteReplacesASymlinkInsteadOfClobberingItsTarget(): void
    {
        $target = $this->tempDir . '/target.txt';
        $link = $this->tempDir . '/link.txt';
        file_put_contents($target, 'SERVER SIDE SECRET');
        symlink($target, $link);

        (new File($link))->write('attacker content');

        self::assertSame('SERVER SIDE SECRET', (string) file_get_contents($target));
        self::assertFalse(is_link($link));
        self::assertSame('attacker content', (string) file_get_contents($link));
    }

    public function testCopyReplacesASymlinkedDestinationInsteadOfClobberingItsTarget(): void
    {
        $source = $this->tempDir . '/source.txt';
        $target = $this->tempDir . '/copy-target.txt';
        $link = $this->tempDir . '/copy-link.txt';
        file_put_contents($source, 'copied content');
        file_put_contents($target, 'SERVER SIDE SECRET');
        symlink($target, $link);

        (new File($source))->copy($link);

        self::assertSame('SERVER SIDE SECRET', (string) file_get_contents($target));
        self::assertFalse(is_link($link));
        self::assertSame('copied content', (string) file_get_contents($link));
    }

    public function testCreateReplacesASymlinkInsteadOfClobberingItsTarget(): void
    {
        $target = $this->tempDir . '/create-target.txt';
        $link = $this->tempDir . '/create-link.txt';
        file_put_contents($target, 'SERVER SIDE SECRET');
        symlink($target, $link);

        File::create($link, 'attacker content');

        self::assertSame('SERVER SIDE SECRET', (string) file_get_contents($target));
        self::assertFalse(is_link($link));
    }

    private function cleanDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
