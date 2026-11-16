<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Zephyrus\Routing\Exception\RouteMiddlewareException;

final class RouteMiddlewareExceptionTest extends TestCase
{
    public function testUndeclaredMiddlewareGroupRefusalEscapesControlCharactersOfTheHandler(): void
    {
        self::assertSame(
            'Middleware group "admin", named by a #[MiddlewareGroup] of Ctrl@act\r\nFAKE, is not declared under '
            . 'router.middleware_groups: declare it there, or the route would run without its middlewares.',
            RouteMiddlewareException::undeclaredMiddlewareGroup('admin', "Ctrl@act\r\nFAKE")->getMessage(),
        );
    }

    public function testUndeclaredMiddlewareGroupRefusalEscapesControlCharactersOfTheGroupAndTheHandler(): void
    {
        self::assertSame(
            'Middleware group "admin\u202e", named by a #[MiddlewareGroup] of Ctrl\u202e@act\r\nFAKE, is not declared '
            . 'under router.middleware_groups: declare it there, or the route would run without its middlewares.',
            RouteMiddlewareException::undeclaredMiddlewareGroup("admin\u{202E}", "Ctrl\u{202E}@act\r\nFAKE")->getMessage(),
        );
    }
}
