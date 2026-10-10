<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\DataProvider;
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

final class ServiceWithUntypedParam
{
    public function __construct($value) {}
}

final class ServiceWithScalarVariadic
{
    /** @var list<string> */
    public readonly array $tags;

    public function __construct(string ...$tags)
    {
        $this->tags = $tags;
    }
}

final class ServiceWithClassVariadic
{
    /** @var list<SimpleService> */
    public readonly array $services;

    public function __construct(SimpleService ...$services)
    {
        $this->services = $services;
    }
}

final class ServiceWithUntypedVariadic
{
    public function __construct(...$items) {}
}

final class ServiceWithMixedVariadic
{
    public function __construct(mixed ...$items) {}
}

final class ServiceWithCallableVariadic
{
    public function __construct(callable ...$handlers) {}
}

final class ServiceWithIterableVariadic
{
    public function __construct(iterable ...$items) {}
}

final class ServiceWithObjectVariadic
{
    public function __construct(object ...$items) {}
}

final class ServiceWithIntersectionVariadic
{
    public function __construct(\Countable&\Traversable ...$items) {}
}

final class ServiceWithNullableClassVariadic
{
    public function __construct(?SimpleService ...$services) {}
}

final class ServiceWithUnionClassVariadic
{
    public function __construct(SimpleService|int ...$values) {}
}

final class ServiceWithByReferenceClassVariadic
{
    public function __construct(SimpleService &...$services) {}
}

final class ServiceWithBuiltinUnionVariadic
{
    /** @var list<int|string> */
    public readonly array $values;

    public function __construct(int|string ...$values)
    {
        $this->values = $values;
    }
}

final class ServiceWithNullableUnionVariadic
{
    /** @var list<int|string|null> */
    public readonly array $values;

