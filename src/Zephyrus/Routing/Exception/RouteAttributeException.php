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

    public static function duplicateRouteName(string $className, string $routeName): self
    {
        return new self(sprintf(
            'Duplicate route name "%s" discovered while reading attributes on class "%s".',
            $routeName,
            $className,
        ));
    }

    /**
     * Two routes of one name that no #[RequiresEnv] pair keeps apart, so both can be registered at once.
     */
    public static function coexistingRouteNames(string $routeName, string $firstHandler, string $secondHandler): self
    {
        return new self(sprintf(
            'Route name "%s" is declared by %s and by %s, which can be registered together: rename one, or require '
            . 'one environment variable at a different value on each with #[RequiresEnv].',
            $routeName,
            $firstHandler,
            $secondHandler,
        ));
    }

    /**
     * A #[RequiresEnv] whose variable EnvironmentVariable::read() refuses to read.
     */
    public static function unreadableRequirement(string $className, \InvalidArgumentException $previous): self
    {
        return new self(
            sprintf('Cannot read the #[RequiresEnv] of class "%s": %s', $className, $previous->getMessage()),
            0,
            $previous,
        );
    }
}
