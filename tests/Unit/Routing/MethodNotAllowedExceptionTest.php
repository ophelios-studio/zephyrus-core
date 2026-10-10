<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Zephyrus\Routing\Exception\MethodNotAllowedException;

final class MethodNotAllowedExceptionTest extends TestCase
{
    public function testThePathIsQuotedWithoutRawSeparators(): void
    {
        $exception = new MethodNotAllowedException(['GET', 'HEAD'], "/a\u{2028}b\u{85}c\u{202E}d\"");

        self::assertSame(['GET', 'HEAD'], $exception->allowedMethods);
        self::assertSame(
            'Method not allowed for "/a\\u2028b\\u0085c\\u202ed\\"". Allowed: GET, HEAD',
            $exception->getMessage(),
        );
    }

    public function testALongPathKeepsItsTailUpTo512Bytes(): void
    {
        $path = '/' . str_repeat('a', 300) . '/tail';

        self::assertSame(
            sprintf('Method not allowed for "%s". Allowed: POST', $path),
            (new MethodNotAllowedException(['POST'], $path))->getMessage(),
        );
    }
}
