<?php

declare(strict_types=1);

namespace Zephyrus\Routing\Exception;

use Throwable;
use Zephyrus\Exceptions\ZephyrusRuntimeException;

final class RouteMiddlewareException extends ZephyrusRuntimeException
{
    public static function unknownMiddleware(string $name): self
    {
        return new self(sprintf('Unknown route middleware: %s', $name));
    }

    public static function resolutionFailed(string $name, Throwable $previous): self
    {
        return new self(
            sprintf('Unable to resolve route middleware "%s": %s', $name, $previous->getMessage()),
            0,
            $previous,
        );
    }

    /**
     * @param array<int, string> $stack
     */
    public static function circularGroupReference(array $stack, string $name): self
    {
        return new self(sprintf(
            'Circular middleware group reference detected: %s',
            implode(' -> ', [...$stack, $name]),
        ));
    }
}
