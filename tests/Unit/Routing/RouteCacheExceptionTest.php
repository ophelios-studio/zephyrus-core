<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Zephyrus\Exceptions\ZephyrusRuntimeException;
use Zephyrus\Routing\Exception\RouteCacheException;

final class RouteCacheExceptionTest extends TestCase
{
    public function testExtendsZephyrusRuntimeException(): void
    {
        $e = RouteCacheException::staleCache('expired');
        self::assertInstanceOf(ZephyrusRuntimeException::class, $e);
    }

    public function testRefusedNamesTheFileProblemAndTheFix(): void
    {
        $e = RouteCacheException::refused('/cache/routes.json', 'no metadata section');

        self::assertSame(
            'Route cache file /cache/routes.json has no metadata section; rebuild the cache with save() or warm()',
            $e->getMessage(),
        );
    }

    public function testStaleCache(): void
    {
        $e = RouteCacheException::staleCache('Route cache is expired');
        self::assertSame('Route cache is expired', $e->getMessage());
    }
}
