<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use Zephyrus\Http\Request;

/**
 * Decides whether a request may proceed. Wrap in AuthGuardMiddleware (401 on false);
 * PredicateAuthGuard covers a closure.
 */
interface AuthGuardInterface
{
    public function isAuthorized(Request $request): bool;
}
