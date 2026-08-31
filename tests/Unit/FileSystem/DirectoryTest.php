<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\FileSystem;

use PHPUnit\Framework\TestCase;
use Zephyrus\FileSystem\Directory;
use Zephyrus\FileSystem\File;
use Zephyrus\FileSystem\FileSystemException;
use Zephyrus\FileSystem\FileSystemNode;

final class DirectoryTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/zephyrus-dir-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->cleanDir($this->tempDir);
    }

    public function testExtendsFileSystemNode(): void
    {
        $dir = new Directory($this->tempDir);
        self::assertInstanceOf(FileSystemNode::class, $dir);
    }

    public function testEnsureCreatesDirectoryIfMissing(): void
    {
        $path = $this->tempDir . '/ensure/nested';
        self::assertDirectoryDoesNotExist($path);

        $dir = Directory::ensure($path);
        self::assertDirectoryExists($path);
        self::assertInstanceOf(Directory::class, $dir);
    }

    public function testEnsureIsIdempotent(): void
    {
        $dir = Directory::ensure($this->tempDir);
        self::assertDirectoryExists($this->tempDir);
        self::assertInstanceOf(Directory::class, $dir);
    }

    public function testFilesReturnsFileObjects(): void
    {
        file_put_contents($this->tempDir . '/a.txt', 'a');
        file_put_contents($this->tempDir . '/b.txt', 'b');
        mkdir($this->tempDir . '/subdir');

        $dir = new Directory($this->tempDir);
        $files = $dir->files('*.txt');

        self::assertCount(2, $files);
        self::assertContainsOnlyInstancesOf(File::class, $files);
    }

    public function testFilesWithDefaultPattern(): void
    {
        file_put_contents($this->tempDir . '/a.txt', 'a');
        file_put_contents($this->tempDir . '/b.php', 'b');

        $dir = new Directory($this->tempDir);
        $files = $dir->files();

        self::assertCount(2, $files);
    }

    public function testFilesExcludesDirectories(): void
    {
        file_put_contents($this->tempDir . '/file.txt', 'content');
        mkdir($this->tempDir . '/subdir');

        $dir = new Directory($this->tempDir);
        $files = $dir->files();

        self::assertCount(1, $files);
        self::assertSame('file.txt', $files[0]->name());
    }

    public function testDirectoriesReturnsSubdirs(): void
    {
        mkdir($this->tempDir . '/sub1');
        mkdir($this->tempDir . '/sub2');
        file_put_contents($this->tempDir . '/file.txt', 'content');

        $dir = new Directory($this->tempDir);
        $dirs = $dir->directories();

        self::assertCount(2, $dirs);
        self::assertContainsOnlyInstancesOf(Directory::class, $dirs);
    }

    public function testGlobReturnsMatchingPaths(): void
    {
        file_put_contents($this->tempDir . '/a.txt', 'a');
        file_put_contents($this->tempDir . '/b.php', 'b');

        $dir = new Directory($this->tempDir);
        $matches = $dir->glob('*.txt');

        self::assertCount(1, $matches);
        self::assertStringEndsWith('a.txt', $matches[0]);
    }

    public function testRecursiveGlobFindsNestedFiles(): void
    {
        file_put_contents($this->tempDir . '/top.txt', 'top');
        mkdir($this->tempDir . '/sub');
        file_put_contents($this->tempDir . '/sub/nested.txt', 'nested');
        mkdir($this->tempDir . '/sub/deep');
        file_put_contents($this->tempDir . '/sub/deep/deep.txt', 'deep');

        $dir = new Directory($this->tempDir);
        $matches = $dir->recursiveGlob('*.txt');

        self::assertCount(3, $matches);
    }

    public function testCreateCreatesDirectory(): void
    {
        $path = $this->tempDir . '/new-dir';
        $dir = new Directory($path);

        self::assertDirectoryDoesNotExist($path);
        $dir->create();
        self::assertDirectoryExists($path);
    }

    public function testCreateIsIdempotent(): void
    {
        $dir = new Directory($this->tempDir);
        $dir->create(); // Should not throw.
        self::assertDirectoryExists($this->tempDir);
    }

    public function testDeleteRemovesEmptyDirectory(): void
    {
        $path = $this->tempDir . '/empty-dir';
        mkdir($path);

        $dir = new Directory($path);
        $dir->delete();
        self::assertDirectoryDoesNotExist($path);
    }

    public function testDeleteRecursiveRemovesContents(): void
    {
        $path = $this->tempDir . '/full-dir';
        mkdir($path);
        file_put_contents($path . '/file.txt', 'content');
        mkdir($path . '/sub');
        file_put_contents($path . '/sub/nested.txt', 'nested');

        $dir = new Directory($path);
        $dir->delete(recursive: true);
        self::assertDirectoryDoesNotExist($path);
    }

    public function testDeleteIsIdempotent(): void
    {
        $path = $this->tempDir . '/nonexistent';
        $dir = new Directory($path);
        $dir->delete(); // Should not throw.
        self::assertDirectoryDoesNotExist($path);
    }

    public function testDeleteNonRecursiveThrowsForNonEmpty(): void
    {
        $path = $this->tempDir . '/non-empty';
        mkdir($path);
        file_put_contents($path . '/file.txt', 'content');

        $dir = new Directory($path);

        $this->expectException(FileSystemException::class);
        $dir->delete(recursive: false);
    }

    public function testSizeReturnsRecursiveSize(): void
    {
        file_put_contents($this->tempDir . '/a.txt', 'aaaa'); // 4 bytes
        mkdir($this->tempDir . '/sub');
        file_put_contents($this->tempDir . '/sub/b.txt', 'bb'); // 2 bytes

        $dir = new Directory($this->tempDir);
        self::assertSame(6, $dir->size());
    }

    public function testSizeOfEmptyDirectory(): void
    {
        $path = $this->tempDir . '/empty';
        mkdir($path);

        $dir = new Directory($path);
        self::assertSame(0, $dir->size());
    }

    public function testIsEmptyReturnsTrueForEmptyDir(): void
    {
        $path = $this->tempDir . '/empty';
        mkdir($path);

        $dir = new Directory($path);
        self::assertTrue($dir->isEmpty());
    }

    public function testIsEmptyReturnsFalseForNonEmptyDir(): void
    {
        file_put_contents($this->tempDir . '/file.txt', 'content');

        $dir = new Directory($this->tempDir);
        self::assertFalse($dir->isEmpty());
    }

    public function testPathReturnsPath(): void
    {
        $dir = new Directory('/some/path');
        self::assertSame('/some/path', $dir->path());
    }

    public function testNameReturnsBasename(): void
    {
        $dir = new Directory('/some/path/dir');
        self::assertSame('dir', $dir->name());
    }

    public function testParentReturnsParent(): void
    {
        $dir = new Directory('/some/path/dir');
        self::assertSame('/some/path', $dir->parent());
    }

    public function testExistsWorks(): void
    {
        $dir = new Directory($this->tempDir);
        self::assertTrue($dir->exists());

        $missing = new Directory($this->tempDir . '/nonexistent');
        self::assertFalse($missing->exists());
    }

    public function testFilesThrowsForMissingDir(): void
    {
        $dir = new Directory($this->tempDir . '/missing');

        $this->expectException(FileSystemException::class);
        $dir->files();
    }

    // ------------------------------------------------------------------
    // A recursive delete that fails must say so
    // ------------------------------------------------------------------

    public function testDeleteRecursiveThrowsWhenAnEntryCannotBeRemoved(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Root ignores directory permissions.');
        }

        $root = $this->tempDir . '/purge';
        mkdir($root . '/locked', 0755, true);
        file_put_contents($root . '/locked/subject-data.txt', 'PERSONAL INFORMATION');
        // The parent is not writable, so the child cannot be unlinked.
        chmod($root . '/locked', 0555);

        try {
            (new Directory($root))->delete(recursive: true);
            self::fail('A recursive delete that leaves data on disk must not report success.');
        } catch (FileSystemException $exception) {
            self::assertStringContainsString('subject-data.txt', $exception->getMessage());
            self::assertFileExists($root . '/locked/subject-data.txt');
        } finally {
            chmod($root . '/locked', 0755);
            $this->cleanDir($root);
        }
    }

    public function testDeleteRecursiveRemovesASymlinkedChildWithoutFollowingIt(): void
    {
        $root = $this->tempDir . '/purge-link';
        $outside = $this->tempDir . '/keep';
        mkdir($root, 0755, true);
        mkdir($outside, 0755, true);
        file_put_contents($outside . '/precious.txt', 'KEEP ME');
        symlink($outside, $root . '/shortcut');

        (new Directory($root))->delete(recursive: true);

        self::assertDirectoryDoesNotExist($root);
        self::assertFileExists($outside . '/precious.txt', 'The link target must be left alone.');
    }

    public function testDeleteRecursiveStillRemovesAWritableTree(): void
    {
        $root = $this->tempDir . '/ok';
        mkdir($root . '/a/b', 0755, true);
        file_put_contents($root . '/a/b/deep.txt', 'x');
        file_put_contents($root . '/top.txt', 'y');

        (new Directory($root))->delete(recursive: true);

        self::assertDirectoryDoesNotExist($root);
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
