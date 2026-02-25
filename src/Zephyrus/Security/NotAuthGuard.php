<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use Zephyrus\Http\Request;

final class NotAuthGuard implements AuthGuardInterface
{
    public function __construct(private readonly AuthGuardInterface $inner)
    {
    }

    public function isAuthorized(Request $request): bool
    {
        return !$this->inner->isAuthorized($request);
    }
}
