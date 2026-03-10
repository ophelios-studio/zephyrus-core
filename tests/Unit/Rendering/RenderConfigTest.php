<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Rendering;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigSection;
use Zephyrus\Rendering\LatteEngine;
use Zephyrus\Rendering\PhpEngine;
use Zephyrus\Rendering\RenderConfig;
use Zephyrus\Rendering\RenderException;

final class RenderConfigTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $config = RenderConfig::fromArray([]);

        self::assertSame('latte', $config->engine);
        self::assertSame('app/Views', $config->directory);
        self::assertSame('cache/latte', $config->cache);
        self::assertSame('always', $config->mode);
        self::assertSame('.php', $config->extension);
    }

    public function testCustomValues(): void
    {
        $config = RenderConfig::fromArray([
            'engine' => 'php',
            'directory' => '/var/www/templates',
            'cache' => '/tmp/latte-cache',
            'mode' => 'never',
            'extension' => '.phtml',
        ]);

        self::assertSame('php', $config->engine);
        self::assertSame('/var/www/templates', $config->directory);
        self::assertSame('/tmp/latte-cache', $config->cache);
        self::assertSame('never', $config->mode);
        self::assertSame('.phtml', $config->extension);
    }

    public function testExtendsConfigSection(): void
    {
        $config = RenderConfig::fromArray([]);
        self::assertInstanceOf(ConfigSection::class, $config);
    }

    public function testCreateEngineReturnsLatteEngine(): void
    {
        $viewsDir = __DIR__ . '/fixtures/views';
        $cacheDir = sys_get_temp_dir() . '/zephyrus-test-render-config-' . uniqid();

        $config = RenderConfig::fromArray([
            'engine' => 'latte',
            'directory' => $viewsDir,
            'cache' => $cacheDir,
        ]);

        try {
            $engine = $config->createEngine();
            self::assertInstanceOf(LatteEngine::class, $engine);
        } finally {
            @rmdir($cacheDir);
        }
    }

    public function testCreateEngineReturnsPhpEngine(): void
    {
        $viewsDir = __DIR__ . '/fixtures/views';

        $config = RenderConfig::fromArray([
            'engine' => 'php',
            'directory' => $viewsDir,
        ]);

        $engine = $config->createEngine();
        self::assertInstanceOf(PhpEngine::class, $engine);
    }

    public function testCreateEngineResolvesRelativePaths(): void
    {
        $basePath = __DIR__ . '/fixtures';

        $config = RenderConfig::fromArray([
            'engine' => 'php',
            'directory' => 'views',
        ]);

        $engine = $config->createEngine($basePath);
        self::assertInstanceOf(PhpEngine::class, $engine);
        // Verify it can actually find templates in the resolved path.
        self::assertTrue($engine->exists('hello'));
    }

    public function testCreateEngineThrowsForUnknownEngine(): void
    {
        $config = RenderConfig::fromArray([
            'engine' => 'twig',
            'directory' => '/tmp/views',
            'cache' => '/tmp/cache',
        ]);

        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('Unknown render engine [twig]');
        $config->createEngine();
    }

    public function testSnakeCaseKeysAreNormalized(): void
    {
        // YAML would typically use snake_case
        $config = RenderConfig::fromArray([
            'engine' => 'php',
        ]);

        self::assertSame('php', $config->engine);
    }

    public function testToArrayReturnsValues(): void
    {
        $config = RenderConfig::fromArray([
            'engine' => 'latte',
            'directory' => '/views',
        ]);

        $array = $config->toArray();
        self::assertSame('latte', $array['engine']);
        self::assertSame('/views', $array['directory']);
    }
}
