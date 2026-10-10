<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Zephyrus\Security\MaxBodySizeMiddleware;

final class MaxBodySizeMiddlewareTest extends TestCase
{
    public function testMaxBytesIsExposed(): void
    {
        self::assertSame(1_024, (new MaxBodySizeMiddleware(1_024))->maxBytes());
        self::assertSame(0, (new MaxBodySizeMiddleware(0))->maxBytes());
    }
}
