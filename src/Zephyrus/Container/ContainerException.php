<?php

declare(strict_types=1);

namespace Zephyrus\Container;

use Zephyrus\Exceptions\ZephyrusRuntimeException;

/**
 * Thrown when the container encounters an error while resolving an entry —
 * for example, a circular dependency or an unresolvable constructor parameter.
 */
class ContainerException extends ZephyrusRuntimeException
{
}
