<?php

declare(strict_types=1);

namespace Zephyrus\Routing\Exception;

use ReflectionException;
use Throwable;
use Zephyrus\Exceptions\ZephyrusRuntimeException;

final class HandlerResolverException extends ZephyrusRuntimeException
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

    public static function unresolvableClass(string $className, Throwable $previous): self
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

    public static function invalidParameterValue(
        string $className,
        string $method,
        string $parameter,
        string $expectedType,
        mixed $actualValue,
    ): self {
        $actualType = get_debug_type($actualValue);

        return new self(sprintf(
            'Cannot resolve parameter "$%s" for "%s::%s": expected %s, got %s.',
            $parameter,
            $className,
            $method,
            $expectedType,
            $actualType,
        ));
    }
}
