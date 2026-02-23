<?php

declare(strict_types=1);

namespace Zephyrus\Container;

use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;

/**
 * Lightweight dependency-injection container with auto-wiring support.
 *
 * ## Registering entries
 *
 *   $c = new Container();
 *
 *   // Factory — new instance on every get():
 *   $c->bind(LoggerInterface::class, fn(Container $c) => new FileLogger('/tmp/app.log'));
 *
 *   // Singleton — first resolution is cached and reused:
 *   $c->singleton(Database::class, fn(Container $c) => new Database($c->get(Config::class)));
 *
 *   // Pre-built instance:
 *   $c->instance(Config::class, Config::fromArray($_ENV));
 *
 * ## Resolving entries
 *
 *   $logger = $c->get(LoggerInterface::class);
 *
 * ## Auto-wiring
 *
 *   When get() is called for a class that has no explicit binding, the container
 *   attempts to construct it automatically by reflecting on its constructor and
 *   recursively resolving each typed parameter.
 *
 *   class Mailer {
 *       public function __construct(private LoggerInterface $log) {}
 *   }
 *
 *   $mailer = $c->get(Mailer::class); // LoggerInterface resolved from registry
 */
final class Container implements ContainerInterface
{
    /**
     * Factory callables: id → callable(Container): mixed
     *
     * @var array<string, callable(static): mixed>
     */
    private array $bindings = [];

    /**
     * Singleton factories (not yet resolved): id → callable(Container): mixed
     *
     * @var array<string, callable(static): mixed>
     */
    private array $singletonFactories = [];

    /**
     * Resolved singleton/instance cache: id → mixed
     *
     * @var array<string, mixed>
     */
    private array $resolved = [];

    /**
     * IDs currently being resolved, used to detect circular dependencies.
     *
     * @var array<string, true>
     */
    private array $resolving = [];

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    /**
     * Register a factory binding.  Every call to get($id) invokes the factory
     * and returns a fresh instance.
     *
     * @param string                  $id      Identifier (usually a FQCN or interface name).
     * @param callable(static): mixed $factory Called with this container as its sole argument.
     */
    public function bind(string $id, callable $factory): void
    {
        $this->bindings[$id] = $factory;
        // Remove any stale singleton/resolved entries for this id.
        unset($this->singletonFactories[$id], $this->resolved[$id]);
    }

    /**
     * Register a singleton binding.  The factory is called once; subsequent
     * calls to get($id) return the cached instance.
     *
     * @param string                  $id      Identifier.
     * @param callable(static): mixed $factory Called once with this container.
     */
    public function singleton(string $id, callable $factory): void
    {
        $this->singletonFactories[$id] = $factory;
        // Remove any stale transient binding and cached value.
        unset($this->bindings[$id], $this->resolved[$id]);
    }

    /**
     * Register a pre-built value (always acts as a singleton).
     *
     * @param string $id    Identifier.
     * @param mixed  $value The value returned for every get($id) call.
     */
    public function instance(string $id, mixed $value): void
    {
        $this->resolved[$id] = $value;
        unset($this->bindings[$id], $this->singletonFactories[$id]);
    }

    // -------------------------------------------------------------------------
    // Resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve the entry for $id.
     *
     * Resolution order:
     *   1. Resolved singleton/instance cache.
     *   2. Singleton factory (invoke once, cache result).
     *   3. Transient factory (invoke on every call).
     *   4. Auto-wire (reflect + recurse).
     *
     * @throws NotFoundException  When no binding exists and $id is not an auto-wireable class.
     * @throws ContainerException On circular dependency or unresolvable constructor param.
     */
    public function get(string $id): mixed
    {
        // 1. Cached resolved value (singleton / instance).
        if (array_key_exists($id, $this->resolved)) {
            return $this->resolved[$id];
        }

        // Circular dependency guard.
        if (isset($this->resolving[$id])) {
            throw new ContainerException(
                "Circular dependency detected while resolving [{$id}]."
            );
        }

        $this->resolving[$id] = true;

        try {
            // 2. Singleton factory — invoke once and cache.
            // The factory is intentionally kept in $singletonFactories so that
            // make() can still call it for a fresh (non-cached) instance.
            if (isset($this->singletonFactories[$id])) {
                $value = ($this->singletonFactories[$id])($this);
                $this->resolved[$id] = $value;

                return $value;
            }

            // 3. Transient factory — new instance every call.
            if (isset($this->bindings[$id])) {
                return ($this->bindings[$id])($this);
            }

            // 4. Auto-wire.
            return $this->autoWire($id);
        } finally {
            unset($this->resolving[$id]);
        }
    }

    /**
     * Return true when the container can supply the requested $id via an
     * explicit binding, a cached resolved value, or a discoverable class.
     */
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->resolved)
            || isset($this->singletonFactories[$id])
            || isset($this->bindings[$id])
            || class_exists($id);
    }

    /**
     * Resolve and return a *fresh* instance of $id, bypassing any singleton
     * cache.  Useful when you need a new copy of a normally-shared service.
     *
     * For transient bindings this is equivalent to get().
     * For pre-built instances registered via instance(), the cached value is
     * still returned (there is no factory to call again).
     *
     * @throws NotFoundException  When no binding exists and $id is not a class.
     * @throws ContainerException On circular dependency or unresolvable param.
     */
    public function make(string $id): mixed
    {
        // Transient factory — always fresh.
        if (isset($this->bindings[$id])) {
            return ($this->bindings[$id])($this);
        }

        // Singleton factory — call directly, do NOT cache.
        if (isset($this->singletonFactories[$id])) {
            return ($this->singletonFactories[$id])($this);
        }

        // Pre-built instance — no factory, return as-is.
        if (array_key_exists($id, $this->resolved)) {
            return $this->resolved[$id];
        }

        // Auto-wire fresh.
        return $this->autoWire($id);
    }

    // -------------------------------------------------------------------------
    // Auto-wiring internals
    // -------------------------------------------------------------------------

    /**
     * Reflect on $className and construct it by recursively resolving each
     * typed constructor parameter from this container.
     *
     * @throws NotFoundException  When $className is not a loadable class.
     * @throws ContainerException When a constructor parameter cannot be resolved.
     */
    private function autoWire(string $className): object
    {
        if (!class_exists($className)) {
            throw new NotFoundException(
                "No binding found and [{$className}] is not a resolvable class."
            );
        }

        try {
            $reflector = new ReflectionClass($className);
        } catch (ReflectionException $e) {
            throw new ContainerException(
                "Failed to reflect class [{$className}]: {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($reflector->isAbstract()) {
            throw new ContainerException(
                "Cannot auto-wire abstract class [{$className}]; register an explicit binding."
            );
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return $reflector->newInstanceWithoutConstructor();
        }

        $args = [];

        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                // Recursively resolve from container.
                $args[] = $this->get($type->getName());
                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            throw new ContainerException(
                "Cannot auto-wire parameter \${$param->getName()} of [{$className}]: "
                . "no type hint and no default value."
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
