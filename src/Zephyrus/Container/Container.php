<?php

declare(strict_types=1);

namespace Zephyrus\Container;

use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;

/**
 * Dependency injection container with auto-wiring of unbound concrete classes.
 *
 *   $c = new Container();
 *   $c->bind(LoggerInterface::class, fn(Container $c) => new FileLogger('/tmp/app.log'));
 *   $c->singleton(Database::class, fn(Container $c) => new Database($c->get(Config::class)));
 *   $c->instance(Config::class, Config::fromArray($_ENV));
 *
 *   // Mailer's typed constructor parameters are resolved from the container.
 *   $mailer = $c->get(Mailer::class);
 */
final class Container implements ContainerInterface
{
    /** @var array<string, callable(static): mixed> */
    private array $bindings = [];

    /** @var array<string, callable(static): mixed> */
    private array $singletonFactories = [];

    /** @var array<string, mixed> */
    private array $resolved = [];

    /** @var array<string, true> IDs being resolved, to detect circular dependencies. */
    private array $resolving = [];

    /**
     * Register a factory, invoked on every get() for a fresh instance.
     *
     * @param callable(static): mixed $factory
     */
    public function bind(string $id, callable $factory): void
    {
        $this->bindings[$id] = $factory;
        unset($this->singletonFactories[$id], $this->resolved[$id]);
    }

    /**
     * Register a factory invoked once, its result cached and returned by later get() calls.
     *
     * @param callable(static): mixed $factory
     */
    public function singleton(string $id, callable $factory): void
    {
        $this->singletonFactories[$id] = $factory;
        unset($this->bindings[$id], $this->resolved[$id]);
    }

    /**
     * Register a pre-built value, returned by every get() and make() call.
     */
    public function instance(string $id, mixed $value): void
    {
        $this->resolved[$id] = $value;
        unset($this->bindings[$id], $this->singletonFactories[$id]);
    }

    /**
     * Resolve the entry for $id, caching singleton results.
     *
     * Order: cached value, singleton factory, transient factory, auto-wiring.
     * Auto-wired classes are not cached; register a singleton to share one.
     *
     * @throws NotFoundException  When no binding exists and $id is not an auto-wireable class.
     * @throws ContainerException On circular dependency or unresolvable constructor param.
     */
    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->resolved)) {
            return $this->resolved[$id];
        }

        if (isset($this->resolving[$id])) {
            throw new ContainerException(
                "Circular dependency detected while resolving [{$id}]."
            );
        }

        $this->resolving[$id] = true;

        try {
            // The factory stays in $singletonFactories so make() can build a fresh instance.
            if (isset($this->singletonFactories[$id])) {
                $value = ($this->singletonFactories[$id])($this);
                $this->resolved[$id] = $value;

                return $value;
            }

            if (isset($this->bindings[$id])) {
                return ($this->bindings[$id])($this);
            }

            return $this->autoWire($id);
        } finally {
            unset($this->resolving[$id]);
        }
    }

    /**
     * Shape a string must have before it reaches the autoloader: a fully-qualified class name.
     * Guards has(), get() and make().
     */
    private const string CLASS_NAME_PATTERN = '/^\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/D';

    /**
     * Return true when $id has a binding, a cached value or a loadable class.
     *
     * Explicit entries are checked first. Other ids reach the autoloader only
     * when they match CLASS_NAME_PATTERN, so has() never autoloads an arbitrary string.
     */
    public function has(string $id): bool
    {
        if (
            array_key_exists($id, $this->resolved)
            || isset($this->singletonFactories[$id])
            || isset($this->bindings[$id])
        ) {
            return true;
        }

        return $this->isLoadableClass($id);
    }

    /**
     * Return true when $id is an already-loaded class or a well-formed name that autoloads.
     */
    private function isLoadableClass(string $id): bool
    {
        return class_exists($id, autoload: false)
            || (preg_match(self::CLASS_NAME_PATTERN, $id) === 1 && class_exists($id));
    }

    /**
     * Return a fresh instance of $id, bypassing the singleton cache.
     *
     * Singleton factories are called without caching their result. Values registered
     * with instance() are returned as-is.
     *
     * @throws NotFoundException  When no binding exists and $id is not an auto-wireable class.
     * @throws ContainerException On circular dependency or unresolvable constructor param.
     */
    public function make(string $id): mixed
    {
        if (isset($this->bindings[$id])) {
            return ($this->bindings[$id])($this);
        }

        if (isset($this->singletonFactories[$id])) {
            return ($this->singletonFactories[$id])($this);
        }

        if (array_key_exists($id, $this->resolved)) {
            return $this->resolved[$id];
        }

        return $this->autoWire($id);
    }

    /**
     * Construct $className, resolving each typed constructor parameter from this container.
     *
     * @throws NotFoundException  When $className is not a loadable class.
     * @throws ContainerException When a constructor parameter cannot be resolved.
     */
    private function autoWire(string $className): object
    {
        if (!$this->isLoadableClass($className)) {
            throw new NotFoundException(
                "No binding found and [{$className}] is not a resolvable class."
            );
        }

        // class_exists() above already guarantees this won't throw.
        $reflector = new ReflectionClass($className);

        if ($reflector->isAbstract()) {
            throw new ContainerException(
                "Cannot auto-wire abstract class [{$className}]; register an explicit binding."
            );
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return $reflector->newInstance();
        }

        $args = [];

        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $args[] = $this->get($type->getName());
                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            $name = $param->getName();

            if ($type === null) {
                throw new ContainerException(
                    "Cannot auto-wire parameter \${$name} of [{$className}]: no type hint and no default value."
                );
            }

            $typeName = $type instanceof ReflectionNamedType && $type->isBuiltin()
                ? "built-in type {$type}"
                : "type {$type}";

            throw new ContainerException(
                "Cannot auto-wire parameter \${$name} of [{$className}]: it has the {$typeName}, "
                . "which the container cannot provide, and no default value."
            );
        }

        try {
            return $reflector->newInstanceArgs($args);
        } catch (ReflectionException $e) {
            throw new ContainerException(
                "Failed to construct [{$className}]: {$e->getMessage()}",
                previous: $e,
            );
        }
    }
}
