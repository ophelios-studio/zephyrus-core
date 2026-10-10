<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use Zephyrus\Http\Request;

/**
 * Authorises a request only when every wrapped guard authorises it.
 */
final class AllAuthGuard implements AuthGuardInterface
{
    /**
     * @param array<int, AuthGuardInterface> $guards
     * @throws \InvalidArgumentException if $guards is empty.
     */
    public function __construct(private readonly array $guards)
    {
        if ($guards === []) {
            throw new \InvalidArgumentException('At least one auth guard must be provided.');
        }
    }

    public function isAuthorized(Request $request): bool
    {
        foreach ($this->guards as $guard) {
            if (!$guard->isAuthorized($request)) {
                return false;
            }
        }

        return true;
    }
}
