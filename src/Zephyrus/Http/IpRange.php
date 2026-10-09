<?php

declare(strict_types=1);

namespace Zephyrus\Http;

/**
 * Shared parser for IP addresses and CIDR ranges. Every trust or allow decision
 * goes through here, and anything it cannot parse is a non-match, never a match.
 */
final class IpRange
{
    /**
     * Whether the value is an IP address or a CIDR range this class can match against.
     */
    public static function isValid(string $range): bool
    {
        return self::parse($range) !== null;
    }

    /**
     * Whether $ip is inside $range. A range without a prefix is an exact address
     * match. Malformed input on either side returns false.
     */
    public static function contains(string $range, string $ip): bool
    {
        $parsed = self::parse($range);
        $ipBinary = self::toBinary($ip);

        if ($parsed === null || $ipBinary === null) {
            return false;
        }

        [$networkBinary, $prefix] = $parsed;
        if (strlen($ipBinary) !== strlen($networkBinary)) {
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

        $mask = (0xff << (8 - $remainingBits)) & 0xff;

        return (ord($ipBinary[$fullBytes]) & $mask) === (ord($networkBinary[$fullBytes]) & $mask);
    }

    /**
     * @return array{string, int}|null Binary network address and prefix length in bits.
     */
    private static function parse(string $range): ?array
    {
        if (str_contains($range, "\0")) {
            return null;
        }

        $slash = strpos($range, '/');
        if ($slash === false) {
            $address = $range;
            $prefix = null;
        } else {
            $address = substr($range, 0, $slash);
            $prefixDigits = substr($range, $slash + 1);
            if (preg_match('/^\d{1,3}$/D', $prefixDigits) !== 1) {
                return null;
            }
            $prefix = (int) $prefixDigits;
        }

        $binary = self::toBinary($address);
        if ($binary === null) {
            return null;
        }

        $bits = strlen($binary) * 8;
        if ($prefix === null) {
            $prefix = $bits;
        }

        if ($prefix > $bits) {
            return null;
        }

        return [$binary, $prefix];
    }

    /**
     * filter_var rejects what inet_pton tolerates on some platforms (leading zero
     * octets, zone identifiers), so it runs first.
     */
    private static function toBinary(string $address): ?string
    {
        if (str_contains($address, "\0") || filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $binary = inet_pton($address);

        return $binary === false ? null : $binary;
    }
}
