<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Cache;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Zephyrus\Core\Cache\BootCache;
use Zephyrus\Core\Cache\BootCacheException;
use Zephyrus\Tests\Support\CapturedErrorLog;

final class BootCacheTest extends TestCase
{
    use CapturedErrorLog;

    private const string FINGERPRINT = 'fingerprint-1';
    private const int STRANGER = 4242;

    private CacheDirectory $directory;
    private CacheDirectory $outside;
    private string $source;
    private string $cachePath;
    private string $marker;

    protected function setUp(): void
    {
        $this->captureErrorLog();
        $this->directory = new CacheDirectory();
        $this->outside = new CacheDirectory();
        $this->source = $this->directory->file('config.yml', "application: {}\n");
        $this->cachePath = $this->directory->path . '/var/zephyrus/config.yml.php';
        $this->marker = $this->outside->path . '/included';
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
        $this->outside->remove();
        $this->releaseErrorLog();
    }

    public function testAnAbsentCacheLoadsAsNullWithoutALogLine(): void
    {
        self::assertNull($this->cache()->load(self::fingerprint(...)));
        self::assertSame([], $this->loggedLines());
    }

    public function testAStoredPayloadLoadsBackWhileTheFingerprintMatches(): void
    {
        $payload = ['database' => ['host' => 'db.example.test', 'password' => new TaggedValue('env', 'DB_PASSWORD')]];
        $this->cache()->store($payload, self::FINGERPRINT);

        self::assertEquals($payload, $this->cache()->load(self::fingerprint(...)));
        self::assertSame([], $this->loggedLines());
    }

    public function testTheFingerprintIsComputedFromTheCachedPayload(): void
    {
        $payload = ['dependencies' => ['lib/Base.php', 'lib/Shared.php']];
        $this->cache()->store($payload, 'lib/Base.php lib/Shared.php');

        $loaded = $this->cache()->load(static fn (array $cached): string => implode(' ', $cached['dependencies']));

        self::assertSame($payload, $loaded);
    }

    public function testAGroupWritableCacheFileIsNeverIncluded(): void
    {
        $this->plantMarkedCache(0o664);

        $this->assertIgnoredWithoutIncluding($this->cache(), $this->cachePath . ' is writable by its group or by others');
    }

    public function testACacheUnderADirectoryWritableByOthersIsNeverIncluded(): void
    {
        $this->plantMarkedCache();
        $directory = $this->directory->directory('var/zephyrus', 0o757);

        $this->assertIgnoredWithoutIncluding($this->cache(), $directory . ' is writable by its group or by others');
    }

    public function testACacheUnderADirectoryOfAnotherOwnerIsNeverIncluded(): void
    {
        $this->plantMarkedCache();
        $directory = $this->directory->path . '/var';

        $this->assertIgnoredWithoutIncluding(
            $this->cache(self::strangerOwns($directory)),
            $directory . ' is owned by neither root nor the owner of ' . $this->source,
        );
    }

    public function testACacheFileOwnedByAnotherUserThanTheSourceIsNeverIncluded(): void
    {
        $this->plantMarkedCache();

        $this->assertIgnoredWithoutIncluding(
            $this->cache(self::strangerOwns($this->cachePath)),
            $this->cachePath . ' is owned by neither root nor the owner of ' . $this->source,
        );
    }

    public function testACacheLinkedOutsideTheBaseDirectoryIsNeverIncluded(): void
    {
        $target = $this->outside->file('planted.php', $this->markedContents());
        $this->directory->directory('var/zephyrus');
        symlink($target, $this->cachePath);

        $this->assertIgnoredWithoutIncluding($this->cache(), 'it resolves outside ' . $this->directory->path);
    }

