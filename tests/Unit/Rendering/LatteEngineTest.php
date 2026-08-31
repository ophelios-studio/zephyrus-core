<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Rendering;

use PHPUnit\Framework\TestCase;
use Zephyrus\Rendering\LatteEngine;
use Zephyrus\Rendering\RenderEngine;
use Zephyrus\Rendering\RenderException;

final class LatteEngineTest extends TestCase
{
    private string $viewsDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->viewsDir = __DIR__ . '/fixtures/views';
        $this->cacheDir = __DIR__ . '/fixtures/cache';
    }

    protected function tearDown(): void
    {
        // Clean up generated cache files.
        $this->cleanDirectory($this->cacheDir);
    }

    public function testImplementsRenderEngine(): void
    {
        $engine = new LatteEngine($this->viewsDir, $this->cacheDir);
        self::assertInstanceOf(RenderEngine::class, $engine);
    }

    public function testRendersSimpleTemplate(): void
    {
        $engine = new LatteEngine($this->viewsDir, $this->cacheDir);
        $output = $engine->render('hello', ['name' => 'World']);

        self::assertSame('Hello, World!', $output);
    }

    public function testRendersTemplateWithMultipleVariables(): void
    {
        $engine = new LatteEngine($this->viewsDir, $this->cacheDir);
        $output = $engine->render('page', [
            'title' => 'Test Page',
            'content' => 'Hello from Latte',
        ]);

        self::assertStringContainsString('<h1>Test Page</h1>', $output);
        self::assertStringContainsString('<p>Hello from Latte</p>', $output);
    }

    public function testRendersNestedTemplate(): void
    {
        $engine = new LatteEngine($this->viewsDir, $this->cacheDir);
        $output = $engine->render('nested/deep', ['value' => 'test']);

        self::assertSame('Nested: test', $output);
    }

    public function testThrowsForMissingTemplate(): void
    {
        $engine = new LatteEngine($this->viewsDir, $this->cacheDir);

        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('Template [nonexistent] not found');
        $engine->render('nonexistent');
    }

    public function testThrowsForRenderError(): void
    {
        $engine = new LatteEngine($this->viewsDir, $this->cacheDir);

        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('Failed to render template [error]');
        $engine->render('error');
    }

    public function testExistsReturnsTrueForExistingTemplate(): void
    {
        $engine = new LatteEngine($this->viewsDir, $this->cacheDir);

        self::assertTrue($engine->exists('hello'));
        self::assertTrue($engine->exists('page'));
        self::assertTrue($engine->exists('nested/deep'));
    }

    public function testExistsReturnsFalseForMissingTemplate(): void
    {
        $engine = new LatteEngine($this->viewsDir, $this->cacheDir);

        self::assertFalse($engine->exists('nonexistent'));
        self::assertFalse($engine->exists('missing/template'));
    }

    public function testCacheModeNever(): void
    {
        $engine = new LatteEngine($this->viewsDir, $this->cacheDir, cacheMode: 'never');
        $output = $engine->render('hello', ['name' => 'Test']);

        self::assertSame('Hello, Test!', $output);
    }

    public function testCacheModeAlways(): void
    {
        $engine = new LatteEngine($this->viewsDir, $this->cacheDir, cacheMode: 'always');

        // Render twice to exercise cache.
        $first = $engine->render('hello', ['name' => 'First']);
        $second = $engine->render('hello', ['name' => 'Second']);

        self::assertSame('Hello, First!', $first);
        self::assertSame('Hello, Second!', $second);
    }

    public function testGetLatteEngineReturnsUnderlyingInstance(): void
    {
        $engine = new LatteEngine($this->viewsDir, $this->cacheDir);

        self::assertInstanceOf(\Latte\Engine::class, $engine->getLatteEngine());
    }

    public function testCreatesCacheDirectoryWhenMissing(): void
    {
        $tempCache = sys_get_temp_dir() . '/zephyrus-test-latte-' . uniqid();
        self::assertDirectoryDoesNotExist($tempCache);

        try {
            $engine = new LatteEngine($this->viewsDir, $tempCache);
            $output = $engine->render('hello', ['name' => 'World']);

            self::assertDirectoryExists($tempCache);
            self::assertSame('Hello, World!', $output);
        } finally {
            $this->cleanDirectory($tempCache);
            @rmdir($tempCache);
        }
    }

    public function testEscapesHtmlByDefault(): void
    {
        $engine = new LatteEngine($this->viewsDir, $this->cacheDir);
        $output = $engine->render('hello', ['name' => '<script>xss</script>']);

        self::assertStringNotContainsString('<script>', $output);
        self::assertStringContainsString('&lt;script&gt;', $output);
    }

    // ------------------------------------------------------------------
    // Path containment
    // ------------------------------------------------------------------

    public function testRefusesToRenderATemplateOutsideTheDirectory(): void
    {
        $root = sys_get_temp_dir() . '/zephyrus-latte-traversal-' . uniqid();
        mkdir($root . '/views', 0755, true);
        mkdir($root . '/uploads', 0755, true);
        file_put_contents($root . '/uploads/evil.latte', 'LATTE-LFI ok');

        $engine = new LatteEngine($root . '/views', $this->cacheDir);

        try {
            $this->expectException(RenderException::class);
            $engine->render('../uploads/evil');
        } finally {
            @unlink($root . '/uploads/evil.latte');
            @rmdir($root . '/uploads');
            @rmdir($root . '/views');
            @rmdir($root);
        }
    }

    public function testExistsIsNotAFileExistenceOracle(): void
    {
        $root = sys_get_temp_dir() . '/zephyrus-latte-oracle-' . uniqid();
        mkdir($root . '/views', 0755, true);
        file_put_contents($root . '/present.latte', 'x');

        $engine = new LatteEngine($root . '/views', $this->cacheDir);

        try {
            self::assertFalse($engine->exists('../present'));
        } finally {
            @unlink($root . '/present.latte');
            @rmdir($root . '/views');
            @rmdir($root);
        }
    }

    // ------------------------------------------------------------------
    // Cache mode
    // ------------------------------------------------------------------

    public function testRejectsAnUnknownCacheMode(): void
    {
        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('Unknown Latte cache mode [garbage]');

        new LatteEngine($this->viewsDir, $this->cacheDir, cacheMode: 'garbage');
    }

    public function testCacheModeNeverWritesNoCompiledTemplate(): void
    {
        $tempCache = sys_get_temp_dir() . '/zephyrus-latte-nocache-' . uniqid();

        try {
            $engine = new LatteEngine($this->viewsDir, $tempCache, cacheMode: 'never');
            $output = $engine->render('hello', ['name' => 'World']);

            self::assertSame('Hello, World!', $output);
            self::assertDirectoryDoesNotExist($tempCache);
        } finally {
            $this->cleanDirectory($tempCache);
            @rmdir($tempCache);
        }
    }

    /**
     * Recursively delete contents of a directory (but keep the directory itself).
     */
    private function cleanDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
    }
}
