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

    public function testFlattenCastsNullJsonValueToEmptyString(): void
    {
        $tempDir = sys_get_temp_dir() . '/zephyrus-locales-' . uniqid('', true);
        mkdir($tempDir);
        file_put_contents($tempDir . '/en.json', '{"messages":{"empty":null,"text":"hello"}}');

        $loader = new JsonLocaleLoader($tempDir);

        try {
            $catalog = $loader->load('en');
            self::assertSame('', $catalog['messages.empty']);
            self::assertSame('hello', $catalog['messages.text']);
        } finally {
            @unlink($tempDir . '/en.json');
            @rmdir($tempDir);
        }
    }

    public function testFlattenSkipsNonScalarNonNullValues(): void
    {
        $tempDir = sys_get_temp_dir() . '/zephyrus-locales-' . uniqid('', true);
        mkdir($tempDir);
        // 'nested' is an object that gets recursed; the array case with a plain
        // string value produces 'nested.key'.  Top-level 'obj' with nested array
        // becomes 'obj.a'.
        file_put_contents($tempDir . '/en.json', '{"obj":{"a":"val-a"},"plain":"hello"}');

        $loader = new JsonLocaleLoader($tempDir);

        try {
            $catalog = $loader->load('en');
            self::assertSame('val-a', $catalog['obj.a']);
            self::assertSame('hello', $catalog['plain']);
            // The intermediate 'obj' key itself is not emitted (only leaves are)
            self::assertArrayNotHasKey('obj', $catalog);
        } finally {
            @unlink($tempDir . '/en.json');
            @rmdir($tempDir);
        }
    }
}
