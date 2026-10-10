<?php

declare(strict_types=1);

namespace Zephyrus\Container;

use Zephyrus\Exceptions\ZephyrusRuntimeException;

/**
 * Thrown when an entry cannot be resolved, for example on a circular dependency
 * or an unresolvable constructor parameter.
 */
class ContainerException extends ZephyrusRuntimeException
{
}
