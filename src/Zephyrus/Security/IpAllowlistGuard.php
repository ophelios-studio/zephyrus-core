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
        if ($ip === null) {
            return false;
        }

        foreach ($this->allowedIps as $allowedIp) {
            if ($allowedIp === $ip || $this->matchesCidr($ip, $allowedIp)) {
                return true;
            }
        }

        return false;
    }

    private function matchesCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return false;
        }

        [$network, $prefixRaw] = explode('/', $cidr, 2);
        if ($network === '' || $prefixRaw === '' || !ctype_digit($prefixRaw)) {
            return false;
        }

        $ipBinary = inet_pton($ip);
        $networkBinary = inet_pton($network);
        if ($ipBinary === false || $networkBinary === false || strlen($ipBinary) !== strlen($networkBinary)) {
            return false;
        }

        $maxBits = strlen($ipBinary) * 8;
        $prefix = (int) $prefixRaw;
        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if (substr($ipBinary, 0, $fullBytes) !== substr($networkBinary, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainingBits);

        return (ord($ipBinary[$fullBytes]) & $mask) === (ord($networkBinary[$fullBytes]) & $mask);
    }
}
