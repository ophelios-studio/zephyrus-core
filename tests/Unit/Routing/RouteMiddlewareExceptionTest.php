<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Zephyrus\Routing\Exception\RouteMiddlewareException;

final class RouteMiddlewareExceptionTest extends TestCase
{
    public function testUndeclaredMiddlewareGroupRefusalEscapesControlCharactersOfTheGroupAndTheHandler(): void
    {
        self::assertSame(
            'Middleware group "admin\u202e", named by a #[MiddlewareGroup] of Ctrl\u202e@act\r\nFAKE, is not declared '
            . 'under router.middleware_groups: declare it there, or the route would run without its middlewares.',
            RouteMiddlewareException::undeclaredMiddlewareGroup("admin\u{202E}", "Ctrl\u{202E}@act\r\nFAKE")->getMessage(),
        );
    }

    public function testExcludedMiddlewareGroupRefusalEscapesControlCharactersOfTheListedClasses(): void
    {
        self::assertSame(
            'Resource "/u" cannot skip "web": "web" is a middleware group: list its classes instead (Ctrl\r\nFAKE)',
            RouteMiddlewareException::excludedMiddlewareGroup('Resource "/u"', 'web', ["Ctrl\r\nFAKE"])->getMessage(),
        );
    }
}