    /**
     * @return array<string, array{\Closure(string): string, string}>
     */
    public static function unusableContents(): array
    {
        return [
            'not PHP' => [
                static fn (string $valid): string => "garbage\0\x01 database: password\n",
                'it prints output, so it is not a boot cache',
            ],
            'truncated' => [
                static fn (string $valid): string => substr($valid, 0, intdiv(strlen($valid), 2)),
                'it does not load (ParseError)',
            ],
            'not an array' => [
                static fn (string $valid): string => "<?php return 42;\n",
                'it does not return a boot cache array',
            ],
            'another format' => [
                static fn (string $valid): string => str_replace("'format' => 1,", "'format' => 2,", $valid),
                'it was written in another cache format',
            ],
            'another framework version' => [
                static fn (string $valid): string => (string) preg_replace("/'framework' => '[^']*'/", "'framework' => 'v0.0.1@x'", $valid),
                'it was written by another version of the framework',
            ],
            'another fingerprint' => [
                static fn (string $valid): string => str_replace(self::FINGERPRINT, 'fingerprint-0', $valid),
                'it is stale, its source changed after it was written',
            ],
        ];
    }

    /**
     * @param \Closure(string): string $damage
     */
    #[DataProvider('unusableContents')]
    public function testAnUnusableCacheIsIgnoredWithOneLogLineAndNoOutput(\Closure $damage, string $reason): void
    {
        $this->cache()->store(['application' => ['name' => 'demo']], self::FINGERPRINT);
        $valid = (string) file_get_contents($this->cachePath);
        $this->directory->file('var/zephyrus/config.yml.php', $damage($valid), 0o444);
        $this->expectOutputString('');

        $loaded = $this->cache()->load(self::fingerprint(...));

        self::assertNull($loaded);
        self::assertSame([$this->ignoredLine($reason)], $this->loggedLines());
    }

    public function testStoreReplacesAnExistingCacheWithoutIncludingIt(): void
    {
        $this->plantMarkedCache();

        $this->cache()->store(['application' => []], self::FINGERPRINT);

        self::assertFileDoesNotExist($this->marker);
        self::assertSame(['application' => []], $this->cache()->load(self::fingerprint(...)));
    }

    public function testStoreRefusesWhenThisProcessIsNeitherRootNorTheOwnerOfTheSource(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Root may write the cache of any source.');
        }

        try {
            $this->cache(self::strangerOwns($this->source))->store([], self::FINGERPRINT);
            self::fail('expected a refusal');
        } catch (BootCacheException $e) {
            self::assertSame(
                sprintf(
                    'Cannot write the boot cache %s: this process is neither root nor the owner of %s, so the application would ignore the cache.',
                    $this->cachePath,
                    $this->source,
                ),
                $e->getMessage(),
            );
        }
        self::assertFileDoesNotExist($this->cachePath);
    }

    private function cache(?\Closure $ownerOf = null): BootCache
    {
        return new BootCache($this->cachePath, $this->directory->path, $this->source, $ownerOf);
    }

    private static function fingerprint(): string
    {
        return self::FINGERPRINT;
    }

    /**
     * @return \Closure(string): (int|false)
     */
    private static function strangerOwns(string $path): \Closure
    {
        return static fn (string $candidate): int|false => $candidate === $path ? self::STRANGER : fileowner($candidate);
    }

    private function plantMarkedCache(int $mode = 0o644): void
    {
        $this->directory->file('var/zephyrus/config.yml.php', $this->markedContents(), $mode);
    }

    private function markedContents(): string
    {
        return sprintf("<?php touch(%s); return ['format' => 1, 'payload' => []];\n", var_export($this->marker, true));
    }

    private function assertIgnoredWithoutIncluding(BootCache $cache, string $reason): void
    {
        $loaded = $cache->load(self::fingerprint(...));

        self::assertNull($loaded);
        self::assertFileDoesNotExist($this->marker);
        self::assertSame([$this->ignoredLine($reason)], $this->loggedLines());
    }

    private function ignoredLine(string $reason): string
    {
        return sprintf(
            'Zephyrus: ignored the boot cache %s: %s. Run vendor/bin/zephyrus cache:warm to rebuild it.',
            $this->cachePath,
            $reason,
        );
    }

    /**
     * @return list<string>
     */
    private function loggedLines(): array
    {
        $lines = array_filter(explode("\n", $this->capturedErrorLog()), static fn (string $line): bool => $line !== '');

        return array_values(array_map(static fn (string $line): string => (string) preg_replace('/^\[[^]]*] /', '', $line), $lines));
    }
}
