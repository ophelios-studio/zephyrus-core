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
}
