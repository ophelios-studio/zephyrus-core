<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Zephyrus\Exceptions\ZephyrusRuntimeException;
use Zephyrus\Routing\Exception\RouteUrlGenerationException;

final class RouteUrlGenerationExceptionTest extends TestCase
{
    public function testExtendsZephyrusRuntimeException(): void
    {
        $e = RouteUrlGenerationException::invalidTtl();
        self::assertInstanceOf(ZephyrusRuntimeException::class, $e);
    }

    public function testUnknownRoute(): void
    {
        $e = RouteUrlGenerationException::unknownRoute('dashboard');
        self::assertStringContainsString('Unknown route name', $e->getMessage());
        self::assertStringContainsString('dashboard', $e->getMessage());
    }

    public function testMissingParameter(): void
    {
        $e = RouteUrlGenerationException::missingParameter('id', 'user.show');
        self::assertStringContainsString('Missing route parameter', $e->getMessage());
        self::assertStringContainsString('id', $e->getMessage());
        self::assertStringContainsString('user.show', $e->getMessage());
    }

    public function testUnexpectedParameter(): void
    {
        $e = RouteUrlGenerationException::unexpectedParameter('extra', 'home');
        self::assertStringContainsString('Unexpected route parameter', $e->getMessage());
        self::assertStringContainsString('extra', $e->getMessage());
    }

    public function testConstraintViolation(): void
    {
        $e = RouteUrlGenerationException::constraintViolation('id', 'user.show', 'must be numeric');
        self::assertStringContainsString('id', $e->getMessage());
        self::assertStringContainsString('user.show', $e->getMessage());
        self::assertStringContainsString('must be numeric', $e->getMessage());
    }

    public function testInvalidTtl(): void
    {
        $e = RouteUrlGenerationException::invalidTtl();
        self::assertStringContainsString('TTL', $e->getMessage());
    }

    public function testSignatureUnavailable(): void
    {
        $e = RouteUrlGenerationException::signatureUnavailable();
        self::assertStringContainsString('RouteSignature', $e->getMessage());
    }
}
