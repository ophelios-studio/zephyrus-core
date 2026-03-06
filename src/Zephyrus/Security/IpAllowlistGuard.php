<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use Zephyrus\Http\Request;

final class IpAllowlistGuard implements AuthGuardInterface
{
    /**
     * @param list<string> $allowedIps
     */
    public function __construct(private readonly array $allowedIps)
    {
    }

    public function isAuthorized(Request $request): bool
    {
        $ip = $request->clientIp();

        return $ip !== null && in_array($ip, $this->allowedIps, true);
    }
}
