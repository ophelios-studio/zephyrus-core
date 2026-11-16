<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Zephyrus\Routing\Exception\RouteParameterException;

final class RouteParameterExceptionTest extends TestCase
{
    public function testMessageEscapesControlCharactersOfTheClassMethodAndParameter(): void
    {
        self::assertSame(
            'Route parameter "$id\r\n" for "No\r\nCtrl::act\u202e" could not be resolved: expected int, got string.',
            (new RouteParameterException("No\r\nCtrl", "act\u{202E}", "id\r\n", 'int', 'abc'))->getMessage(),
        );
    }

    public function testMessageEscapesControlCharactersOfTheExpectedType(): void
    {
        self::assertSame(
            'Route parameter "$id" for "Ctrl::act" could not be resolved: expected int\u202e, got string.',
            (new RouteParameterException('Ctrl', 'act', 'id', "int\u{202E}", 'abc'))->getMessage(),
        );
    }

    public function testMessageEscapesControlCharactersOfTheActualType(): void
    {
        if (!class_exists("Actual\u{202E}Type", false)) {
            eval("final class Actual\u{202E}Type {}");
        }
        $value = new ("Actual\u{202E}Type")();

        self::assertSame(
            'Route parameter "$id" for "Ctrl::act" could not be resolved: expected int, got Actual\u202eType.',
            (new RouteParameterException('Ctrl', 'act', 'id', 'int', $value))->getMessage(),
        );
    }
}
