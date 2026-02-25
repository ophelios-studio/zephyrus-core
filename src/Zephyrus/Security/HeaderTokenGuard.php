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
        $value = $request->header($this->headerName);
        if ($value === null || $value === '') {
            return false;
        }

        $token = $this->extractToken($value);
        if ($token === '') {
            return false;
        }

        return hash_equals($this->expectedToken, $token);
    }

    private function extractToken(string $value): string
    {
        if ($this->bearerPrefix === '') {
            return trim($value);
        }

        if (str_starts_with($value, $this->bearerPrefix)) {
            return trim(substr($value, strlen($this->bearerPrefix)));
        }

        return trim($value);
    }
}
