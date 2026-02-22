<?php

declare(strict_types=1);

namespace Zephyrus\Routing\Exception;

use ReflectionException;
use RuntimeException;

final class RouteAttributeException extends RuntimeException
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
