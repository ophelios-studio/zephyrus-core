<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Localization;

use PHPUnit\Framework\TestCase;
use Zephyrus\Exceptions\ZephyrusRuntimeException;
use Zephyrus\Localization\LocalizationException;

final class LocalizationExceptionTest extends TestCase
{
    public function testExtendsZephyrusRuntimeException(): void
    {
        $e = LocalizationException::unreadableFile('/path');
        self::assertInstanceOf(ZephyrusRuntimeException::class, $e);
    }

    public function testUnreadableFile(): void
    {
        $e = LocalizationException::unreadableFile('/locales/en.json');
        self::assertStringContainsString('Unable to read', $e->getMessage());
        self::assertStringContainsString('/locales/en.json', $e->getMessage());
    }

    public function testInvalidJson(): void
    {
        $previous = new \JsonException('Syntax error');
        $e = LocalizationException::invalidJson('/locales/en.json', $previous);
        self::assertStringContainsString('Invalid JSON', $e->getMessage());
        self::assertStringContainsString('Syntax error', $e->getMessage());
        self::assertSame($previous, $e->getPrevious());
    }

    public function testInvalidJsonWithoutPrevious(): void
    {
        $e = LocalizationException::invalidJson('/locales/en.json');
        self::assertStringContainsString('Invalid JSON', $e->getMessage());
        self::assertNull($e->getPrevious());
    }

    public function testInvalidFormat(): void
    {
        $e = LocalizationException::invalidFormat('/locales/en.json');
        self::assertStringContainsString('must decode to an object', $e->getMessage());
        self::assertStringContainsString('/locales/en.json', $e->getMessage());
    }
}
