<?php

declare(strict_types=1);

namespace Zephyrus\Routing\Exception;

use Zephyrus\Exceptions\ZephyrusRuntimeException;

/**
 * Thrown when URL generation for a named route fails.
 */
final class RouteUrlGenerationException extends ZephyrusRuntimeException
{
    public static function unknownRoute(string $name): self
    {
        return new self(sprintf('Unknown route name: %s', $name));
    }

    public static function missingParameter(string $parameter, string $route): self
    {
        return new self(sprintf('Missing route parameter "%s" for route "%s"', $parameter, $route));
    }

    public static function unexpectedParameter(string $parameter, string $route): self
    {
        return new self(sprintf('Unexpected route parameter "%s" for route "%s"', $parameter, $route));
    }

    public static function constraintViolation(string $parameter, string $route, string $detail): self
    {
        return new self(sprintf(
            'Route parameter "%s" for route "%s": %s',
            $parameter,
            $route,
            $detail,
        ));
    }

    public static function invalidTtl(): self
    {
        return new self('Temporary signed URL TTL must be greater than zero seconds');
    }

    public static function signatureUnavailable(): self
    {
        return new self('Cannot generate signed URL without a RouteSignature instance');
    }
}
