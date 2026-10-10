<?php

declare(strict_types=1);

namespace Zephyrus\Http;

/**
 * Shared parser for IP addresses and CIDR ranges, used by trusted proxy and IP
 * allowlist matching. Anything it cannot parse is a non-match, never a match.
 * Debugger client lists are matched by Tracy, see DebugIntegration.
 */
final class IpRange
{
    private const int SHOWN_LENGTH = 64;

    public const string IPV4_MAPPED_REFUSAL = 'an IPv4-mapped IPv6 range shorter than /96 covers far more than the IPv4 range it names, use the IPv4 form instead, such as 10.0.0.0/8';

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
     * An entry as it may appear in an error message: at most 64 bytes, then the
     * byte count when cut, with control characters escaped.
     */
    public static function shownEntry(string $entry): string
    {
        $shown = addcslashes(
            mb_strcut(mb_scrub($entry, 'UTF-8'), 0, self::SHOWN_LENGTH, 'UTF-8'),
            "\\\0..\37\177",
        );

        return strlen($entry) > self::SHOWN_LENGTH
            ? sprintf('%s...(%d bytes)', $shown, strlen($entry))
            : $shown;
    }

    /**
     * Whether the range is an IPv4-mapped IPv6 range (::ffff:a.b.c.d/N) with N below 96.
     * Such a range covers ::/8-style spans of IPv6 space, not the IPv4 range it names.
     */
    public static function isIpv4MappedBelow96(string $range): bool
    {
        $parsed = self::split($range);

        return $parsed !== null && self::isShortIpv4Mapped($parsed[0], $parsed[1]);
    }

    /**
     * @return array{string, int}|null Binary network address and prefix length in bits.
     */
    private static function parse(string $range): ?array
    {
        $parsed = self::split($range);
        if ($parsed === null || self::isShortIpv4Mapped($parsed[0], $parsed[1])) {
            return null;
        }

        return $parsed;
    }

    /**
     * @return array{string, int}|null
     */
    private static function split(string $range): ?array
    {
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

    private static function isShortIpv4Mapped(string $binary, int $prefix): bool
    {
        return $prefix < 96 && strlen($binary) === 16 && substr($binary, 0, 12) === str_repeat("\0", 10) . "\xff\xff";
    }

    /**
     * filter_var rejects what inet_pton tolerates on some platforms (leading zero
     * octets, zone identifiers), so it runs first.
     */
    private static function toBinary(string $address): ?string
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $binary = inet_pton($address);

        return $binary === false ? null : $binary;
    }
}
