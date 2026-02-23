<?php

declare(strict_types=1);

namespace Zephyrus\Container;

/**
 * Thrown when the container cannot locate a binding for the requested
 * identifier and it is not an auto-wireable class.
 */
class NotFoundException extends ContainerException
{
}
