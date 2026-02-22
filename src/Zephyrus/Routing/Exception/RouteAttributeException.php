<?php

declare(strict_types=1);

namespace Zephyrus\Routing\Exception;

use ReflectionException;
use Zephyrus\Exceptions\ZephyrusRuntimeException;

final class RouteAttributeException extends ZephyrusRuntimeException
{
    public static function unresolvableClass(string $className, ReflectionException $previous): self
    {
        return new self(
            sprintf('Cannot read route attributes from class "%s": %s', $className, $previous->getMessage()),
            0,
            $previous,
        );
    }
}
