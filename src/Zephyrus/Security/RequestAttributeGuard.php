<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use Zephyrus\Http\Request;

final class RequestAttributeGuard implements AuthGuardInterface
{
    /**
     * @param list<scalar|null> $allowedValues
     */
    public function __construct(
        private readonly string $attribute,
        private readonly array $allowedValues,
    ) {
    }

    public function isAuthorized(Request $request): bool
    {
        $value = $request->attribute($this->attribute);

        if (is_array($value) || is_object($value)) {
            return false;
        }

        return in_array($value, $this->allowedValues, true);
    }
}
