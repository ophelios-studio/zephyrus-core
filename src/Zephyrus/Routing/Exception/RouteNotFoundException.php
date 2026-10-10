<?php

declare(strict_types=1);

namespace Zephyrus\Routing\Exception;

use Zephyrus\Exceptions\ZephyrusRuntimeException;

final class RouteNotFoundException extends ZephyrusRuntimeException
{
    public const string REASON_CONTROL_CHARACTER = 'contains a control character';
    public const string REASON_INVALID_UTF8 = 'is not valid UTF-8';

    /**
     * @param string $reason One of the REASON_* constants.
     */
    public static function refusedPath(string $method, string $reason): self
    {
        return new self(sprintf('No route matched %s: the request path %s', strtoupper($method), $reason));
    }
}
