<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use Zephyrus\Http\Request;

interface AuthGuardInterface
{
    public function isAuthorized(Request $request): bool;
}
