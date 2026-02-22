<?php

declare(strict_types=1);

namespace Zephyrus\Routing\Exception;

use Zephyrus\Exceptions\ZephyrusRuntimeException;

final class MethodNotAllowedException extends ZephyrusRuntimeException
{
    /**
     * @param array<int, string> $allowedMethods
     */
    public function __construct(
        public readonly array $allowedMethods,
        string $path,
    ) {
        parent::__construct(sprintf(
            'Method not allowed for %s. Allowed: %s',
            $path,
            implode(', ', $allowedMethods),
        ));
    }
}
