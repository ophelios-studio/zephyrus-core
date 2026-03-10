<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Formatting;

use PHPUnit\Framework\TestCase;
use Zephyrus\Exceptions\ZephyrusRuntimeException;
use Zephyrus\Formatting\FormatterException;

final class FormatterExceptionTest extends TestCase
{
    public function testExtendsZephyrusRuntimeException(): void
    {
        $exception = new FormatterException('test');
        self::assertInstanceOf(ZephyrusRuntimeException::class, $exception);
    }

    public function testFormattingFailed(): void
    {
        $exception = FormatterException::formattingFailed('money', 'invalid currency');
        self::assertStringContainsString('money', $exception->getMessage());
        self::assertStringContainsString('invalid currency', $exception->getMessage());
    }

    public function testFormattingFailedWithPrevious(): void
    {
        $previous = new \RuntimeException('ICU error');
        $exception = FormatterException::formattingFailed('date', 'parse error', $previous);
        self::assertSame($previous, $exception->getPrevious());
    }

    public function testInvalidLocale(): void
    {
        $exception = FormatterException::invalidLocale('xx_YY');
        self::assertStringContainsString('xx_YY', $exception->getMessage());
    }

    public function testUnknownFormatter(): void
    {
        $exception = FormatterException::unknownFormatter('phone');
        self::assertStringContainsString('phone', $exception->getMessage());
    }
}
