<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\FileSystem;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\FileSystem\File;
use Zephyrus\FileSystem\FileSystemException;
use Zephyrus\FileSystem\FileSystemNode;

final class FileTest extends TestCase
{
    private const int ANOTHER_UID = 2000;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/zephyrus-file-test-' . bin2hex(random_bytes(8));
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
    // Permissions never exceed what the process umask allows
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

    public function testCreateNeverWidensTheProcessUmask(): void
    {
        $previous = umask(0077);
        $path = $this->tempDir . '/umask-private.txt';

        try {
            File::create($path, 'content');

            self::assertSame(0600, fileperms($path) & 0777);
        } finally {
            umask($previous);
        }
    }

    public function testWriteNeverWidensTheProcessUmask(): void
    {
        $previous = umask(0027);
        $path = $this->tempDir . '/umask-group.txt';

        try {
            (new File($path))->write('content');

            self::assertSame(0640, fileperms($path) & 0777);
        } finally {
            umask($previous);
        }
    }

    public function testCopyNeverWidensTheProcessUmask(): void
    {
        $source = $this->tempDir . '/umask-source.txt';
        file_put_contents($source, 'content');
        $previous = umask(0077);
        $destination = $this->tempDir . '/umask-copy.txt';

        try {
            (new File($source))->copy($destination);

            self::assertSame(0600, fileperms($destination) & 0777);
        } finally {
            umask($previous);
        }
    }

    public function testCopyOfARestrictedSourceDoesNotWidenItsMode(): void
    {
        $source = $this->tempDir . '/restricted-source.txt';
        $destination = $this->tempDir . '/restricted-copy.txt';
        file_put_contents($source, 'content');
        chmod($source, 0600);
        $previous = umask(0022);

        try {
            (new File($source))->copy($destination);

            self::assertSame(0600, fileperms($destination) & 0777);
        } finally {
            umask($previous);
        }
    }

    public function testCopyOverAnExistingFileLeavesItsPermissionsAlone(): void
    {
        $source = $this->tempDir . '/keep-source.txt';
        $destination = $this->tempDir . '/keep-destination.txt';
        file_put_contents($source, 'new');
        file_put_contents($destination, 'old');
        chmod($destination, 0640);
        $previous = umask(0022);

        try {
            (new File($source))->copy($destination);

            self::assertSame(0640, fileperms($destination) & 0777);
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

    public function testAppendRefusesALinkToAFileWithoutTouchingIt(): void
    {
        $target = $this->tempDir . '/append-target.txt';
        $link = $this->tempDir . '/append-link.txt';
        file_put_contents($target, 'SERVER SIDE SECRET');
        symlink($target, $link);

        $this->assertAppendRefusesTheLink($link);

        self::assertSame('SERVER SIDE SECRET', (string) file_get_contents($target));
    }

    public function testAppendRefusesADanglingLinkWithoutCreatingItsTarget(): void
    {
        $target = $this->tempDir . '/append-target.txt';
        $link = $this->tempDir . '/append-link.txt';
        symlink($target, $link);

        $this->assertAppendRefusesTheLink($link);

        clearstatcache();
        self::assertFileDoesNotExist($target);
    }

    public function testAppendToAMissingFileCreatesItWithTheDefaultPermissions(): void
    {
        $previous = umask(0);
        $path = $this->tempDir . '/append-new.txt';

        try {
            (new File($path))->append('first line');

            self::assertSame('first line', (string) file_get_contents($path));
            self::assertSame(0644, fileperms($path) & 0777);
        } finally {
            umask($previous);
        }
    }

    public function testConcurrentAppendsKeepEveryLineWhole(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            self::markTestSkipped('Needs the pcntl and posix extensions.');
        }
        $path = $this->tempDir . '/journal.log';
        $first = self::journalLines('first');
        $second = self::journalLines('second');

        $writers = [$this->forkAppender($path, $first), $this->forkAppender($path, $second)];
        foreach ($writers as $writer) {
            pcntl_waitpid($writer, $status);
        }

        $expected = [...$first, ...$second];
        $written = (array) file($path, FILE_IGNORE_NEW_LINES);
        sort($expected);
        sort($written);
        self::assertSame($expected, $written);
    }

    public function testWriteNeverClobbersTheTargetOfALinkSwappedInDuringTheCall(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            self::markTestSkipped('Needs the pcntl and posix extensions.');
        }
        $target = $this->tempDir . '/swap-target.txt';
        $path = $this->tempDir . '/swapped.txt';
        file_put_contents($target, 'SERVER SIDE SECRET');
        file_put_contents($path, 'regular');

        $swapper = $this->forkPathSwapper($path, $target);
        try {
            for ($i = 0; $i < 3000; $i++) {
                try {
                    (new File($path))->write('attacker content');
                } catch (FileSystemException) {
                }
            }
        } finally {
            posix_kill($swapper, SIGKILL);
            pcntl_waitpid($swapper, $status);
        }

        self::assertSame('SERVER SIDE SECRET', (string) file_get_contents($target));
    }

