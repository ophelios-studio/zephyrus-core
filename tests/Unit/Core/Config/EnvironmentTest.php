<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\Environment;

final class EnvironmentTest extends TestCase
{
    // ── fromString() ─────────────────────────────────────────────────────────

    public function testFromStringProduction(): void
    {
        self::assertSame(Environment::Production, Environment::fromString('production'));
        self::assertSame(Environment::Production, Environment::fromString('prod'));
        self::assertSame(Environment::Production, Environment::fromString('PRODUCTION'));
    }

    public function testFromStringStaging(): void
    {
        self::assertSame(Environment::Staging, Environment::fromString('staging'));
        self::assertSame(Environment::Staging, Environment::fromString('stage'));
        self::assertSame(Environment::Staging, Environment::fromString('STAGE'));
    }

    public function testFromStringTesting(): void
    {
        self::assertSame(Environment::Testing, Environment::fromString('testing'));
        self::assertSame(Environment::Testing, Environment::fromString('test'));
        self::assertSame(Environment::Testing, Environment::fromString('TEST'));
    }

    public function testFromStringDevelopment(): void
    {
        self::assertSame(Environment::Development, Environment::fromString('development'));
        self::assertSame(Environment::Development, Environment::fromString('dev'));
        self::assertSame(Environment::Development, Environment::fromString('local'));
        self::assertSame(Environment::Development, Environment::fromString('DEV'));
    }

    public function testFromStringUnknownDefaultsToProduction(): void
    {
        self::assertSame(Environment::Production, Environment::fromString('unknown'));
        self::assertSame(Environment::Production, Environment::fromString(''));
        self::assertSame(Environment::Production, Environment::fromString('  '));
    }

    public function testFromStringTrimsWhitespace(): void
    {
        self::assertSame(Environment::Development, Environment::fromString('  dev  '));
        self::assertSame(Environment::Testing, Environment::fromString(' test '));
    }

    // ── isProductionLike() ───────────────────────────────────────────────────

    public function testProductionIsProductionLike(): void
    {
        self::assertTrue(Environment::Production->isProductionLike());
    }

    public function testStagingIsProductionLike(): void
    {
        self::assertTrue(Environment::Staging->isProductionLike());
    }

    public function testTestingIsNotProductionLike(): void
    {
        self::assertFalse(Environment::Testing->isProductionLike());
    }

    public function testDevelopmentIsNotProductionLike(): void
    {
        self::assertFalse(Environment::Development->isProductionLike());
    }
}
