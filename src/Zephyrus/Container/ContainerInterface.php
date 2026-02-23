<?php

declare(strict_types=1);

namespace Zephyrus\Container;

/**
 * Dependency injection container contract (mirrors PSR-11 semantics without
 * requiring the psr/container package).
 *
 * Implementations must resolve entries by their string identifier (typically a
 * fully-qualified class name) and throw ContainerException (or a subclass) for
 * any failure.
 */
interface ContainerInterface
{
    /**
     * Return the entry for the given identifier.
     *
     * @param string $id Identifier of the entry to look up.
     * @return mixed The resolved entry.
     *
     * @throws NotFoundException    When no entry is found and cannot be auto-wired.
     * @throws ContainerException   When an error occurs while resolving the entry.
     */
    public function get(string $id): mixed;

    /**
     * Return true if the container can resolve the given identifier.
     *
     * Returning true does NOT guarantee that get() will not throw — it only
     * means that a binding or auto-wireable class exists.
     *
     * @param string $id Identifier to check.
     */
    public function has(string $id): bool;
}