    #[DataProvider('writingMethods')]
    public function testALinkPlantedOnAnAbsentPathNeverHasItsTargetCreated(string $method): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            self::markTestSkipped('Needs the pcntl and posix extensions.');
        }
        $path = $this->tempDir . '/planted.txt';
        $target = $this->tempDir . '/planted-target.txt';

        $planter = $this->forkLinkPlanter($path, $target);
        try {
            for ($i = 0; $i < 2000; $i++) {
                @unlink($path);
                try {
                    $this->writeThrough($method, $path);
                } catch (FileSystemException) {
                }
            }
        } finally {
            posix_kill($planter, SIGKILL);
            pcntl_waitpid($planter, $status);
        }

        clearstatcache(true);
        self::assertFileDoesNotExist($target);
        $expected = ['.', '..', 'planted.txt', 'planter-link', 'write-through-source.txt'];
        self::assertSame([], array_values(array_diff((array) scandir($this->tempDir), $expected)));
    }

    public function testWriteRewritesAnExistingFileInPlaceSoItsHardLinksSeeTheNewContent(): void
    {
        $path = $this->tempDir . '/linked.txt';
        $alias = $this->tempDir . '/alias.txt';
        file_put_contents($path, 'old');
        link($path, $alias);

        (new File($path))->write('new');

        self::assertSame('new', (string) file_get_contents($alias));
    }

    #[DataProvider('writingMethods')]
    public function testAnExistingFileTheProcessCannotReadIsRefusedUntouched(string $method): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Root reads any file.');
        }
        $path = $this->tempDir . '/write-only.txt';
        file_put_contents($path, 'kept');
        chmod($path, 0200);

        try {
            $this->writeThrough($method, $path);
            self::fail('expected a refusal');
        } catch (FileSystemException $e) {
            self::assertSame('Path is not readable, which a safe write requires: ' . $path, $e->getMessage());
        }

        chmod($path, 0600);
        self::assertSame('kept', (string) file_get_contents($path));
    }

    #[DataProvider('writingMethods')]
    public function testAFifoIsRefusedAsNotARegularFile(string $method): void
    {
        if (!function_exists('posix_mkfifo')) {
            self::markTestSkipped('Needs the posix extension.');
        }
        $path = $this->tempDir . '/pipe';
        posix_mkfifo($path, 0600);

        try {
            $this->writeThrough($method, $path);
            self::fail('expected a refusal');
        } catch (FileSystemException $e) {
            self::assertSame('Path is not a regular file: ' . $path, $e->getMessage());
        }

        self::assertSame('fifo', filetype($path));
    }

    #[DataProvider('writingMethods')]
    public function testAFileAnotherUserOwnsInAStickyDirectoryIsRefusedUntouched(string $method): void
    {
        $path = $this->fileInSharedDirectory(directoryMode: 0o1777, directoryOwner: 0, fileOwner: self::ANOTHER_UID);

        try {
            $this->writeThrough($method, $path);
            self::fail('expected a refusal');
        } catch (FileSystemException $e) {
            self::assertSame(
                'Path belongs to another user in a shared directory, so it is not written: ' . $path,
                $e->getMessage(),
            );
        }

        self::assertSame('kept', (string) file_get_contents($path));
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function writableSharedFiles(): array
    {
        return [
            'directory without the sticky bit' => [0o777, 0, self::ANOTHER_UID],
            'file of the directory owner' => [0o1777, self::ANOTHER_UID, self::ANOTHER_UID],
            'file of the effective user' => [0o1777, self::ANOTHER_UID, 0],
        ];
    }

    #[DataProvider('writableSharedFiles')]
    public function testASharedFileIsWrittenUnlessAnotherUserOwnsItInAStickyDirectory(
        int $directoryMode,
        int $directoryOwner,
        int $fileOwner,
    ): void {
        $path = $this->fileInSharedDirectory($directoryMode, $directoryOwner, $fileOwner);

        (new File($path))->write('new');

        self::assertSame('new', (string) file_get_contents($path));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function writesCutShort(): array
    {
        return [
            'write' => ['write', 'Failed to write to: {path}'],
            'append' => ['append', 'Failed to append to: {path}'],
            'copy' => ['copy', 'Failed to copy: {source} to {path}'],
        ];
    }

    #[DataProvider('writesCutShort')]
    public function testAWriteCutShortIsAFileSystemExceptionUnderAThrowingErrorHandler(string $method, string $message): void
    {
        if (!function_exists('posix_setrlimit') || !function_exists('pcntl_signal')) {
            self::markTestSkipped('Needs the posix and pcntl extensions.');
        }
        $path = $this->tempDir . '/capped.txt';
        $source = $this->tempDir . '/source.txt';
        file_put_contents($path, '');
        file_put_contents($source, 'more than four bytes');
        @lstat($this->tempDir . '/missing.txt');

        try {
            self::underFileSizeLimit(4, match ($method) {
                'write' => static fn () => (new File($path))->write('more than four bytes'),
                'append' => static fn () => (new File($path))->append('more than four bytes'),
                'copy' => static fn () => (new File($source))->copy($path),
            });
            self::fail('expected a failure');
        } catch (FileSystemException $e) {
            $expected = strtr($message, ['{path}' => $path, '{source}' => $source]);
            self::assertStringStartsWith($expected . ' (', $e->getMessage());
            self::assertStringContainsString('errno=27', $e->getMessage());
            self::assertStringEndsWith(')', $e->getMessage());
            self::assertStringNotContainsString('lstat', $e->getMessage());
        }
    }

    public function testAWriteOfANewFileCutShortNamesTheErrorOfTheWriteAlone(): void
    {
        if (!function_exists('posix_setrlimit') || !function_exists('pcntl_signal')) {
            self::markTestSkipped('Needs the posix and pcntl extensions.');
        }
        $path = $this->tempDir . '/new.txt';

        try {
            self::underFileSizeLimit(4, static fn () => File::create($path, 'more than four bytes'));
            self::fail('expected a failure');
        } catch (FileSystemException $e) {
            self::assertStringStartsWith('Failed to create file: ' . $path . ' (fwrite(): ', $e->getMessage());
            self::assertStringContainsString('errno=27', $e->getMessage());
        }
        self::assertSame([], glob($this->tempDir . '/.zephyrus-*'));
    }

    public function testCopyOfADirectoryIsRefusedBeforeTouchingTheDestination(): void
    {
        $source = $this->tempDir . '/folder';
        mkdir($source);
        $destination = $this->tempDir . '/missing/copy.txt';

        try {
            (new File($source))->copy($destination);
            self::fail('expected a refusal');
        } catch (FileSystemException $e) {
            self::assertSame('Path is not a regular file: ' . $source, $e->getMessage());
        }

        self::assertDirectoryDoesNotExist($this->tempDir . '/missing');
    }

    public function testCopyOntoItselfIsRefusedWithoutEmptyingTheFile(): void
    {
        $path = $this->tempDir . '/self.txt';
        file_put_contents($path, 'content');

        try {
            (new File($path))->copy($path);
            self::fail('expected a refusal');
        } catch (FileSystemException $e) {
            self::assertSame('Cannot copy a file onto itself: ' . $path . ' to ' . $path, $e->getMessage());
        }

        self::assertSame('content', (string) file_get_contents($path));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function writingMethods(): array
    {
        return [
            'create' => ['create'],
            'write' => ['write'],
            'append' => ['append'],
            'copy' => ['copy'],
        ];
    }

    #[DataProvider('writingMethods')]
    public function testALinkNameEndingInASlashIsRefusedAsAFileName(string $method): void
    {
        $target = $this->tempDir . '/slash-target.txt';
        $link = $this->tempDir . '/slash-link.txt';
        file_put_contents($target, 'SERVER SIDE SECRET');
        symlink($target, $link);

        try {
            $this->writeThrough($method, $link . '/');
            self::fail('expected a refusal');
        } catch (FileSystemException $e) {
            self::assertSame('Path does not end with a file name: ' . $link . '/', $e->getMessage());
        }

        self::assertTrue(is_link($link));
        self::assertSame('SERVER SIDE SECRET', (string) file_get_contents($target));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function directorySegments(): array
    {
        return [
            'dot segment' => ['/.'],
            'dot-dot segment' => ['/..'],
        ];
    }

    #[DataProvider('directorySegments')]
    public function testAPathEndingInADotSegmentIsRefusedBeforeCreatingAnyDirectory(string $segment): void
    {
        $path = $this->tempDir . '/missing/parent' . $segment;

        try {
            File::create($path, 'content');
            self::fail('expected a refusal');
        } catch (FileSystemException $e) {
            self::assertSame('Path does not end with a file name: ' . $path, $e->getMessage());
        }

        self::assertDirectoryDoesNotExist($this->tempDir . '/missing');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function streamWrapperPaths(): array
    {
        return [
            'create' => ['create', 'file://{dir}/created.txt'],
            'write' => ['write', 'phar://{dir}/archive.phar/inner.txt'],
            'append' => ['append', 'data://text/plain;base64,AAAA'],
            'copy' => ['copy', 'file://{dir}/copied.txt'],
        ];
    }

    #[DataProvider('streamWrapperPaths')]
    public function testAStreamWrapperPathIsRefusedBeforeCreatingAnything(string $method, string $template): void
    {
        $path = str_replace('{dir}', $this->tempDir . '/missing', $template);

        try {
            $this->writeThrough($method, $path);
            self::fail('expected a refusal');
        } catch (FileSystemException $e) {
            self::assertSame('Path is a stream wrapper URL, which is not written: ' . $path, $e->getMessage());
        }

        self::assertDirectoryDoesNotExist($this->tempDir . '/missing');
        self::assertSame(['write-through-source.txt'], array_values(array_diff((array) scandir($this->tempDir), ['.', '..'])));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function bareSchemePaths(): array
    {
        return [
            'two-character scheme' => ['create', '9p://x/created.txt'],
            'data URI' => ['append', 'data:,x'],
        ];
    }

    #[DataProvider('bareSchemePaths')]
    public function testAPathWithABareSchemeIsRefusedAsAStreamWrapper(string $method, string $path): void
    {
        $previous = (string) getcwd();
        chdir($this->tempDir);
        try {
            $this->writeThrough($method, $path);
            self::fail('expected a refusal');
        } catch (FileSystemException $e) {
            self::assertSame('Path is a stream wrapper URL, which is not written: ' . $path, $e->getMessage());
        } finally {
            chdir($previous);
        }

        self::assertSame(['write-through-source.txt'], array_values(array_diff((array) scandir($this->tempDir), ['.', '..'])));
    }

    public function testAPathThatOnlyResemblesAWrapperIsAPlainRelativePath(): void
    {
        $previous = (string) getcwd();
        chdir($this->tempDir);
        try {
            File::create('c://n7/a.txt', 'drive');
            File::create('DATA:,x', 'upper');
        } finally {
            chdir($previous);
        }

        self::assertSame('drive', (string) file_get_contents($this->tempDir . '/c:/n7/a.txt'));
        self::assertSame('upper', (string) file_get_contents($this->tempDir . '/DATA:,x'));
    }

    public function testADotFollowedByANewlineIsAFileName(): void
    {
        $path = $this->tempDir . "/.\n";

        File::create($path, 'content');

        self::assertSame('content', (string) file_get_contents($path));
    }

    private function writeThrough(string $method, string $path): void
    {
        $source = $this->tempDir . '/write-through-source.txt';
        file_put_contents($source, 'attacker content');

        match ($method) {
            'create' => File::create($path, 'attacker content'),
            'write' => (new File($path))->write('attacker content'),
            'append' => (new File($path))->append('attacker content'),
            'copy' => (new File($source))->copy($path),
        };
    }

    /**
     * Run as root, to give the directory and the file their owners; root is the writer.
     */
    private function fileInSharedDirectory(int $directoryMode, int $directoryOwner, int $fileOwner): string
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Needs root, to give a file to another user.');
        }
        $directory = $this->tempDir . '/shared';
        mkdir($directory);
        chown($directory, $directoryOwner);
        chmod($directory, $directoryMode);
        $path = $directory . '/report.csv';
        file_put_contents($path, 'kept');
        chown($path, $fileOwner);
        chmod($path, 0666);

        return $path;
    }

    /**
     * Run `$operation` with every file it writes capped at `$bytes`, every error reported, and
     * an error handler that throws on any not silenced with @.
     */
    private static function underFileSizeLimit(int $bytes, \Closure $operation): void
    {
        $toLimit = static fn (int|string $value): int => $value === 'unlimited' ? POSIX_RLIMIT_INFINITY : (int) $value;
        [$soft, $hard] = array_map($toLimit, (array) posix_getrlimit(POSIX_RLIMIT_FSIZE));
        $signalHandler = pcntl_signal_get_handler(SIGXFSZ);
        pcntl_signal(SIGXFSZ, SIG_IGN);
        $reporting = error_reporting(E_ALL);
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        posix_setrlimit(POSIX_RLIMIT_FSIZE, $bytes, $hard);
        try {
            $operation();
        } finally {
            posix_setrlimit(POSIX_RLIMIT_FSIZE, $soft, $hard);
            restore_error_handler();
            error_reporting($reporting);
            pcntl_signal(SIGXFSZ, $signalHandler);
        }
    }

    private function assertAppendRefusesTheLink(string $link): void
    {
        try {
            (new File($link))->append('attacker content');
            self::fail('expected a refusal');
        } catch (FileSystemException $e) {
            self::assertSame('Path is a symbolic link, which is not followed: ' . $link, $e->getMessage());
        }

        self::assertTrue(is_link($link));
    }

    /**
     * @return list<string>
     */
    private static function journalLines(string $writer): array
    {
        return array_map(
            static fn (int $n): string => sprintf('%s-%05d-%s', $writer, $n, str_repeat('.', 48)),
            range(1, 2000),
        );
    }

    /**
     * Fork a process that appends each of `$lines` to `$path`, then kills itself.
     *
     * @param list<string> $lines
     */
    private function forkAppender(string $path, array $lines): int
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            self::markTestSkipped('Could not fork.');
        }
        if ($pid > 0) {
            return $pid;
        }

        try {
            $file = new File($path);
            foreach ($lines as $line) {
                $file->append($line . "\n");
            }
        } finally {
            posix_kill(posix_getpid(), SIGKILL);
        }
    }

    /**
     * Fork a process that keeps replacing `$path`, by atomic rename, with a regular file
     * and with a link to `$target`; it never writes through the link.
     */
    private function forkPathSwapper(string $path, string $target): int
    {
        $parent = posix_getpid();
        $pid = pcntl_fork();
        if ($pid === -1) {
            self::markTestSkipped('Could not fork.');
        }
        if ($pid > 0) {
            return $pid;
        }

        $sideLink = $this->tempDir . '/swapper-link';
        $sideFile = $this->tempDir . '/swapper-file';
        for ($n = 0; ; $n++) {
            @symlink($target, $sideLink);
            @rename($sideLink, $path);
            @touch($sideFile);
            @rename($sideFile, $path);
            if ($n % 1000 === 0 && posix_getppid() !== $parent) {
                posix_kill(posix_getpid(), SIGKILL);
            }
        }
    }

    /**
     * Fork a process that keeps placing a link to the absent `$target` at `$path` whenever
     * the path is free. PHP's symlink() follows a dangling link at its link name, so the
     * link is made under a free side name and renamed into place.
     */
    private function forkLinkPlanter(string $path, string $target): int
    {
        $parent = posix_getpid();
        $pid = pcntl_fork();
        if ($pid === -1) {
            self::markTestSkipped('Could not fork.');
        }
        if ($pid > 0) {
            return $pid;
        }

        $sideLink = $this->tempDir . '/planter-link';
        for ($n = 0; ; $n++) {
            clearstatcache(true);
            if (!is_link($sideLink)) {
                @symlink($target, $sideLink);
            }
            if (!is_link($path) && !file_exists($path)) {
                @rename($sideLink, $path);
            }
            if ($n % 1000 === 0 && posix_getppid() !== $parent) {
                posix_kill(posix_getpid(), SIGKILL);
            }
        }
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
