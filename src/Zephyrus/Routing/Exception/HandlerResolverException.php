<?php

declare(strict_types=1);

namespace Zephyrus\Routing\Exception;

use ReflectionException;
use RuntimeException;

final class HandlerResolverException extends RuntimeException
{
    public static function invalidHandlerFormat(string $handler): self
    {
        return new self(
            sprintf(
                'Handler "%s" is not a valid ClassName@method string.',
                $handler,
            ),
        );
    }

    public static function unresolvableClass(string $className, ReflectionException $previous): self
    {
        return new self(
            sprintf('Cannot instantiate handler class "%s": %s', $className, $previous->getMessage()),
            0,
            $previous,
        );
    }

    public static function unresolvableMethod(string $className, string $method, ReflectionException $previous): self
    {
        return new self(
            sprintf('Method "%s::%s" does not exist.', $className, $method),
            0,
            $previous,
        );
    }

    public static function unresolvedParameter(string $className, string $method, string $parameter): self
    {
        return new self(
            sprintf(
                'Cannot resolve parameter "$%s" for "%s::%s": no matching request attribute, type hint, or default value.',
                $parameter,
                $className,
                $method,
            ),
        );
    }
}
