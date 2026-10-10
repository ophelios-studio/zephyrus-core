<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use InvalidArgumentException;
use Zephyrus\Http\IpRange;
use Zephyrus\Http\Request;

final class IpAllowlistGuard implements AuthGuardInterface
{
    /**
     * @param list<string> $allowedIps IP addresses or CIDR ranges.
     * @throws InvalidArgumentException When an entry is not an IP address or a CIDR range.
     */
    public function __construct(private readonly array $allowedIps)
    {
        foreach ($allowedIps as $entry) {
            if (!IpRange::isValid($entry)) {
                throw new InvalidArgumentException(sprintf(
                    'Allowed IP "%s": %s.',
                    IpRange::shownEntry($entry),
                    IpRange::isIpv4MappedBelow96($entry)
                        ? IpRange::IPV4_MAPPED_REFUSAL
                        : 'not an IP address or a CIDR range such as 10.0.0.0/8 or 2001:db8::/32',
                ));
            }
        }
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
