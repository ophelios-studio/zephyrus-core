<?php

declare(strict_types=1);

namespace Zephyrus\Routing\Exception;

use Zephyrus\Exceptions\MessageValue;
use Zephyrus\Exceptions\ZephyrusRuntimeException;

final class RouteNotFoundException extends ZephyrusRuntimeException
{
    private const int MAX_PATH_BYTES = 512;

    private ?RoutePathRefusal $refusalReason = null;
    private ?string $refusedParameter = null;

    /** No route matches the path; refusalReason() and refusedParameter() are null. */
    public static function noRouteMatched(string $method, string $path): self
    {
        return new self(sprintf('No route matched %s %s', MessageValue::quote(strtoupper($method)), MessageValue::quote($path, self::MAX_PATH_BYTES)));
    }

    /** The request path was refused before matching; refusalReason() is set. */
    public static function pathIsRefused(string $method, RoutePathRefusal $reason): self
    {
        $exception = new self(sprintf(
            'No route matched %s: the request path %s',
            MessageValue::quote(strtoupper($method)),
            self::describe($reason),
        ));
        $exception->refusalReason = $reason;

        return $exception;
    }

    /** A route matched the path but a decoded parameter value was refused; refusalReason() and refusedParameter() are set. */
    public static function parameterIsRefused(string $method, string $path, string $parameter, RoutePathRefusal $reason): self
    {
        $exception = new self(sprintf(
            'No route matched %s %s: parameter %s %s',
            MessageValue::quote(strtoupper($method)),
            MessageValue::quote($path, self::MAX_PATH_BYTES),
            MessageValue::quote($parameter),
            self::describe($reason),
        ));
        $exception->refusalReason = $reason;
        $exception->refusedParameter = $parameter;

        return $exception;
    }

    /** Why the path or a parameter value was refused, or null for an ordinary not found. */
    public function refusalReason(): ?RoutePathRefusal
    {
        return $this->refusalReason;
    }

    /** The name of the parameter whose value was refused, or null for a refused path or an ordinary not found. */
    public function refusedParameter(): ?string
    {
        return $this->refusedParameter;
    }

    private static function describe(RoutePathRefusal $reason): string
    {
        return match ($reason) {
            RoutePathRefusal::ControlCharacter => 'contains a control character',
            RoutePathRefusal::InvalidUtf8 => 'is not valid UTF-8',
        };
    }
}
