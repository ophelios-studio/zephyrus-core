<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Container;

use PHPUnit\Framework\TestCase;
use Zephyrus\Container\Container;
use Zephyrus\Container\ContainerException;
use Zephyrus\Container\NotFoundException;

// ---------------------------------------------------------------------------
// Minimal fixtures used only inside this test file.
// ---------------------------------------------------------------------------

final class SimpleService
{
    public string $tag = 'default';
}

final class DependentService
{
    public function __construct(public readonly SimpleService $simple) {}
}

final class ServiceWithDefault
{
    public function __construct(public readonly int $timeout = 30) {}
}

final class ServiceWithUnresolvableParam
{
    public function __construct(public readonly string $dsn) {}
}

interface SomeInterface {}

abstract class AbstractService {}

final class ConcreteService implements SomeInterface
{
    public function __construct(public readonly SimpleService $dep) {}
}

final class CircularA
{
    public function __construct(public readonly CircularB $b) {}
}

final class CircularB
{
    public function __construct(public readonly CircularA $a) {}
}

/**
 * Has a non-public constructor with a typed dependency.
 * When auto-wired, ReflectionClass::newInstanceArgs() throws ReflectionException
 * because the constructor is not publicly accessible from outside the class.
 */
final class ProtectedConstructorService
{
    protected function __construct(public readonly SimpleService $dep) {}
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

final class ContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    // ------------------------------------------------------------------
    // bind() — transient factories
    // ------------------------------------------------------------------

    public function testBindReturnsNewInstanceEachCall(): void
    {
        $this->container->bind(SimpleService::class, fn() => new SimpleService());

        $a = $this->container->get(SimpleService::class);
        $b = $this->container->get(SimpleService::class);

        self::assertInstanceOf(SimpleService::class, $a);
        self::assertInstanceOf(SimpleService::class, $b);
        self::assertNotSame($a, $b);
    }

    public function testBindFactoryReceivesContainer(): void
    {
        $this->container->bind(SimpleService::class, fn() => new SimpleService());
        $this->container->bind(DependentService::class, fn(Container $c) => new DependentService($c->get(SimpleService::class)));

        $dep = $this->container->get(DependentService::class);
        self::assertInstanceOf(DependentService::class, $dep);
        self::assertInstanceOf(SimpleService::class, $dep->simple);
    }

    // ------------------------------------------------------------------
    // singleton() — shared factories
    // ------------------------------------------------------------------

    public function testSingletonReturnsSameInstance(): void
    {
        $this->container->singleton(SimpleService::class, fn() => new SimpleService());

        $a = $this->container->get(SimpleService::class);
        $b = $this->container->get(SimpleService::class);

        self::assertSame($a, $b);
    }

    public function testSingletonIsResolvedOnlyOnce(): void
    {
        $callCount = 0;
        $this->container->singleton(SimpleService::class, function () use (&$callCount) {
            $callCount++;
            return new SimpleService();
        });

        $this->container->get(SimpleService::class);
        $this->container->get(SimpleService::class);

        self::assertSame(1, $callCount);
    }

    // ------------------------------------------------------------------
    // instance() — pre-built values
    // ------------------------------------------------------------------

    public function testInstanceAlwaysReturnsSameObject(): void
    {
        $svc = new SimpleService();
        $svc->tag = 'pre-built';

        $this->container->instance(SimpleService::class, $svc);

        $resolved = $this->container->get(SimpleService::class);
        self::assertSame($svc, $resolved);
        self::assertSame('pre-built', $resolved->tag);
    }

    public function testInstanceWorksForScalarValues(): void
    {
        $this->container->instance('app.name', 'Zephyrus');
        self::assertSame('Zephyrus', $this->container->get('app.name'));
    }

    // ------------------------------------------------------------------
    // has()
    // ------------------------------------------------------------------

    public function testHasReturnsTrueForExplicitBinding(): void
    {
        $this->container->bind(SimpleService::class, fn() => new SimpleService());
        self::assertTrue($this->container->has(SimpleService::class));
    }

    public function testHasReturnsTrueForSingleton(): void
    {
        $this->container->singleton(SimpleService::class, fn() => new SimpleService());
        self::assertTrue($this->container->has(SimpleService::class));
    }

    public function testHasReturnsTrueForInstance(): void
    {
        $this->container->instance('key', 42);
        self::assertTrue($this->container->has('key'));
    }

    public function testHasReturnsTrueForAutoWireableClass(): void
    {
        // SimpleService has no dependencies, so it should be auto-wireable.
        self::assertTrue($this->container->has(SimpleService::class));
    }

    public function testHasReturnsFalseForUnknownIdentifier(): void
    {
        self::assertFalse($this->container->has('Zephyrus\NoSuchClass'));
    }

    // ------------------------------------------------------------------
    // Auto-wiring
    // ------------------------------------------------------------------

