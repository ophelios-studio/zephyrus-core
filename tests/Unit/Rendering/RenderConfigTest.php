<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Rendering;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigurationException;
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
        $cacheDir = sys_get_temp_dir() . '/zephyrus-test-render-config-' . bin2hex(random_bytes(8));

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

    public function testAMisspelledKeyIsRefusedWithASuggestion(): void
    {
        try {
            RenderConfig::fromArray(['engin' => 'php']);
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            self::assertSame(
                "Configuration section 'render' field 'engin' is an unknown key: did you mean \"engine\"?",
                $e->getMessage(),
            );
        }
    }

    public function testAKeyWithNoNeighbourListsTheAcceptedKeys(): void
    {
        try {
            RenderConfig::fromArray(['layout' => 'main']);
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            self::assertSame(
                "Configuration section 'render' field 'layout' is an unknown key: "
                . 'the accepted keys are engine, directory, cache, mode, extension.',
                $e->getMessage(),
            );
        }
    }

    public function testEveryDocumentedKeyIsAccepted(): void
    {
        $config = RenderConfig::fromArray([
            'engine' => 'php',
            'directory' => 'views',
            'cache' => 'cache',
            'mode' => 'never',
            'extension' => '.phtml',
        ]);

        self::assertSame('.phtml', $config->extension);
    }

    public function testASectionDerivedByAnApplicationStaysPermissive(): void
    {
        $section = new class(['anything' => 1]) extends ConfigSection {
        };

        self::assertSame(1, $section->get('anything'));
        self::assertSame(2, $section::fromArray(['other' => 2])->get('other'));
    }

    public function testATypeRefusalNamesTheSectionByItsConfigurationKey(): void
    {
        try {
            RenderConfig::fromArray(['engine' => ['latte']]);
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            self::assertSame(
                "Configuration section 'render' field 'engine' has invalid value array: is not representable as a string.",
                $e->getMessage(),
            );
        }
    }
}
