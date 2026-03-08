<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use Zephyrus\Http\Request;

final class HeaderTokenGuard implements AuthGuardInterface
{
    public function __construct(
        private readonly string $expectedToken,
        private readonly string $headerName = 'Authorization',
        private readonly string $bearerPrefix = 'Bearer ',
    ) {
    }

    public function isAuthorized(Request $request): bool
    {
        $token = $request->bearerToken($this->headerName, $this->bearerPrefix);
        if ($token === null || $token === '') {
            return false;
        }

        return hash_equals($this->expectedToken, $token);
    }
}
