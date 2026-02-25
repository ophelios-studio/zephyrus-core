<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use Zephyrus\Http\Request;

final class AnyAuthGuard implements AuthGuardInterface
{
    /**
     * @param array<int, AuthGuardInterface> $guards
     */
    public function __construct(private readonly array $guards)
    {
    }

    public function isAuthorized(Request $request): bool
    {
        foreach ($this->guards as $guard) {
            if ($guard->isAuthorized($request)) {
                return true;
            }
        }

        return false;
    }
}
