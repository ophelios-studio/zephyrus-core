<?php

declare(strict_types=1);

namespace Zephyrus\Routing\Exception;

use Zephyrus\Exceptions\ZephyrusRuntimeException;

final class RouteSignatureException extends ZephyrusRuntimeException
{
    public static function invalidSignature(): self
    {
        return new self('Invalid route signature');
    }

    public static function expiredSignature(): self
    {
        return new self('Route signature has expired');
    }

    public static function malformedExpiry(): self
    {
        return new self('Route signature expiry value is malformed');
    }

    public static function invalidTtl(): self
    {
        return new self('Temporary signature TTL must be greater than zero seconds');
    }

    public static function invalidExpiryInstant(): self
    {
        return new self('Temporary signature expiry instant must be in the future');
    }
}
