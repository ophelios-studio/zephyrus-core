<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Cache;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Cache\AtomicFileWriter;
use Zephyrus\Core\Cache\BootCacheException;

final class AtomicFileWriterTest extends TestCase
{
    private CacheDirectory $directory;
    private CacheDirectory $outside;
    private string $target;

    protected function setUp(): void
    {
        $this->directory = new CacheDirectory();
        $this->outside = new CacheDirectory();
        $this->target = $this->directory->path . '/var/zephyrus/routes.php';
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
        $this->outside->remove();
    }

    public function testCreatesDirectoriesAt0755AndTheFileAtItsModeWhateverTheUmask(): void
    {
        $previousUmask = umask(0o077);
        try {
            $this->writer()->write($this->target, '<?php return [];', 0o440);
        } finally {
            umask($previousUmask);
        }

        self::assertSame('<?php return [];', file_get_contents($this->target));
        self::assertSame(
            ['var' => 0o755, 'var/zephyrus' => 0o755, 'var/zephyrus/routes.php' => 0o440],
            $this->modes(['var', 'var/zephyrus', 'var/zephyrus/routes.php']),
        );
    }

    public function testReplacesAnExistingFileAndLeavesNoTemporaryFileBehind(): void
    {
        $this->directory->file('var/zephyrus/routes.php', 'old');

        $this->writer()->write($this->target, 'new');

        self::assertSame('new', file_get_contents($this->target));
        self::assertSame(['var', 'var/zephyrus', 'var/zephyrus/routes.php'], $this->directory->entries());
    }

    public function testATargetThatIsASymbolicLinkIsRefused(): void
    {
        $victim = $this->outside->file('victim.txt', 'original');
        $this->directory->directory('var/zephyrus');
        symlink($victim, $this->target);

        $this->assertRefused($this->target, $this->target . ' is a symbolic link');
        self::assertSame('original', file_get_contents($victim));
    }

    public function testADirectoryThatIsASymbolicLinkIsRefused(): void
    {
        symlink($this->outside->path, $this->directory->path . '/var');

        $this->assertRefused($this->target, $this->directory->path . '/var is a symbolic link');
        self::assertSame([], $this->outside->entries());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function escapingTargets(): array
    {
        return [
            'dot-dot segments' => ['/var/../../escaped.php'],
            'the base directory itself' => ['/var/..'],
        ];
    }

    #[DataProvider('escapingTargets')]
    public function testATargetThatLeavesTheBaseDirectoryIsRefused(string $suffix): void
    {
        $target = $this->directory->path . $suffix;

        $this->assertRefused($target, 'it is outside ' . $this->directory->path);
        self::assertFileDoesNotExist(dirname($this->directory->path) . '/escaped.php');
    }

    public function testATargetInASiblingWhoseNameExtendsTheBaseDirectoryIsRefused(): void
    {
        $target = $this->directory->path . '-sibling/routes.php';

        $this->assertRefused($target, 'it is outside ' . $this->directory->path);
        self::assertDirectoryDoesNotExist($this->directory->path . '-sibling');
    }

    public function testABaseDirectoryWrittenWithATrailingSlashIsTheSameDirectory(): void
    {
        (new AtomicFileWriter($this->directory->path . '/', fileowner(...)))->write($this->target, 'cached');

        self::assertSame(['var', 'var/zephyrus', 'var/zephyrus/routes.php'], $this->directory->entries());
    }

    public function testAnAbsoluteTargetElsewhereIsRefused(): void
    {
        $target = $this->outside->path . '/routes.php';

        $this->assertRefused($target, 'it is outside ' . $this->directory->path);
        self::assertSame([], $this->outside->entries());
    }

    public function testAnExistingDirectoryWritableByOthersIsRefused(): void
    {
        $directory = $this->directory->directory('var/zephyrus', 0o757);

        $this->assertRefused(
            $this->target,
            $directory . ' is writable by its group or by others: run chmod g-w,o-w ' . $directory,
        );
        self::assertSame([], $this->directory->entries('var/zephyrus'));
    }

    public function testAnExistingDirectoryOfAnotherOwnerIsRefused(): void
    {
        $directory = $this->directory->directory('var');
        $ownerOf = static fn (string $path): int|false => $path === $directory ? 4242 : fileowner($path);

        $this->assertRefused($this->target, $directory . ' is owned by another user than this process', $ownerOf);
        self::assertSame([], $this->directory->entries('var'));
    }

    public function testRemoveDeletesTheFileThenReportsItAbsent(): void
    {
        $this->directory->file('var/zephyrus/routes.php', 'cached');

        self::assertTrue($this->writer()->remove($this->target));
        self::assertFalse($this->writer()->remove($this->target));
        self::assertFileDoesNotExist($this->target);
    }

    public function testRemoveRefusesADirectoryAtTheTarget(): void
    {
        $this->directory->directory('var/zephyrus/routes.php');

        try {
            $this->writer()->remove($this->target);
            self::fail('expected a refusal');
        } catch (BootCacheException $e) {
            self::assertSame(
                sprintf('Cannot remove the boot cache %1$s: %1$s is not a regular file.', $this->target),
                $e->getMessage(),
            );
        }
        self::assertDirectoryExists($this->target);
    }

    public function testRemoveDeletesALinkAtTheTargetAndNotTheFileItPointsTo(): void
    {
        $kept = $this->outside->file('routes.php', 'kept');
        $this->directory->directory('var/zephyrus');
        symlink($kept, $this->target);

        self::assertTrue($this->writer()->remove($this->target));
        self::assertFalse(is_link($this->target));
        self::assertFileExists($kept);
    }

    public function testWriteRefusesADirectoryAtTheTarget(): void
    {
        $this->directory->directory('var/zephyrus/routes.php');

        $this->assertRefused($this->target, $this->target . ' is not a regular file');
    }

    public function testRemoveRefusesADirectoryThatIsASymbolicLink(): void
    {
        $kept = $this->outside->file('zephyrus/routes.php', 'kept');
        symlink($this->outside->path, $this->directory->path . '/var');

        try {
            $this->writer()->remove($this->target);
            self::fail('expected a refusal');
        } catch (BootCacheException $e) {
            self::assertSame(
                sprintf('Cannot remove the boot cache %s: %s/var is a symbolic link.', $this->target, $this->directory->path),
                $e->getMessage(),
            );
        }
        self::assertFileExists($kept);
    }

    private function writer(?\Closure $ownerOf = null): AtomicFileWriter
    {
        return new AtomicFileWriter($this->directory->path, $ownerOf ?? fileowner(...));
    }

    private function assertRefused(string $target, string $reason, ?\Closure $ownerOf = null): void
    {
        try {
            $this->writer($ownerOf)->write($target, '<?php return [];');
            self::fail('expected a refusal');
        } catch (BootCacheException $e) {
            self::assertSame(sprintf('Cannot write the boot cache %s: %s.', $target, $reason), $e->getMessage());
        }
    }

    /**
     * @param list<string> $relativePaths
     * @return array<string, int>
     */
    private function modes(array $relativePaths): array
    {
        clearstatcache();
        $modes = [];
        foreach ($relativePaths as $relativePath) {
            $modes[$relativePath] = fileperms($this->directory->path . '/' . $relativePath) & 0o777;
        }

        return $modes;
    }
}
