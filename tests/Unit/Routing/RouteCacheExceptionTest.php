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

    public function testFileSystemError(): void
    {
        $e = RouteCacheException::fileSystemError('write', '/cache/routes.json');
        self::assertStringContainsString('write', $e->getMessage());
        self::assertStringContainsString('/cache/routes.json', $e->getMessage());
    }

    public function testEncodingFailed(): void
    {
        $previous = new \JsonException('malformed');
        $e = RouteCacheException::encodingFailed('encode', $previous);
        self::assertStringContainsString('encode', $e->getMessage());
        self::assertSame($previous, $e->getPrevious());
    }

    public function testEncodingFailedWithoutPrevious(): void
    {
        $e = RouteCacheException::encodingFailed('decode');
        self::assertStringContainsString('decode', $e->getMessage());
        self::assertNull($e->getPrevious());
    }

    public function testInvalidPayloadStructure(): void
    {
        $e = RouteCacheException::invalidPayloadStructure('missing routes section');
        self::assertStringContainsString('missing routes section', $e->getMessage());
    }

    public function testInvalidMetadata(): void
    {
        $e = RouteCacheException::invalidMetadata('invalid metadata version');
        self::assertStringContainsString('invalid metadata version', $e->getMessage());
    }

    public function testIntegrityCheckFailed(): void
    {
        $e = RouteCacheException::integrityCheckFailed('hash mismatch');
        self::assertStringContainsString('hash mismatch', $e->getMessage());
    }

    public function testInvalidRouteEntry(): void
    {
        $e = RouteCacheException::invalidRouteEntry('missing valid "method"');
        self::assertStringContainsString('missing valid "method"', $e->getMessage());
    }

    public function testStaleCache(): void
    {
        $e = RouteCacheException::staleCache('Route cache is expired');
        self::assertSame('Route cache is expired', $e->getMessage());
    }
}
