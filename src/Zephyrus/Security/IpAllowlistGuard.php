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
        $ip = $this->resolveIp($request);

        return $ip !== null && in_array($ip, $this->allowedIps, true);
    }

    private function resolveIp(Request $request): ?string
    {
        $forwarded = $request->header('X-Forwarded-For');
        if (is_string($forwarded) && $forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0] ?? '');
            if ($first !== '') {
                return $first;
            }
        }

        $remote = $request->attribute('client_ip');
        if (is_string($remote) && $remote !== '') {
            return $remote;
        }

        return null;
    }
}
