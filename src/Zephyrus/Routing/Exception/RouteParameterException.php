<?php

declare(strict_types=1);

namespace Zephyrus\Routing\Exception;

use Zephyrus\Exceptions\ZephyrusRuntimeException;

/**
 * Thrown when a route parameter value cannot be coerced to the type declared
 * on the controller method signature (e.g. "abc" for int $id).
 *
 * Mapped to a 404 Not Found response by HttpExceptionResponder — the
 * requested resource conceptually does not exist when the URL segment
 * fails type validation.
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
