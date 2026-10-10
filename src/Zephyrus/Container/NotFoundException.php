<?php

declare(strict_types=1);

namespace Zephyrus\Container;

/**
 * Thrown when no binding exists for an identifier and it is not auto-wireable.
 */
class NotFoundException extends ContainerException
{
}
