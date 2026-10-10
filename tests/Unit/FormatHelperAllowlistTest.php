<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\App;
use Zephyrus\Formatting\Formatter;
use Zephyrus\Formatting\FormatterException;

/**
 * The format() helper must not dispatch to arbitrary Formatter methods.
 */
final class FormatHelperAllowlistTest extends TestCase
{
    protected function setUp(): void
    {
        App::setFormatter(new Formatter(locale: 'en_US', defaultCurrency: 'USD'));
    }

    protected function tearDown(): void
    {
        App::reset();
    }

    public function testTheConstructorCannotBeCalledThroughTheHelper(): void
    {
        $this->expectException(FormatterException::class);

        format('__construct', 'de_DE');
    }

    public function testTheSingletonSurvivesTheAttempt(): void
    {
        try {
            format('__construct', 'de_DE');
        } catch (FormatterException) {
            // Expected; the point is what did NOT happen.
        }

        $formatter = App::getFormatter();
        self::assertNotNull($formatter);
        self::assertSame('en_US', $formatter->getLocale());
        self::assertSame('USD', $formatter->getDefaultCurrency());
    }

    public function testAnAccessorIsNotAFormatter(): void
    {
        $this->expectException(FormatterException::class);

        format('getDefaultCurrency');
    }

    public function testRegisterIsNotReachableEither(): void
    {
        $this->expectException(FormatterException::class);

        format('register', 'evil', static fn(): string => 'owned');
    }

    public function testAnUnknownNameIsNamedInTheRefusal(): void
    {
        try {
            format('definitelyNotAFormatter');
            self::fail('Expected a FormatterException.');
        } catch (FormatterException $exception) {
            self::assertStringContainsString('definitelyNotAFormatter', $exception->getMessage());
            self::assertStringContainsString('money', $exception->getMessage());
        }
    }

    public function testEveryBuiltInFormatterStillWorks(): void
    {
        self::assertNotSame('', format('decimal', 42));
        self::assertStringContainsString('19.99', format('money', 19.99, 'USD'));
        self::assertNotSame('', format('filesize', 1048576));
        self::assertNotSame('', format('percent', 0.85));
        self::assertNotSame('', format('ordinal', 3));
        self::assertNotSame('', format('truncate', 'a rather long value', 5));
    }

    /**
     * Custom formatters are resolved BEFORE the allowlist, so a name registered
     * with Formatter::register() is completely unaffected.
     */
    public function testACustomFormatterIsUnaffected(): void
    {
        $formatter = App::getFormatter();
        self::assertNotNull($formatter);
        $formatter->register('shout', static fn(string $value): string => strtoupper($value));

        self::assertSame('HELLO', format('shout', 'hello'));
    }

    public function testTheHelperRefusesToRunWithoutAFormatter(): void
    {
        App::reset();

        $this->expectException(FormatterException::class);

        format('money', '19.99');
    }
}
