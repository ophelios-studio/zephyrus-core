<?php

declare(strict_types=1);

namespace Zephyrus\Routing\Exception;

use Zephyrus\Exceptions\ZephyrusRuntimeException;

/**
 * Thrown when a route parameter cannot be coerced to the type declared on the
 * controller method (e.g. "abc" for int $id).
 *
 * HttpExceptionResponder maps it to 404 Not Found.
 */
final class RouteParameterException extends ZephyrusRuntimeException
{
    public function __construct(
        public readonly string $className,
        public readonly string $methodName,
        public readonly string $parameter,
        public readonly string $expectedType,
        public readonly mixed $actualValue,
    ) {
        $actualType = get_debug_type($actualValue);

        parent::__construct(sprintf(
            'Route parameter "$%s" for "%s::%s" could not be resolved: expected %s, got %s.',
            $parameter,
            $className,
            $methodName,
            $expectedType,
            $actualType,
        ));
    }
}
