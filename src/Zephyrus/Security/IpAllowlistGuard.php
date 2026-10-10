<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use InvalidArgumentException;
use Zephyrus\Exceptions\MessageValue;
use Zephyrus\Http\IpRange;
use Zephyrus\Http\Request;

/**
 * Authorises a request whose client IP is in the allowlist of addresses or CIDR ranges.
 */
final class IpAllowlistGuard implements AuthGuardInterface
{
    /**
     * @param list<string> $allowedIps IP addresses or CIDR ranges.
     * @throws InvalidArgumentException When an entry is not an IP address or a CIDR range.
     */
    public function __construct(private readonly array $allowedIps)
    {
        foreach ($allowedIps as $entry) {
            $reason = IpRange::invalidEntryReason($entry);
            if ($reason !== null) {
                throw new InvalidArgumentException(sprintf(
                    'Allowed IP %s: %s.',
                    MessageValue::quote($entry),
                    $reason,
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
