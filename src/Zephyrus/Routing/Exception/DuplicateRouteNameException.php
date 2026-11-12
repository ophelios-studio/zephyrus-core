<?php

declare(strict_types=1);

namespace Zephyrus\Routing\Exception;

use Zephyrus\Exceptions\ZephyrusRuntimeException;

final class DuplicateRouteNameException extends ZephyrusRuntimeException
{
    /**
     * @param list<int|string> $names
     */
    public static function forNames(array $names): self
    {
        return new self(sprintf('Duplicate route names detected: %s', implode(', ', $names)));
    }
}
