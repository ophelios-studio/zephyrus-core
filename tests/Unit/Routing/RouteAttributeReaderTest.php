<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Zephyrus\Routing\Attribute\Route as RouteAttribute;
use Zephyrus\Routing\Exception\RouteAttributeException;
use Zephyrus\Routing\RouteAttributeReader;

// ---------------------------------------------------------------------------
// Fixture controllers used only in this test file
// ---------------------------------------------------------------------------

class EmptyController
{
    public function noAttribute(): void {}
}

class SimpleController
{
    #[RouteAttribute('/users', 'GET')]
    public function index(): void {}

    #[RouteAttribute('/users/{id}', 'GET', constraints: ['id' => '\d+'], name: 'users.show')]
    public function show(): void {}

    #[RouteAttribute('/users', 'POST', middlewares: ['auth'])]
    public function store(): void {}

    protected function notPublic(): void {}
}

class RepeatableController
{
    #[RouteAttribute('/health', 'GET')]
    #[RouteAttribute('/status', 'GET')]
    public function health(): void {}
}

// ---------------------------------------------------------------------------

final class RouteAttributeReaderTest extends TestCase
{
    private RouteAttributeReader $reader;

    protected function setUp(): void
    {
        $this->reader = new RouteAttributeReader();
    }

    public function testEmptyControllerYieldsNoRoutes(): void
    {
        $routes = $this->reader->read(EmptyController::class);

        self::assertSame([], $routes);
    }

    public function testSimpleControllerYieldsThreeRoutes(): void
    {
        $routes = $this->reader->read(SimpleController::class);

        self::assertCount(3, $routes);
    }

    public function testHandlerStringIsClassAtMethod(): void
    {
        $routes = $this->reader->read(SimpleController::class);

        $handlers = array_map(fn ($r) => $r->handler, $routes);

        self::assertContains(SimpleController::class . '@index', $handlers);
        self::assertContains(SimpleController::class . '@show', $handlers);
        self::assertContains(SimpleController::class . '@store', $handlers);
    }

    public function testPathAndMethodAreNormalized(): void
    {
        $routes = $this->reader->read(SimpleController::class);

        $index = $this->findByHandler($routes, SimpleController::class . '@index');

        self::assertNotNull($index);
        self::assertSame('/users', $index->path);
        self::assertSame('GET', $index->method);
    }

    public function testConstraintsArePreserved(): void
    {
        $routes = $this->reader->read(SimpleController::class);

        $show = $this->findByHandler($routes, SimpleController::class . '@show');

        self::assertNotNull($show);
        self::assertSame(['id' => '\d+'], $show->constraints);
    }

    public function testNameIsPreserved(): void
    {
        $routes = $this->reader->read(SimpleController::class);

        $show = $this->findByHandler($routes, SimpleController::class . '@show');

        self::assertNotNull($show);
        self::assertSame('users.show', $show->name);
    }

    public function testMiddlewaresArePreserved(): void
    {
        $routes = $this->reader->read(SimpleController::class);

        $store = $this->findByHandler($routes, SimpleController::class . '@store');

        self::assertNotNull($store);
        self::assertSame(['auth'], $store->middlewares);
    }

    public function testUnnamedAttributeHasNullName(): void
    {
        $routes = $this->reader->read(SimpleController::class);

        $index = $this->findByHandler($routes, SimpleController::class . '@index');

        self::assertNotNull($index);
        self::assertNull($index->name);
    }

    public function testRepeatableAttributeYieldsOneRoutePerDeclaration(): void
    {
        $routes = $this->reader->read(RepeatableController::class);

        self::assertCount(2, $routes);

        $paths = array_map(fn ($r) => $r->path, $routes);

        self::assertContains('/health', $paths);
        self::assertContains('/status', $paths);
    }

    public function testProtectedMethodsAreIgnored(): void
    {
        $routes = $this->reader->read(SimpleController::class);

        $handlers = array_map(fn ($r) => $r->handler, $routes);

        self::assertNotContains(SimpleController::class . '@notPublic', $handlers);
    }

    public function testUnresolvableClassThrowsException(): void
    {
        $this->expectException(RouteAttributeException::class);

        /** @var class-string $nonExistent */
        $nonExistent = 'DoesNotExist\\Controller';
        $this->reader->read($nonExistent);
    }

    // -----------------------------------------------------------------------

    /**
     * @param list<\Zephyrus\Routing\Route> $routes
     */
    private function findByHandler(array $routes, string $handler): ?\Zephyrus\Routing\Route
    {
        foreach ($routes as $route) {
            if ($route->handler === $handler) {
                return $route;
            }
        }

        return null;
    }
}
