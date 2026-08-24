<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Rendering;

use PHPUnit\Framework\TestCase;
use Zephyrus\Exceptions\ZephyrusRuntimeException;
use Zephyrus\Rendering\ViteException;

final class ViteExceptionTest extends TestCase
{
    public function testExtendsZephyrusRuntimeException(): void
    {
        self::assertInstanceOf(ZephyrusRuntimeException::class, ViteException::manifestNotFound(['/manifest.json']));
    }

    public function testManifestNotFoundIncludesCheckedPaths(): void
    {
        $exception = ViteException::manifestNotFound([
            '/app/public/build/manifest.json',
            '/app/public/build/.vite/manifest.json',
        ]);

        self::assertStringContainsString('/app/public/build/manifest.json', $exception->getMessage());
        self::assertStringContainsString('/app/public/build/.vite/manifest.json', $exception->getMessage());
    }

    public function testInvalidManifestIncludesPathAndPreviousException(): void
    {
        $previous = new \JsonException('Syntax error');
        $exception = ViteException::invalidManifest('/app/public/build/manifest.json', $previous);

        self::assertStringContainsString('/app/public/build/manifest.json', $exception->getMessage());
        self::assertSame($previous, $exception->getPrevious());
    }

    public function testEntryNotFoundIncludesEntryAndManifestPath(): void
    {
        $exception = ViteException::entryNotFound('resources/js/app.js', '/app/public/build/manifest.json');

        self::assertStringContainsString('resources/js/app.js', $exception->getMessage());
        self::assertStringContainsString('/app/public/build/manifest.json', $exception->getMessage());
    }

    public function testEntryMissingFileIncludesEntryAndManifestPath(): void
    {
        $exception = ViteException::entryMissingFile('resources/js/app.js', '/app/public/build/manifest.json');

        self::assertStringContainsString('resources/js/app.js', $exception->getMessage());
        self::assertStringContainsString('/app/public/build/manifest.json', $exception->getMessage());
    }
}
