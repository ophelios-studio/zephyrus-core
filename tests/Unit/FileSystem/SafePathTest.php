<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\FileSystem;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\FileSystem\SafePath;

final class SafePathTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/zsp-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0755);
        file_put_contents($this->root . '/a.txt', 'content');
    }

    protected function tearDown(): void
    {
        foreach ([$this->root . '/link', $this->root . '/a.txt', $this->root . '-evil/secret.txt'] as $entry) {
            @unlink($entry);
        }
        @rmdir($this->root . '-evil');
        @rmdir($this->root);
    }

    public function testARootHoldingANulByteResolvesToNull(): void
    {
        self::assertNull(SafePath::within("/tmp\0x", 'a'));
    }

    public function testAFileInsideAValidRootResolvesToItsRealPath(): void
    {
        self::assertSame(realpath($this->root . '/a.txt'), SafePath::within($this->root, 'a.txt'));
    }

    public function testStreamWrapperUrlsAreToldFromLocalPaths(): void
    {
        self::assertTrue(SafePath::isStreamWrapperUrl('phar:///a/c.yml'));
        self::assertTrue(SafePath::isStreamWrapperUrl('data:,x'));
        self::assertFalse(SafePath::isStreamWrapperUrl('a://c.yml'));
        self::assertFalse(SafePath::isStreamWrapperUrl('C:\\dir\\c.yml'));
        self::assertFalse(SafePath::isStreamWrapperUrl('/srv/app/c.yml'));
        self::assertSame('phar://', SafePath::streamWrapperScheme('phar:///a/c.yml'));
        self::assertSame('data:', SafePath::streamWrapperScheme('data:,x'));
        self::assertNull(SafePath::streamWrapperScheme('/srv/app/c.yml'));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function relativePaths(): array
    {
        return [
            'a plain file' => ['a/b.txt', true],
            'dots inside a name' => ['a/..b/c..d', true],
            'a single dot segment' => ['./a.txt', true],
            'empty' => ['', false],
            'a NUL byte' => ["a\0.txt", false],
            'a leading parent segment' => ['../a.txt', false],
            'a middle parent segment' => ['a/../b', false],
            'a trailing parent segment' => ['a/..', false],
            'the parent segment alone' => ['..', false],
            'a backslash parent segment' => ['a\\..\\b', false],
            'a parent segment after repeated separators' => ['a//..//b', false],
        ];
    }

    #[DataProvider('relativePaths')]
    public function testOnlyAPathFreeOfParentSegmentsAndNulBytesIsASafeRelativePath(string $path, bool $safe): void
    {
        self::assertSame($safe, SafePath::isSafeRelativePath($path));
    }

    public function testAParentSegmentIsRefusedEvenWhenItLandsInsideTheRoot(): void
    {
        mkdir($this->root . '/sub');

        try {
            self::assertNull(SafePath::within($this->root, 'sub/../a.txt'));
        } finally {
            rmdir($this->root . '/sub');
        }
    }

    public function testASymlinkToASiblingDirectorySharingTheRootPrefixIsRefused(): void
    {
        mkdir($this->root . '-evil');
        file_put_contents($this->root . '-evil/secret.txt', 'secret');
        symlink($this->root . '-evil', $this->root . '/link');

        self::assertNull(SafePath::within($this->root, 'link/secret.txt'));
    }

    public function testARootWrittenWithATrailingSeparatorResolvesTheSamePath(): void
    {
        self::assertSame(realpath($this->root . '/a.txt'), SafePath::within($this->root . '/', 'a.txt'));
        self::assertSame(realpath($this->root . '/a.txt'), SafePath::within($this->root, '/a.txt'));
    }

    public function testTheRootItselfIsNotInsideItself(): void
    {
        self::assertNull(SafePath::within($this->root, '.'));
    }

    public function testFileDirectoryAndReadableChecksRefuseMissingPathsAndNulBytes(): void
    {
        $file = $this->root . '/a.txt';

        self::assertTrue(SafePath::isFile($file));
        self::assertFalse(SafePath::isFile($this->root));
        self::assertFalse(SafePath::isFile($this->root . '/none'));
        self::assertTrue(SafePath::isDirectory($this->root));
        self::assertFalse(SafePath::isDirectory($file));
        self::assertTrue(SafePath::isReadable($file));
        self::assertFalse(SafePath::isReadable($this->root . '/none'));
        self::assertFalse(SafePath::isReadable($file . "\0"));
        self::assertFalse(SafePath::isFile($file . "\0"));
        self::assertFalse(SafePath::isDirectory($this->root . "\0"));
    }
}
