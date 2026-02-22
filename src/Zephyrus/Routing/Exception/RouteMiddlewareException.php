<?php

declare(strict_types=1);

namespace Zephyrus\Routing\Exception;

use Zephyrus\Exceptions\ZephyrusRuntimeException;

final class RouteMiddlewareException extends ZephyrusRuntimeException
{
    public static function unknownMiddleware(string $name): self
    {
        return new self(sprintf('Unknown route middleware: %s', $name));
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
