<?php

declare(strict_types=1);

namespace Zephyrus\Container;

/**
 * Dependency injection container contract, with PSR-11 semantics and no psr/container dependency.
 *
 * Entries are resolved by string identifier, usually a fully-qualified class name.
 */
interface ContainerInterface
{
    /**
     * Return the entry for the given identifier.
     *
     * @throws NotFoundException    When no entry is found and cannot be auto-wired.
     * @throws ContainerException   When an error occurs while resolving the entry.
     */
    public function get(string $id): mixed;

    /**
     * Return true when a binding or an auto-wireable class exists for the identifier.
     *
     * This does not guarantee that get() succeeds.
     */
    public function has(string $id): bool;
}
