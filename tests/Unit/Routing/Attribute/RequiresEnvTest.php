<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing\Attribute;

use PHPUnit\Framework\TestCase;
use Zephyrus\Routing\Attribute\Get as GetAttribute;
use Zephyrus\Routing\Attribute\RequiresEnv as RequiresEnvAttribute;
use Zephyrus\Routing\Attribute\Root as RootAttribute;
use Zephyrus\Routing\RouteAttributeReader;

// ---------------------------------------------------------------------------
// Fixture controllers
// ---------------------------------------------------------------------------

#[RequiresEnvAttribute('APP_MODE', 'WEB')]
class WebOnlyController
{
    #[GetAttribute('/dashboard')]
    public function dashboard(): void {}
}

#[RequiresEnvAttribute('APP_MODE', 'API')]
class ApiOnlyController
{
    #[GetAttribute('/v1/status')]
    public function status(): void {}
}

class MethodLevelEnvController
{
    #[RequiresEnvAttribute('APP_MODE', 'WEB')]
    #[GetAttribute('/web-only')]
    public function webOnly(): void {}

    #[RequiresEnvAttribute('APP_MODE', 'API')]
    #[GetAttribute('/api-only')]
    public function apiOnly(): void {}

    #[GetAttribute('/always')]
    public function always(): void {}
}

#[RequiresEnvAttribute('APP_MODE', 'WEB')]
class ClassAndMethodEnvController
{
    #[GetAttribute('/page')]
    public function page(): void {}

    #[RequiresEnvAttribute('APP_FEATURE', 'enabled')]
    #[GetAttribute('/feature')]
    public function feature(): void {}
}

#[RequiresEnvAttribute('APP_MODE', 'WEB')]
#[RequiresEnvAttribute('APP_REGION', 'US')]
class MultipleClassEnvController
{
    #[GetAttribute('/regional')]
    public function regional(): void {}
}

// ---------------------------------------------------------------------------

final class RequiresEnvTest extends TestCase
{
    private RouteAttributeReader $reader;

    protected function setUp(): void
    {
        $this->reader = new RouteAttributeReader();
    }

    protected function tearDown(): void
    {
        putenv('APP_MODE');
        putenv('APP_FEATURE');
        putenv('APP_REGION');
        unset($_ENV['APP_MODE'], $_ENV['APP_FEATURE'], $_ENV['APP_REGION']);
    }

    public function testClassLevelRouteRegisteredWhenEnvMatches(): void
    {
        putenv('APP_MODE=WEB');
        $_ENV['APP_MODE'] = 'WEB';

        $routes = $this->reader->read(WebOnlyController::class);

        self::assertCount(1, $routes);
        self::assertSame('/dashboard', $routes[0]->path);
    }

    public function testClassLevelRouteSkippedWhenEnvDoesNotMatch(): void
    {
        putenv('APP_MODE=API');
        $_ENV['APP_MODE'] = 'API';

        $routes = $this->reader->read(WebOnlyController::class);

        self::assertSame([], $routes);
    }

    public function testClassLevelRouteSkippedWhenEnvNotSet(): void
    {
        $routes = $this->reader->read(WebOnlyController::class);

        self::assertSame([], $routes);
    }

    public function testMethodLevelRouteRegisteredWhenEnvMatches(): void
    {
        putenv('APP_MODE=WEB');
        $_ENV['APP_MODE'] = 'WEB';

        $routes = $this->reader->read(MethodLevelEnvController::class);

        $paths = array_map(fn ($r) => $r->path, $routes);

        self::assertContains('/web-only', $paths);
        self::assertContains('/always', $paths);
        self::assertNotContains('/api-only', $paths);
        self::assertCount(2, $routes);
    }

    public function testMethodLevelRouteSkippedWhenEnvDoesNotMatch(): void
    {
        putenv('APP_MODE=API');
        $_ENV['APP_MODE'] = 'API';

        $routes = $this->reader->read(MethodLevelEnvController::class);

        $paths = array_map(fn ($r) => $r->path, $routes);

        self::assertContains('/api-only', $paths);
        self::assertContains('/always', $paths);
        self::assertNotContains('/web-only', $paths);
        self::assertCount(2, $routes);
    }

    public function testClassAndMethodLevelWorkTogether(): void
    {
        putenv('APP_MODE=WEB');
        $_ENV['APP_MODE'] = 'WEB';
        putenv('APP_FEATURE=enabled');
        $_ENV['APP_FEATURE'] = 'enabled';

        $routes = $this->reader->read(ClassAndMethodEnvController::class);

        $paths = array_map(fn ($r) => $r->path, $routes);

        self::assertCount(2, $routes);
        self::assertContains('/page', $paths);
        self::assertContains('/feature', $paths);
    }

    public function testClassPassesButMethodFails(): void
    {
        putenv('APP_MODE=WEB');
        $_ENV['APP_MODE'] = 'WEB';
        // APP_FEATURE not set, so /feature method should be skipped

        $routes = $this->reader->read(ClassAndMethodEnvController::class);

        $paths = array_map(fn ($r) => $r->path, $routes);

        self::assertCount(1, $routes);
        self::assertContains('/page', $paths);
        self::assertNotContains('/feature', $paths);
    }

    public function testClassFailsSkipsAllMethodsRegardlessOfMethodAttributes(): void
    {
        putenv('APP_MODE=API');
        $_ENV['APP_MODE'] = 'API';
        putenv('APP_FEATURE=enabled');
        $_ENV['APP_FEATURE'] = 'enabled';

        $routes = $this->reader->read(ClassAndMethodEnvController::class);

        self::assertSame([], $routes);
    }

    public function testMultipleClassAttributesAllMustMatch(): void
    {
        putenv('APP_MODE=WEB');
        $_ENV['APP_MODE'] = 'WEB';
        putenv('APP_REGION=US');
        $_ENV['APP_REGION'] = 'US';

        $routes = $this->reader->read(MultipleClassEnvController::class);

        self::assertCount(1, $routes);
        self::assertSame('/regional', $routes[0]->path);
    }

    public function testMultipleClassAttributesFailsWhenOneDoesNotMatch(): void
    {
        putenv('APP_MODE=WEB');
        $_ENV['APP_MODE'] = 'WEB';
        putenv('APP_REGION=EU');
        $_ENV['APP_REGION'] = 'EU';

        $routes = $this->reader->read(MultipleClassEnvController::class);

        self::assertSame([], $routes);
    }
}
