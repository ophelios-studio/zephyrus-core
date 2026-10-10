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

    public function testInvalidGroupingSeparatorStatesTheReason(): void
    {
        $message = FormatterException::invalidGroupingSeparator('must be at most 4 bytes')->getMessage();

        self::assertSame('Invalid grouping separator: it must be at most 4 bytes.', $message);
    }

    public function testReservedGroupingSeparatorNamesTheLocale(): void
    {
        $message = FormatterException::reservedGroupingSeparator('fr_CA')->getMessage();

        self::assertStringContainsString('minus sign of locale fr_CA', $message);
    }

    public function testInvalidCurrencyCodeStatesTheExpectedShape(): void
    {
        $message = FormatterException::invalidCurrencyCode()->getMessage();

        self::assertSame('Invalid currency code: it must be three ASCII letters, for example CAD.', $message);
    }

    public function testInvalidLocale(): void
    {
        $exception = FormatterException::invalidLocale('xx_YY');
        self::assertStringContainsString('xx_YY', $exception->getMessage());
    }

    public function testUnknownFormatter(): void
    {
        $exception = FormatterException::unknownFormatter('phone', ['money', 'date']);
        self::assertStringContainsString('phone', $exception->getMessage());
        self::assertStringContainsString('Use one of: money, date, or register a custom formatter.', $exception->getMessage());
    }
}
