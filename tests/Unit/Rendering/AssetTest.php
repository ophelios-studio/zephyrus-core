<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Rendering;

use PHPUnit\Framework\TestCase;
use Zephyrus\Rendering\Asset;

final class AssetTest extends TestCase
{
    private string $publicDir;

    protected function setUp(): void
    {
        $this->publicDir = sys_get_temp_dir() . '/zephyrus-asset-test-' . uniqid();
        mkdir($this->publicDir . '/css', 0755, true);
        mkdir($this->publicDir . '/img', 0755, true);
        file_put_contents($this->publicDir . '/css/app.css', 'body { color: red; }');
        file_put_contents($this->publicDir . '/img/logo.svg', '<svg>test</svg>');
    }

    protected function tearDown(): void
    {
        $this->cleanDir($this->publicDir);
    }

    public function testUrlReturnsPathWithHash(): void
    {
        $asset = new Asset($this->publicDir);
        $url = $asset->url('/css/app.css');

        self::assertStringStartsWith('/css/app.css?v=', $url);
        self::assertMatchesRegularExpression('/\?v=[a-f0-9]{8}$/', $url);
    }

    public function testUrlReturnsSameHashForSameContent(): void
    {
        $asset = new Asset($this->publicDir);

        $url1 = $asset->url('/css/app.css');
        $url2 = $asset->url('/css/app.css');

        self::assertSame($url1, $url2);
    }

    public function testUrlChangesWhenContentChanges(): void
    {
        $asset1 = new Asset($this->publicDir);
        $url1 = $asset1->url('/css/app.css');

        // Change the file content.
        file_put_contents($this->publicDir . '/css/app.css', 'body { color: blue; }');

        // New Asset instance (fresh cache).
        $asset2 = new Asset($this->publicDir);
        $url2 = $asset2->url('/css/app.css');

        self::assertNotSame($url1, $url2);
    }

    public function testUrlReturnsOriginalPathForMissingFile(): void
    {
        $asset = new Asset($this->publicDir);
        $url = $asset->url('/css/missing.css');

        self::assertSame('/css/missing.css', $url);
    }

    public function testUrlAppendsToExistingQueryString(): void
    {
        $asset = new Asset($this->publicDir);
        $url = $asset->url('/css/app.css?already=1');

        self::assertStringContainsString('?already=1&v=', $url);
    }

    public function testEmbedReturnsFileContent(): void
    {
        $asset = new Asset($this->publicDir);
        $content = $asset->embed('/img/logo.svg');

        self::assertSame('<svg>test</svg>', $content);
    }

    public function testEmbedReturnsEmptyForMissingFile(): void
    {
        $asset = new Asset($this->publicDir);
        $content = $asset->embed('/img/missing.svg');

        self::assertSame('', $content);
    }

    public function testExistsReturnsTrueForExistingAsset(): void
    {
        $asset = new Asset($this->publicDir);

        self::assertTrue($asset->exists('/css/app.css'));
        self::assertTrue($asset->exists('/img/logo.svg'));
    }

    public function testExistsReturnsFalseForMissingAsset(): void
    {
        $asset = new Asset($this->publicDir);

        self::assertFalse($asset->exists('/missing.css'));
        self::assertFalse($asset->exists('/nonexistent/path.js'));
    }

    public function testCustomHashAlgorithm(): void
    {
        $asset = new Asset($this->publicDir, 'sha256');
        $url = $asset->url('/css/app.css');

        self::assertStringStartsWith('/css/app.css?v=', $url);
        self::assertMatchesRegularExpression('/\?v=[a-f0-9]{8}$/', $url);
    }

    public function testLeadingSlashIsNormalized(): void
    {
        $asset = new Asset($this->publicDir);

        // With and without leading slash should both work.
        $url1 = $asset->url('/css/app.css');
        $url2 = $asset->url('css/app.css');

        self::assertStringContainsString('?v=', $url1);
        self::assertStringContainsString('?v=', $url2);
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
