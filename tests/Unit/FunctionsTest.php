<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FunctionsTest extends TestCase
{
    public function testEnvReturnsValueFromEnvSuperglobal(): void
    {
        $_ENV['ZEPHYRUS_TEST_VAR'] = 'hello';

        try {
            self::assertSame('hello', env('ZEPHYRUS_TEST_VAR'));
        } finally {
            unset($_ENV['ZEPHYRUS_TEST_VAR']);
        }
    }

    public function testEnvReturnsValueFromServerSuperglobal(): void
    {
        $_SERVER['ZEPHYRUS_SERVER_VAR'] = 'world';

        try {
            self::assertSame('world', env('ZEPHYRUS_SERVER_VAR'));
        } finally {
            unset($_SERVER['ZEPHYRUS_SERVER_VAR']);
        }
    }

    public function testEnvReturnsDefaultForMissingVariable(): void
    {
        unset($_ENV['ZEPHYRUS_MISSING'], $_SERVER['ZEPHYRUS_MISSING']);

        self::assertNull(env('ZEPHYRUS_MISSING'));
        self::assertSame('fallback', env('ZEPHYRUS_MISSING', 'fallback'));
    }

    public function testEnvCastsTrueFalseNullEmpty(): void
    {
        $_ENV['ZEPHYRUS_BOOL_TRUE'] = 'true';
        $_ENV['ZEPHYRUS_BOOL_FALSE'] = 'false';
        $_ENV['ZEPHYRUS_NULL'] = 'null';
        $_ENV['ZEPHYRUS_EMPTY'] = 'empty';

        try {
            self::assertTrue(env('ZEPHYRUS_BOOL_TRUE'));
            self::assertFalse(env('ZEPHYRUS_BOOL_FALSE'));
            self::assertNull(env('ZEPHYRUS_NULL'));
            self::assertSame('', env('ZEPHYRUS_EMPTY'));
        } finally {
            unset(
                $_ENV['ZEPHYRUS_BOOL_TRUE'],
                $_ENV['ZEPHYRUS_BOOL_FALSE'],
                $_ENV['ZEPHYRUS_NULL'],
                $_ENV['ZEPHYRUS_EMPTY'],
            );
        }
    }

    public function testEnvReturnsRawValueForNonSpecialStrings(): void
    {
        $_ENV['ZEPHYRUS_NORMAL'] = 'some-value';

        try {
            self::assertSame('some-value', env('ZEPHYRUS_NORMAL'));
        } finally {
            unset($_ENV['ZEPHYRUS_NORMAL']);
        }
    }

    public function testEnvPrefersEnvOverServer(): void
    {
        $_ENV['ZEPHYRUS_PRIORITY'] = 'from-env';
        $_SERVER['ZEPHYRUS_PRIORITY'] = 'from-server';

        try {
            self::assertSame('from-env', env('ZEPHYRUS_PRIORITY'));
        } finally {
            unset($_ENV['ZEPHYRUS_PRIORITY'], $_SERVER['ZEPHYRUS_PRIORITY']);
        }
    }
}