    public function testAutoWireClassWithNoConstructor(): void
    {
        $svc = $this->container->get(SimpleService::class);
        self::assertInstanceOf(SimpleService::class, $svc);
    }

    public function testAutoWireClassWithTypedDependency(): void
    {
        // DependentService needs SimpleService — resolved automatically.
        $dep = $this->container->get(DependentService::class);
        self::assertInstanceOf(DependentService::class, $dep);
        self::assertInstanceOf(SimpleService::class, $dep->simple);
    }

    public function testAutoWireUsesExplicitBindingForTransitiveDep(): void
    {
        $tagged = new SimpleService();
        $tagged->tag = 'tagged';
        $this->container->instance(SimpleService::class, $tagged);

        $dep = $this->container->get(DependentService::class);
        self::assertSame('tagged', $dep->simple->tag);
    }

    public function testAutoWireUsesDefaultParamValue(): void
    {
        $svc = $this->container->get(ServiceWithDefault::class);
        self::assertInstanceOf(ServiceWithDefault::class, $svc);
        self::assertSame(30, $svc->timeout);
    }

    public function testAutoWireThrowsContainerExceptionForUnresolvableParam(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/Cannot auto-wire parameter \$dsn/');

        $this->container->get(ServiceWithUnresolvableParam::class);
    }

    public function testAutoWireThrowsNotFoundExceptionForUnknownClass(): void
    {
        $this->expectException(NotFoundException::class);

        $this->container->get('Zephyrus\NoSuchClass');
    }

    public function testAutoWireThrowsContainerExceptionForAbstractClass(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/Cannot auto-wire abstract class/');

        $this->container->get(AbstractService::class);
    }

    public function testAutoWireThrowsContainerExceptionWhenInstantiationFails(): void
    {
        // ProtectedConstructorService has a protected constructor with a dependency.
        // newInstanceArgs() raises ReflectionException for non-public constructors
        // when called from outside the class; the container wraps it in ContainerException.
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/Failed to construct/');

        $this->container->get(ProtectedConstructorService::class);
    }

    // ------------------------------------------------------------------
    // Circular dependency detection
    // ------------------------------------------------------------------

    public function testCircularDependencyThrowsContainerException(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/Circular dependency detected/');

        $this->container->get(CircularA::class);
    }

    // ------------------------------------------------------------------
    // make() — always-fresh resolution
    // ------------------------------------------------------------------

    public function testMakeReturnsFreshInstanceFromTransientBinding(): void
    {
        $this->container->bind(SimpleService::class, fn() => new SimpleService());

        $a = $this->container->make(SimpleService::class);
        $b = $this->container->make(SimpleService::class);

        self::assertNotSame($a, $b);
    }

    public function testMakeBypassesSingletonCache(): void
    {
        $this->container->singleton(SimpleService::class, fn() => new SimpleService());

        $a = $this->container->get(SimpleService::class);  // caches
        $b = $this->container->make(SimpleService::class); // bypasses cache

        self::assertNotSame($a, $b);
    }

    public function testMakeReturnsCachedInstanceWhenRegisteredViaInstance(): void
    {
        $svc = new SimpleService();
        $this->container->instance(SimpleService::class, $svc);

        self::assertSame($svc, $this->container->make(SimpleService::class));
    }

    public function testMakeAutoWiresWhenNoBinding(): void
    {
        $svc = $this->container->make(SimpleService::class);
        self::assertInstanceOf(SimpleService::class, $svc);
    }

    // ------------------------------------------------------------------
    // Re-registration (overwrite)
    // ------------------------------------------------------------------

    public function testRebindingOverwritesPreviousBinding(): void
    {
        $this->container->bind(SimpleService::class, function () {
            $s = new SimpleService();
            $s->tag = 'v1';
            return $s;
        });

        $this->container->bind(SimpleService::class, function () {
            $s = new SimpleService();
            $s->tag = 'v2';
            return $s;
        });

        self::assertSame('v2', $this->container->get(SimpleService::class)->tag);
    }

    public function testSingletonOverwritesClearsCache(): void
    {
        $this->container->singleton(SimpleService::class, function () {
            $s = new SimpleService();
            $s->tag = 'old';
            return $s;
        });
        $this->container->get(SimpleService::class); // resolve + cache

        // Re-register as singleton with new factory.
        $this->container->singleton(SimpleService::class, function () {
            $s = new SimpleService();
            $s->tag = 'new';
            return $s;
        });

        self::assertSame('new', $this->container->get(SimpleService::class)->tag);
    }

    // ------------------------------------------------------------------
    // Interface binding
    // ------------------------------------------------------------------

    public function testBindingInterfaceToConcreteClass(): void
    {
        $this->container->bind(
            SomeInterface::class,
            fn(Container $c) => new ConcreteService($c->get(SimpleService::class)),
        );

        $resolved = $this->container->get(SomeInterface::class);
        self::assertInstanceOf(SomeInterface::class, $resolved);
        self::assertInstanceOf(ConcreteService::class, $resolved);
    }
}