    public function __construct(int|string|null ...$values)
    {
        $this->values = $values;
    }
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
    // bind(): transient factories
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
    // singleton(): shared factories
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
    // instance(): pre-built values
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
        self::assertTrue($this->container->has(SimpleService::class));
    }

    public function testLoadedAnonymousClassIsHasAndResolvableThroughGetAndMake(): void
    {
        $instance = new class {};
        $id = $instance::class;

        self::assertTrue($this->container->has($id));
        self::assertInstanceOf($id, $this->container->get($id));
        self::assertInstanceOf($id, $this->container->make($id));
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedIdProvider(): iterable
    {
        yield 'leading digit' => ['1abc'];
        yield 'double backslash' => ['Vendor\\\\Name'];
        yield 'trailing backslash' => ['Vendor\\'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function idPhpRejectsBeforeAutoloaderProvider(): iterable
    {
        yield 'parent segment' => ['../x'];
        yield 'space' => ['A B'];
        yield 'nul byte' => ["\0"];
        yield 'empty' => [''];
    }

    #[DataProvider('malformedIdProvider')]
    public function testGetRefusesMalformedIdWithoutAutoloading(string $id): void
    {
        $calls = $this->countAutoloadCalls(function () use ($id): void {
            try {
                $this->container->get($id);
                self::fail('A malformed id must not resolve.');
            } catch (NotFoundException) {
            }
        });

        self::assertSame(0, $calls);
    }

    #[DataProvider('malformedIdProvider')]
    public function testMakeRefusesMalformedIdWithoutAutoloading(string $id): void
    {
        $calls = $this->countAutoloadCalls(function () use ($id): void {
            try {
                $this->container->make($id);
                self::fail('A malformed id must not resolve.');
            } catch (NotFoundException) {
            }
        });

        self::assertSame(0, $calls);
    }

    #[DataProvider('idPhpRejectsBeforeAutoloaderProvider')]
    public function testGetRefusesIdThatIsNotAClassNameAndHasIsFalse(string $id): void
    {
        self::assertFalse($this->container->has($id));

        $this->expectException(NotFoundException::class);

        $this->container->get($id);
    }

    public function testAutoWireNamesBuiltInTypeThatCannotBeProvided(): void
    {
        try {
            $this->container->get(ServiceWithUnresolvableParam::class);
            self::fail('An unprovidable scalar parameter must be refused.');
        } catch (ContainerException $e) {
            self::assertStringContainsString('parameter $dsn of [' . ServiceWithUnresolvableParam::class . ']', $e->getMessage());
            self::assertStringContainsString('built-in type string', $e->getMessage());
            self::assertStringContainsString(
                'add a class or interface type, give it a default value, or bind [' . ServiceWithUnresolvableParam::class . '] explicitly',
                $e->getMessage(),
            );
            self::assertStringNotContainsString('no type hint', $e->getMessage());
        }
    }

    public function testAutoWireUntypedParamWithoutDefaultNamesTheFix(): void
    {
        try {
            $this->container->get(ServiceWithUntypedParam::class);
            self::fail('An untyped parameter without a default must be refused.');
        } catch (ContainerException $e) {
            self::assertStringContainsString('parameter $value of [' . ServiceWithUntypedParam::class . ']', $e->getMessage());
            self::assertStringContainsString(
                'add a class or interface type, give it a default value, or bind [' . ServiceWithUntypedParam::class . '] explicitly',
                $e->getMessage(),
            );
        }
    }

    public function testAutoWireResolvesScalarVariadicToNoArguments(): void
    {
        $svc = $this->container->get(ServiceWithScalarVariadic::class);
        self::assertInstanceOf(ServiceWithScalarVariadic::class, $svc);
        self::assertSame([], $svc->tags);
    }

    public function testAutoWireResolvesBuiltinUnionVariadicToNoArguments(): void
    {
        $svc = $this->container->get(ServiceWithBuiltinUnionVariadic::class);
        self::assertInstanceOf(ServiceWithBuiltinUnionVariadic::class, $svc);
        self::assertSame([], $svc->values);
    }

    public function testAutoWireResolvesNullableScalarUnionVariadicToNoArguments(): void
    {
        $svc = $this->container->get(ServiceWithNullableUnionVariadic::class);
        self::assertInstanceOf(ServiceWithNullableUnionVariadic::class, $svc);
        self::assertSame([], $svc->values);
    }

    /**
     * @return iterable<string, array{string, string, string}> Class, parameter name, refused kind.
     */
    public static function refusedVariadicProvider(): iterable
    {
        $class = SimpleService::class;

        yield 'class' => [ServiceWithClassVariadic::class, 'services', "typed [{$class}]"];
        yield 'nullable class' => [ServiceWithNullableClassVariadic::class, 'services', "typed [?{$class}]"];
        yield 'union containing a class' => [ServiceWithUnionClassVariadic::class, 'values', "typed [{$class}|int]"];
        yield 'by-reference class' => [ServiceWithByReferenceClassVariadic::class, 'services', "typed [{$class}]"];
        yield 'intersection' => [ServiceWithIntersectionVariadic::class, 'items', 'typed [Countable&Traversable]'];
        yield 'untyped' => [ServiceWithUntypedVariadic::class, 'items', 'untyped'];
        yield 'mixed' => [ServiceWithMixedVariadic::class, 'items', 'typed [mixed]'];
        yield 'callable' => [ServiceWithCallableVariadic::class, 'handlers', 'typed [callable]'];
        yield 'iterable' => [ServiceWithIterableVariadic::class, 'items', 'typed [iterable]'];
        yield 'object' => [ServiceWithObjectVariadic::class, 'items', 'typed [object]'];
    }

    #[DataProvider('refusedVariadicProvider')]
    public function testAutoWireRefusesVariadicWhoseTypeIsNotScalar(string $id, string $param, string $kind): void
    {
        $this->assertAutoWireRefusesVariadic($id, $param, $kind);
    }

    public function testAutoWireRefusesClassVariadicEvenWhenItsClassIsBound(): void
    {
        $this->container->bind(SimpleService::class, fn(): SimpleService => new SimpleService());

        $this->assertAutoWireRefusesVariadic(
            ServiceWithClassVariadic::class,
            'services',
            'typed [' . SimpleService::class . ']',
        );
    }

    /**
     * Asserts that auto-wiring $id is refused with the full variadic message for $param of kind $kind.
     */
    private function assertAutoWireRefusesVariadic(string $id, string $param, string $kind): void
    {
        try {
            $this->container->get($id);
            self::fail("Variadic \${$param} of [{$id}] must be refused, not resolved.");
        } catch (ContainerException $e) {
            self::assertSame(
                "Cannot auto-wire parameter \${$param} of [{$id}]: it is variadic and {$kind}; "
                . "the container cannot choose how many to pass: bind [{$id}] explicitly with a factory.",
                $e->getMessage(),
            );
        }
    }

    /**
     * Runs $callback with a counting autoloader registered and returns the number of calls.
     */
    private function countAutoloadCalls(callable $callback): int
    {
        $calls = 0;
        $spy = static function () use (&$calls): void {
            $calls++;
        };

        spl_autoload_register($spy);

        try {
            $callback();
        } finally {
            spl_autoload_unregister($spy);
        }

        return $calls;
    }

    public function testAutoWireThrowsContainerExceptionForAbstractClass(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessageMatches('/Cannot auto-wire abstract class/');

        $this->container->get(AbstractService::class);
    }

    public function testAutoWireThrowsContainerExceptionWhenInstantiationFails(): void
    {
        // The container wraps the ReflectionException from newInstanceArgs() in ContainerException.
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
    // make(): always-fresh resolution
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
