<?php

declare(strict_types=1);

namespace Zephyrus\Session;

use Zephyrus\Exceptions\ZephyrusException;

final class SessionException extends ZephyrusException
{
    public static function invalidKey(string $key): self
    {
        return new self(sprintf('Session key must be a non-empty string. Got "%s".', $key));
    }
}
