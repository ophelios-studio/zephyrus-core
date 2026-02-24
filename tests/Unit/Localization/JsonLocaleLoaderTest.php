<?php

declare(strict_types=1);

namespace Tests\Unit\Localization;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Zephyrus\Localization\JsonLocaleLoader;

final class JsonLocaleLoaderTest extends TestCase
{
    public function testLoadReturnsFlattenedKeys(): void
    {
        $loader = new JsonLocaleLoader(__DIR__ . '/../../Fixtures/locales');

        $catalog = $loader->load('en');

        self::assertSame('Welcome {name}', $catalog['messages.welcome']);
        self::assertSame('The {field} field is required', $catalog['errors.required']);
    }

    public function testLoadReturnsEmptyCatalogWhenFileIsMissing(): void
    {
        $loader = new JsonLocaleLoader(__DIR__ . '/../../Fixtures/locales');

        self::assertSame([], $loader->load('es'));
    }

    public function testLoadThrowsWhenJsonIsInvalid(): void
    {
        $tempDir = sys_get_temp_dir() . '/zephyrus-locales-' . uniqid('', true);
        mkdir($tempDir);
        file_put_contents($tempDir . '/en.json', '{invalid');

        $loader = new JsonLocaleLoader($tempDir);

        try {
            $this->expectException(RuntimeException::class);
            $loader->load('en');
        } finally {
            @unlink($tempDir . '/en.json');
            @rmdir($tempDir);
        }
    }
}
