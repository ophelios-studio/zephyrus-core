<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Exceptions;

use ErrorException;
use PHPUnit\Framework\TestCase;
use Zephyrus\Exceptions\RegexCompilation;

final class RegexCompilationTest extends TestCase
{
    public function testAPatternThatCompilesHasNoError(): void
    {
        self::assertNull(RegexCompilation::error('#^/webhooks/#'));
    }

    public function testAPatternThatDoesNotCompileGivesPhpsReasonWithoutTheFunctionPrefix(): void
    {
        self::assertSame("No ending delimiter '/' found", RegexCompilation::error('/unclosed'));
    }

    public function testACompilationFailureReasonIsReturned(): void
    {
        self::assertSame(
            'Compilation failed: missing terminating ] for character class at offset 3',
            RegexCompilation::error('#[ab#'),
        );
    }

    public function testTheApplicationErrorHandlerIsRestoredAfterAnInvalidPattern(): void
    {
        $handler = static function (int $severity, string $message, string $file, int $line): bool {
            throw new ErrorException($message, 0, $severity, $file, $line);
        };
        set_error_handler($handler);

        try {
            self::assertNotNull(RegexCompilation::error('/unclosed'));
            self::assertNull(RegexCompilation::error('/closed/'));

            try {
                preg_match('/unclosed', '');
                self::fail('the application handler must be back in place');
            } catch (ErrorException $e) {
                self::assertSame("preg_match(): No ending delimiter '/' found", $e->getMessage());
            }
        } finally {
            restore_error_handler();
        }
    }

    public function testAFailureWithoutAWarningGivesThePcreErrorMessage(): void
    {
        $matched = @preg_match('/(?R)/', '');
        self::assertFalse($matched);
        $expected = preg_last_error_msg();

        self::assertSame($expected, RegexCompilation::error('/(?R)/'));
    }

    public function testTheOffsetOfAReasonIsStrippedForAWrappedPattern(): void
    {
        self::assertSame(
            'Compilation failed: missing terminating ] for character class',
            RegexCompilation::errorWithoutOffset('#[ab#'),
        );
    }

    public function testAReasonWithoutAnOffsetIsKeptWhole(): void
    {
        self::assertSame("No ending delimiter '/' found", RegexCompilation::errorWithoutOffset('/unclosed'));
    }

    public function testAWrappedPatternThatCompilesHasNoError(): void
    {
        self::assertNull(RegexCompilation::errorWithoutOffset('#^/webhooks/#'));
    }
}
