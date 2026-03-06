<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Zephyrus\Routing\Attribute\Delete as DeleteAttribute;
use Zephyrus\Routing\Attribute\Get as GetAttribute;
use Zephyrus\Routing\Attribute\Patch as PatchAttribute;
use Zephyrus\Routing\Attribute\Post as PostAttribute;
use Zephyrus\Routing\Attribute\Put as PutAttribute;
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

class ParentAttributedController
{
    #[RouteAttribute('/parent', 'GET', name: 'parent.route')]
    public function parentRoute(): void {}
}

class ChildAttributedController extends ParentAttributedController
{
    #[RouteAttribute('/child', 'GET', name: 'child.route')]
    public function childRoute(): void {}
}

class DuplicateRouteNameController
{
    #[RouteAttribute('/users', 'GET', name: 'users.index')]
    public function index(): void {}

    #[RouteAttribute('/people', 'GET', name: 'users.index')]
    public function people(): void {}
}

class VerbAttributesController
{
    #[GetAttribute('/articles', name: 'articles.index')]
    public function index(): void {}

    #[PostAttribute('/articles', middlewares: ['auth'])]
    public function store(): void {}

    #[PutAttribute('/articles/{id}', constraints: ['id' => '\\d+'])]
    public function replace(): void {}

    #[PatchAttribute('/articles/{id}')]
    public function update(): void {}

    #[DeleteAttribute('/articles/{id}')]
    public function destroy(): void {}
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

    public function testOnlyMethodsDeclaredOnGivenClassAreRead(): void
    {
        $routes = $this->reader->read(ChildAttributedController::class);

        self::assertCount(1, $routes);
        self::assertSame('/child', $routes[0]->path);
        self::assertSame(ChildAttributedController::class . '@childRoute', $routes[0]->handler);
    }

    public function testDuplicateRouteNamesThrowException(): void
    {
        $this->expectException(RouteAttributeException::class);
        $this->expectExceptionMessage('Duplicate route name "users.index" discovered while reading attributes on class');

        $this->reader->read(DuplicateRouteNameController::class);
    }

    public function testVerbAttributesMapToExpectedHttpMethods(): void
    {
        $routes = $this->reader->read(VerbAttributesController::class);

        self::assertCount(5, $routes);

        self::assertSame('GET', $this->findByHandler($routes, VerbAttributesController::class . '@index')?->method);
        self::assertSame('POST', $this->findByHandler($routes, VerbAttributesController::class . '@store')?->method);
        self::assertSame('PUT', $this->findByHandler($routes, VerbAttributesController::class . '@replace')?->method);
        self::assertSame('PATCH', $this->findByHandler($routes, VerbAttributesController::class . '@update')?->method);
        self::assertSame('DELETE', $this->findByHandler($routes, VerbAttributesController::class . '@destroy')?->method);
    }

    public function testVerbAttributesPreserveRouteMetadata(): void
    {
        $routes = $this->reader->read(VerbAttributesController::class);

        $index = $this->findByHandler($routes, VerbAttributesController::class . '@index');
        $store = $this->findByHandler($routes, VerbAttributesController::class . '@store');
        $replace = $this->findByHandler($routes, VerbAttributesController::class . '@replace');

        self::assertNotNull($index);
        self::assertSame('articles.index', $index->name);

        self::assertNotNull($store);
        self::assertSame(['auth'], $store->middlewares);

        self::assertNotNull($replace);
        self::assertSame(['id' => '\\d+'], $replace->constraints);
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
