<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use Zephyrus\Http\IpRange;
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
        if ($ip === null) {
            return false;
        }

        foreach ($this->allowedIps as $allowedIp) {
            if (IpRange::contains($allowedIp, $ip)) {
                return true;
            }
        }

        return false;
    }
}
