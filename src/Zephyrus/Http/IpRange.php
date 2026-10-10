<?php

declare(strict_types=1);

namespace Zephyrus\Http;

/**
 * Shared parser for IP addresses and CIDR ranges, used by trusted proxy and IP
 * allowlist matching. Anything it cannot parse is a non-match, never a match.
 */
final class IpRange
{
    private const int SHOWN_LENGTH = 64;

    private const string MAPPED_REFUSAL = 'an IPv4-mapped IPv6 range shorter than /96 covers far more than the IPv4 range it names, use the IPv4 form instead, such as 10.0.0.0/8, or ::ffff:10.0.0.0/104 for peers seen in mapped form';

    private const string EMBEDDED_REFUSAL = 'an IPv6 range shorter than /96 that embeds an IPv4 address covers far more than the IPv4 range it names, use the IPv4 form instead, such as 10.0.0.0/8';

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
     *
     * @internal
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
     * Why an IP address or CIDR range cannot be matched, or null when it can.
     */
    public static function invalidEntryReason(string $entry): ?string
    {
        $parsed = self::split($entry);
        if ($parsed === null) {
            return 'not an IP address or a CIDR range such as 10.0.0.0/8 or 2001:db8::/32';
        }

        return self::refusal($entry, $parsed[0], $parsed[1]);
    }

    /**
     * @return array{string, int}|null Binary network address and prefix length in bits.
     */
    private static function parse(string $range): ?array
    {
        $parsed = self::split($range);
        if ($parsed === null || self::refusal($range, $parsed[0], $parsed[1]) !== null) {
            return null;
        }

        return $parsed;
    }

    /**
     * The reason a parsed range is refused, or null when it is safe.
     */
    private static function refusal(string $range, string $binary, int $prefix): ?string
    {
        return self::embeddedIpv4Refusal($range, $binary, $prefix)
            ?? self::hostBitsRefusal($range, $binary, $prefix);
    }

    /**
     * The refusal for an IPv6 range shorter than /96 whose address embeds an IPv4
     * address, or null when it is safe. Such a range masks the IPv4 part away, so it
     * matches far more than it names. From /96 on, hostBitsRefusal() applies.
     */
    private static function embeddedIpv4Refusal(string $range, string $binary, int $prefix): ?string
    {
        if ($prefix >= 96 || !self::embedsIpv4($range, $binary)) {
            return null;
        }

        return self::isIpv4Mapped($binary) ? self::MAPPED_REFUSAL : self::EMBEDDED_REFUSAL;
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

    /**
     * Whether the address is IPv4-mapped, or written with a dotted quad inside an IPv6 literal.
     */
    private static function embedsIpv4(string $range, string $binary): bool
    {
        return self::isIpv4Mapped($binary) || (str_contains($range, '.') && str_contains($range, ':'));
    }

    private static function isIpv4Mapped(string $binary): bool
    {
        return strlen($binary) === 16 && substr($binary, 0, 12) === str_repeat("\0", 10) . "\xff\xff";
    }

    /**
     * The refusal for a range whose address has bits after its prefix, naming the range it really covers.
     */
    private static function hostBitsRefusal(string $range, string $binary, int $prefix): ?string
    {
        if (!self::hasBitsAfterPrefix($binary, $prefix)) {
            return null;
        }

        if (self::embedsIpv4($range, $binary)) {
            return self::embeddedIpv4HostBitsRefusal($range, $binary, $prefix);
        }

        $address = explode('/', $range, 2)[0];

        if (strlen($binary) === 4 && $prefix === 0) {
            return sprintf('%s covers every IPv4 address; write %s/32 for one IPv4 peer', $range, $address);
        }

        if (self::containsIpv4MappedBlock($binary, $prefix)) {
            return sprintf(
                '%s covers every IPv4 peer on a dual-stack socket; write %s/128 for one IPv6 peer',
                $range,
                $address,
            );
        }

        $network = inet_ntop($binary & self::netmask(strlen($binary), $prefix));
        $addressBits = strlen($binary) * 8;

        return sprintf(
            '%s covers %s/%d; write %s/%d or %s/%d',
            $range,
            $network,
            $prefix,
            $address,
            $addressBits,
            $network,
            $prefix,
        );
    }

    /**
     * The refusal for an IPv6 range at /96 or longer with host bits, which names the IPv4
     * range it means. The masked IPv6 network is withheld only when it would cover every IPv4 peer.
     */
    private static function embeddedIpv4HostBitsRefusal(string $range, string $binary, int $prefix): string
    {
        $address = explode('/', $range, 2)[0];
        $ipv4 = substr($binary, 12);
        $ipv4Prefix = $prefix - 96;

        if ($ipv4Prefix === 0) {
            return sprintf(
                '%s covers every IPv4 address; write %s/128 for one IPv6 peer or %s/32 for one IPv4 peer',
                $range,
                $address,
                inet_ntop($ipv4),
            );
        }

        $network = inet_ntop($binary & self::netmask(16, $prefix));

        return sprintf(
            '%s covers %s/%d; write %s/%d (IPv4 form) or %s/%d',
            $range,
            $network,
            $prefix,
            inet_ntop($ipv4 & self::netmask(4, $ipv4Prefix)),
            $ipv4Prefix,
            $network,
            $prefix,
        );
    }

    /**
     * Whether an IPv6 network with this prefix contains every IPv4-mapped address (::ffff:0:0/96).
     */
    private static function containsIpv4MappedBlock(string $binary, int $prefix): bool
    {
        if (strlen($binary) !== 16 || $prefix > 96) {
            return false;
        }

        $mask = self::netmask(16, $prefix);

        return ("\0\0\0\0\0\0\0\0\0\0\xff\xff\0\0\0\0" & $mask) === ($binary & $mask);
    }

    private static function netmask(int $length, int $prefix): string
    {
        $mask = str_repeat("\xff", intdiv($prefix, 8));
        if ($prefix % 8 !== 0) {
            $mask .= chr((0xff << (8 - $prefix % 8)) & 0xff);
        }

        return str_pad($mask, $length, "\0");
    }

    /**
     * Whether any bit after the first $prefix bits of the binary address is set.
     */
    private static function hasBitsAfterPrefix(string $binary, int $prefix): bool
    {
        if ($prefix >= strlen($binary) * 8) {
            return false;
        }

        $byte = intdiv($prefix, 8);
        if ((ord($binary[$byte]) & (0xff >> ($prefix % 8))) !== 0) {
            return true;
        }

        return ltrim(substr($binary, $byte + 1), "\0") !== '';
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
