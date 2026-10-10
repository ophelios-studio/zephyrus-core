<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Http\IpRange;

final class IpRangeTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function validRanges(): iterable
    {
        yield 'ipv4 cidr' => ['10.0.0.0/8'];
        yield 'ipv4 zero prefix' => ['0.0.0.0/0'];
        yield 'zero quad at /96 in mapped form' => ['::ffff:0.0.0.0/96'];
        yield 'mapped range at /104' => ['::ffff:10.0.0.0/104'];
        yield 'ipv4 full prefix' => ['10.0.0.0/32'];
        yield 'ipv4 bare address' => ['10.0.0.1'];
        yield 'ipv4 three digit prefix' => ['10.0.0.0/008'];
        yield 'ipv6 cidr' => ['2001:db8::/32'];
        yield 'ipv6 zero prefix' => ['::/0'];
        yield 'ipv6 full prefix' => ['::1/128'];
        yield 'ipv6 bare address' => ['::1'];
        yield 'ipv6 uppercase bare address' => ['2001:DB8::1'];
        yield 'ipv6 expanded bare address' => ['0:0:0:0:0:0:0:1'];
        yield 'ipv4 mapped range at /120' => ['::ffff:10.0.0.0/120'];
        yield 'ipv4 mapped full prefix' => ['::ffff:10.0.0.1/128'];
        yield 'ipv4 mapped bare address' => ['::ffff:10.0.0.1'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRanges(): iterable
    {
        yield 'empty' => [''];
        yield 'hex form that reads as ipv4 at /8 with host bits' => ['::a00:0/8'];
        yield 'only a slash' => ['/8'];
        yield 'empty prefix' => ['10.0.0.0/'];
        yield 'alphabetic prefix' => ['10.0.0.0/abc'];
        yield 'letter O for zero' => ['10.0.0.0/O8'];
        yield 'negative prefix' => ['10.0.0.0/-1'];
        yield 'signed prefix' => ['10.0.0.0/+8'];
        yield 'space in prefix' => ['10.0.0.0/ 8'];
        yield 'trailing space' => ['10.0.0.0/8 '];
        yield 'four digit prefix' => ['10.0.0.0/0008'];
        yield 'two slashes' => ['10.0.0.0/8/9'];
        yield 'ipv4 prefix too large' => ['10.0.0.0/33'];
        yield 'ipv6 prefix too large' => ['2001:db8::/129'];
        yield 'overflowing prefix' => ['10.0.0.0/' . str_repeat('9', 309)];
        yield 'overflowing prefix with zero' => ['0.0.0.0/' . str_repeat('0', 400) . '1'];
        yield 'nul in prefix' => ["10.0.0.0/8\0"];
        yield 'nul in address' => ["10.0.0.0\0/8"];
        yield 'nul only' => ["\0"];
        yield 'leading zero octet' => ['010.0.0.1'];
        yield 'leading zero octet in cidr' => ['010.0.0.0/8'];
        yield 'ipv6 zone identifier' => ['fe80::1%eth0'];
        yield 'ipv6 zone identifier in cidr' => ['fe80::1%eth0/64'];
        yield 'bracketed ipv6' => ['[::1]'];
        yield 'hostname' => ['example.com'];
        yield 'octet out of range' => ['999.1.1.1'];
        yield 'leading space' => [' 10.0.0.1'];
        yield 'ipv4 mapped range at /95' => ['::ffff:10.0.0.0/95'];
        yield 'ipv4 mapped range at /8' => ['::ffff:10.0.0.0/8'];
        yield 'ipv4 mapped range at /0' => ['::ffff:0.0.0.0/0'];
        yield 'ipv4 mapped range in hex' => ['::ffff:a00:0/8'];
        yield 'ipv4 compatible range below /96' => ['::10.0.0.0/8'];
        yield 'ipv4 compatible range at /95' => ['::10.0.0.0/95'];
        yield 'nat64 range below /96' => ['64:ff9b::10.0.0.0/8'];
        yield 'nat64 range at /95' => ['64:ff9b::10.0.0.0/95'];
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function ipv4MappedRanges(): iterable
    {
        yield 'short mapped range' => ['::ffff:10.0.0.0/8', true];
        yield 'mapped range at the boundary' => ['::ffff:10.0.0.0/95', true];
        yield 'mapped range at /96' => ['::ffff:10.0.0.0/96', false];
        yield 'plain ipv4 range' => ['10.0.0.0/8', false];
        yield 'plain ipv6 range' => ['2001:db8::/32', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('ipv4MappedRanges')]
    public function testInvalidEntryReasonUsesTheMappedReasonOnlyBelowSlash96(string $range, bool $expected): void
    {
        self::assertSame($expected, str_contains(IpRange::invalidEntryReason($range) ?? '', 'shorter than /96'));
    }

    public function testInvalidEntryReasonNamesTheMappedFormForMappedRanges(): void
    {
        self::assertStringContainsString('::ffff:10.0.0.0/104', IpRange::invalidEntryReason('::ffff:10.0.0.0/8') ?? '');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function embeddedIpv4RangesWithHostBits(): iterable
    {
        yield 'ipv4 compatible at /96' => ['::10.0.0.0/96'];
        yield 'nat64 at /96' => ['64:ff9b::10.0.0.0/96'];
        yield 'ipv4 mapped at /96' => ['::ffff:10.0.0.0/96'];
        yield 'ipv4 mapped at /96 in hex' => ['::ffff:a00:0/96'];
        yield 'ipv4 mapped at /127 with the last bit set' => ['::ffff:10.0.0.1/127'];
    }

    #[DataProvider('embeddedIpv4RangesWithHostBits')]
    public function testInvalidEntryReasonRefusesEmbeddedIpv4WithBitsAfterThePrefix(string $range): void
    {
        self::assertFalse(IpRange::isValid($range));
        self::assertStringContainsString('must be zero', IpRange::invalidEntryReason($range) ?? '');
        self::assertFalse(IpRange::contains($range, '::1'));
        self::assertFalse(IpRange::contains($range, '::ffff:10.1.2.3'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ipv6RangesEmbeddingIpv4(): iterable
    {
        yield 'ipv4 compatible' => ['::10.0.0.0/8'];
        yield 'ipv4 compatible at /95' => ['::10.0.0.0/95'];
        yield 'nat64' => ['64:ff9b::10.0.0.0/8'];
        yield 'nat64 at /95' => ['64:ff9b::10.0.0.0/95'];
    }

    #[DataProvider('ipv6RangesEmbeddingIpv4')]
    public function testInvalidEntryReasonRefusesIpv6RangesEmbeddingIpv4BelowSlash96(string $range): void
    {
        self::assertStringContainsString('embeds an IPv4 address', IpRange::invalidEntryReason($range) ?? '');
        self::assertFalse(IpRange::contains($range, '::1'));
    }

    #[DataProvider('validRanges')]
    public function testIsValidAcceptsWellFormedRanges(string $range): void
    {
        self::assertTrue(IpRange::isValid($range));
    }

    #[DataProvider('invalidRanges')]
    public function testIsValidRejectsMalformedRanges(string $range): void
    {
        self::assertFalse(IpRange::isValid($range));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function membership(): iterable
    {
        yield 'ipv4 inside /8' => ['10.0.0.0/8', '10.0.0.1', true];
        yield 'ipv4 outside /8' => ['10.0.0.0/8', '11.0.0.1', false];
        yield 'ipv4 zero prefix matches everything' => ['0.0.0.0/0', '203.0.113.9', true];
        yield 'ipv4 full prefix matches itself' => ['10.0.0.0/32', '10.0.0.0', true];
        yield 'ipv4 full prefix rejects neighbour' => ['10.0.0.0/32', '10.0.0.1', false];
        yield '/12 last address inside' => ['172.16.0.0/12', '172.31.255.254', true];
        yield '/12 first address outside' => ['172.16.0.0/12', '172.32.0.1', false];
        yield '/12 first address inside' => ['172.16.0.0/12', '172.16.0.1', true];
        yield '/20 last address inside' => ['10.0.16.0/20', '10.0.31.254', true];
        yield '/20 first address outside' => ['10.0.16.0/20', '10.0.32.1', false];
        yield '/20 address below range' => ['10.0.16.0/20', '10.0.15.255', false];
        yield 'ipv6 /12 last address inside' => ['2000::/12', '200f:ffff::1', true];
        yield 'ipv6 /12 first address outside' => ['2000::/12', '2010::1', false];
        yield 'ipv6 /20 last address inside' => ['2001::/20', '2001:fff:ffff::1', true];
        yield 'ipv6 /20 first address outside' => ['2001::/20', '2001:1000::1', false];
        yield 'ipv6 zero prefix matches everything' => ['::/0', '2001:db8::1', true];
        yield 'ipv6 full prefix matches itself' => ['::1/128', '::1', true];
        yield 'ipv6 full prefix rejects neighbour' => ['::1/128', '::2', false];
        yield 'bare ipv6 matches uppercase spelling' => ['2001:DB8::1', '2001:db8::1', true];
        yield 'bare ipv6 matches expanded spelling' => ['0:0:0:0:0:0:0:1', '::1', true];
        yield 'bare ipv6 rejects other address' => ['::1', '::2', false];
        yield 'bare ipv4 is an exact match' => ['10.0.0.1', '10.0.0.1', true];
        yield 'bare ipv4 rejects neighbour' => ['10.0.0.1', '10.0.0.2', false];
        yield 'ipv4 range rejects ipv6 peer' => ['10.0.0.0/8', '::1', false];
        yield 'ipv6 range rejects ipv4 peer' => ['::/0', '10.0.0.1', false];
        yield 'ipv4 mapped ipv6 is not ipv4' => ['10.0.0.1', '::ffff:10.0.0.1', false];
        yield 'nul in range' => ["10.0.0.0/8\0", '10.0.0.1', false];
        yield 'nul in address' => ["10.0.0.1\0", '10.0.0.1', false];
        yield 'nul in peer' => ['10.0.0.0/8', "10.0.0.1\0", false];
        yield 'empty peer' => ['10.0.0.0/8', '', false];
        yield 'malformed peer' => ['10.0.0.0/8', 'not-an-ip', false];
        yield 'leading zero peer' => ['10.0.0.0/8', '010.0.0.1', false];
        yield 'ipv6 zone peer' => ['fe80::/10', 'fe80::1%eth0', false];
        yield 'malformed prefix fails closed' => ['10.0.0.0/abc', '10.0.0.1', false];
        yield 'empty prefix fails closed' => ['10.0.0.0/', '10.0.0.1', false];
        yield 'letter O prefix fails closed' => ['10.0.0.0/O8', '10.0.0.1', false];
        yield 'overflowing prefix fails closed' => ['10.0.0.0/' . str_repeat('9', 309), '203.0.113.9', false];
        yield 'overflowing ipv6 prefix fails closed' => ['2001:db8::/' . str_repeat('9', 309), '2001:db8::1', false];
        yield 'empty range fails closed' => ['', '10.0.0.1', false];
        yield 'ipv4 mapped short range does not cover loopback' => ['::ffff:10.0.0.0/8', '::1', false];
        yield 'ipv4 mapped short range does not cover mapped peer' => ['::ffff:10.0.0.0/8', '::ffff:10.1.2.3', false];
        yield 'ipv4 mapped short range does not cover ipv4 peer' => ['::ffff:0.0.0.0/0', '10.0.0.1', false];
        yield 'ipv4 mapped range at /104 matches mapped peer' => ['::ffff:10.0.0.0/104', '::ffff:10.1.2.3', true];
        yield 'ipv4 mapped zero quad at /96 matches mapped peer' => ['::ffff:0.0.0.0/96', '::ffff:10.1.2.3', true];
        yield 'ipv4 mapped range at /96 with host bits is refused' => ['::ffff:10.0.0.0/96', '::ffff:10.1.2.3', false];
        yield 'ipv4 compatible range at /96 with host bits is refused' => ['::10.0.0.0/96', '::1', false];
        yield 'ipv4 mapped full prefix matches itself' => ['::ffff:10.0.0.1/128', '::ffff:10.0.0.1', true];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function shownEntries(): iterable
    {
        yield 'short entry is shown whole' => ['10.0.0.1', '10.0.0.1'];
        yield 'empty entry is shown as empty' => ['', ''];
        yield 'long entry is cut at 64 bytes' => [str_repeat('a', 70), str_repeat('a', 64) . '...(70 bytes)'];
        yield 'multibyte entry is cut on a character boundary' => [
            str_repeat("\u{e9}", 40),
            str_repeat("\u{e9}", 32) . '...(80 bytes)',
        ];
        yield 'line feed is escaped' => ["a\nb", 'a\\nb'];
        yield 'nul byte is escaped' => ["a\0b", 'a\\000b'];
        yield 'invalid UTF-8 is replaced' => ["ok\xffend", 'ok?end'];
    }

    #[DataProvider('shownEntries')]
    public function testShownEntryBoundsAndEscapesTheEntry(string $entry, string $expected): void
    {
        self::assertSame($expected, IpRange::shownEntry($entry));
    }

    public function testShownEntryOfAHugeValueStaysSmall(): void
    {
        $shown = IpRange::shownEntry(str_repeat('x', 5 * 1024 * 1024));

        self::assertSame(str_repeat('x', 64) . '...(5242880 bytes)', $shown);
    }

    public function testNulInputIsRefusedWhateverItsPosition(): void
    {
        self::assertFalse(IpRange::isValid("\0"));
        self::assertFalse(IpRange::isValid("\0" . '10.0.0.1'));
        self::assertFalse(IpRange::contains("\0" . '10.0.0.0/8', '10.0.0.1'));
        self::assertFalse(IpRange::contains('10.0.0.0/8', "\0" . '10.0.0.1'));
    }

    #[DataProvider('membership')]
    public function testContainsMatchesAddressAgainstRange(string $range, string $ip, bool $expected): void
    {
        self::assertSame($expected, IpRange::contains($range, $ip));
    }

    /**
     * @return iterable<string, array{string, string}> The entry, then the range it really covers.
     */
    public static function plainRangesWithHostBits(): iterable
    {
        yield 'ipv4 octet typo that widens a /8' => ['10.1.0.0/8', '10.0.0.0/8'];
        yield 'ipv4 digit typo that widens a /3' => ['10.0.0.5/3', '0.0.0.0/3'];
        yield 'ipv4 last bit after a /31' => ['192.168.1.1/31', '192.168.1.0/31'];
        yield 'ipv4 address with a zero prefix' => ['10.0.0.5/0', '0.0.0.0/0'];
        yield 'ipv6 address inside a /32' => ['2001:db8::1/32', '2001:db8::/32'];
        yield 'ipv6 block inside a /16' => ['2001:db8:1::/16', '2001::/16'];
        yield 'ipv6 address with a zero prefix' => ['::1/0', '::/0'];
        yield 'ipv6 last bit after a /127' => ['2001:db8::1/127', '2001:db8::/127'];
    }

    #[DataProvider('plainRangesWithHostBits')]
    public function testIsValidRefusesAPlainRangeWhoseAddressHasBitsAfterThePrefix(string $range, string $covered): void
    {
        self::assertFalse(IpRange::isValid($range));
        self::assertStringContainsString(sprintf('%s covers %s', $range, $covered), IpRange::invalidEntryReason($range) ?? '');
        self::assertTrue(IpRange::isValid($covered));
    }

    public function testTheReasonNamesTheSingleAddressAndTheCoveredRange(): void
    {
        self::assertSame(
            '10.0.0.5/3 covers 0.0.0.0/3; write 10.0.0.5/32 or 0.0.0.0/3',
            IpRange::invalidEntryReason('10.0.0.5/3'),
        );
    }

    public function testAPlainRangeWithoutHostBitsStaysValid(): void
    {
        self::assertNull(IpRange::invalidEntryReason('10.0.0.0/8'));
        self::assertNull(IpRange::invalidEntryReason('10.0.0.5/32'));
        self::assertNull(IpRange::invalidEntryReason('2001:db8::/32'));
    }
}
