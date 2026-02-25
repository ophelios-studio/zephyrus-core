<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use Closure;
use Zephyrus\Http\Request;

final class PredicateAuthGuard implements AuthGuardInterface
{
    /**
     * @param Closure(Request): bool $predicate
     */
    public function __construct(private readonly Closure $predicate)
    {
    }

    public function isAuthorized(Request $request): bool
    {
        return ($this->predicate)($request);
    }
}
