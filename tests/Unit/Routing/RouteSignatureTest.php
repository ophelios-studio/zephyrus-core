<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Zephyrus\Routing\Exception\RouteSignatureException;
use Zephyrus\Routing\RouteSignature;

final class RouteSignatureTest extends TestCase
{
    public function testSignAndVerifyRoundTrip(): void
    {
        $signer = new RouteSignature('top-secret');

        $signed = $signer->sign('https://example.com/users/42?expand=roles');

        self::assertStringContainsString('_sig=', $signed);
        self::assertTrue($signer->verify($signed));
    }

    public function testVerifyFailsWhenPayloadIsTampered(): void
    {
        $signer = new RouteSignature('top-secret');

        $signed = $signer->sign('https://example.com/users/42?expand=roles');
        $tampered = str_replace('roles', 'admin', $signed);

        self::assertFalse($signer->verify($tampered));
    }

    public function testAssertValidThrowsOnInvalidSignature(): void
    {
        $signer = new RouteSignature('top-secret');

        $this->expectException(RouteSignatureException::class);
        $this->expectExceptionMessage('Invalid route signature');

        $signer->assertValid('https://example.com/users/42');
    }

    public function testSignCanonicalizesQueryOrderingBeforeSigning(): void
    {
        $signer = new RouteSignature('top-secret');

        $signed = $signer->sign('https://example.com/users/42?b=2&a=1');

        self::assertStringStartsWith('https://example.com/users/42?a=1&b=2&_sig=', $signed);
        self::assertTrue($signer->verify($signed));
    }

    public function testSignReplacesExistingSignatureParameter(): void
    {
        $signer = new RouteSignature('top-secret');

        $signed = $signer->sign('https://example.com/users/42?a=1&_sig=old-signature');

        self::assertSame(1, substr_count($signed, '_sig='));
        self::assertTrue($signer->verify($signed));
    }

    public function testSignPreservesFragmentAfterSignature(): void
    {
        $signer = new RouteSignature('top-secret');

        $signed = $signer->sign('https://example.com/users/42?expand=roles#section-2');

        self::assertStringContainsString('#section-2', $signed);
        self::assertTrue($signer->verify($signed));
    }

    public function testSignAndVerifySupportsRelativeUrls(): void
    {
        $signer = new RouteSignature('top-secret');

        $signed = $signer->sign('/users/42?expand=roles');

        self::assertStringStartsWith('/users/42?expand=roles&_sig=', $signed);
        self::assertTrue($signer->verify($signed));
    }

    public function testSignAndVerifyWithExplicitPort(): void
    {
        $signer = new RouteSignature('top-secret');

        $signed = $signer->sign('https://example.com:8080/users/42?expand=roles');

        self::assertStringStartsWith('https://example.com:8080/users/42?', $signed);
        self::assertTrue($signer->verify($signed));
    }

    /**
     * A pure query-string URL (no path, no scheme, no host) exercises the
     * elseif branch in buildBaseUrl (L90-91).
     */
    public function testSignAndVerifyPureQueryStringUrl(): void
    {
        $signer = new RouteSignature('top-secret');

        $signed = $signer->sign('?foo=bar');

        self::assertStringContainsString('_sig=', $signed);
        self::assertTrue($signer->verify($signed));
    }

    public function testSignTemporaryAddsExpiryAndVerifiesBeforeExpiry(): void
    {
        $signer = new RouteSignature('top-secret');

        $signed = $signer->signTemporary('https://example.com/downloads/42', ttlSeconds: 60, now: 1_700_000_000);

        self::assertStringContainsString('_exp=1700000060', $signed);
        self::assertTrue($signer->verifyAt($signed, now: 1_700_000_030));
    }

    public function testVerifyAtFailsWhenTemporarySignatureExpires(): void
    {
        $signer = new RouteSignature('top-secret');

        $signed = $signer->signTemporary('https://example.com/downloads/42', ttlSeconds: 10, now: 1_700_000_000);

        self::assertFalse($signer->verifyAt($signed, now: 1_700_000_011));
    }

    public function testVerifyAtFailsWhenExpiryFieldIsMalformed(): void
    {
        $signer = new RouteSignature('top-secret');

        $signed = $signer->signTemporary('https://example.com/downloads/42', ttlSeconds: 60, now: 1_700_000_000);
        $tampered = str_replace('_exp=1700000060', '_exp=not-a-timestamp', $signed);

        self::assertFalse($signer->verifyAt($tampered, now: 1_700_000_001));
    }

    public function testSignTemporaryThrowsWhenTtlIsNotPositive(): void
    {
        $signer = new RouteSignature('top-secret');

        $this->expectException(RouteSignatureException::class);
        $this->expectExceptionMessage('Temporary signature TTL must be greater than zero seconds');

        $signer->signTemporary('https://example.com/downloads/42', ttlSeconds: 0, now: 1_700_000_000);
    }

    public function testAssertValidAtThrowsExpiredSignatureMessage(): void
    {
        $signer = new RouteSignature('top-secret');
        $signed = $signer->signTemporary('https://example.com/downloads/42', ttlSeconds: 10, now: 1_700_000_000);

        $this->expectException(RouteSignatureException::class);
        $this->expectExceptionMessage('Route signature has expired');

        $signer->assertValidAt($signed, now: 1_700_000_011);
    }

    public function testAssertValidAtThrowsMalformedExpiryMessage(): void
    {
        $signer = new RouteSignature('top-secret');
        $signed = $signer->signTemporary('https://example.com/downloads/42', ttlSeconds: 60, now: 1_700_000_000);
        $tampered = str_replace('_exp=1700000060', '_exp=bad', $signed);

        $this->expectException(RouteSignatureException::class);
        $this->expectExceptionMessage('Route signature expiry value is malformed');

        $signer->assertValidAt($tampered, now: 1_700_000_001);
    }

    public function testVerifyAtAllowsClockSkewGraceWindow(): void
    {
        $signer = new RouteSignature('top-secret');
        $signed = $signer->signTemporary('https://example.com/downloads/42', ttlSeconds: 10, now: 1_700_000_000);

        self::assertTrue($signer->verifyAt($signed, now: 1_700_000_015, clockSkewSeconds: 5));
    }

    public function testVerifyAtFailsAfterClockSkewGraceWindow(): void
    {
        $signer = new RouteSignature('top-secret');
        $signed = $signer->signTemporary('https://example.com/downloads/42', ttlSeconds: 10, now: 1_700_000_000);

        self::assertFalse($signer->verifyAt($signed, now: 1_700_000_016, clockSkewSeconds: 5));
    }

    public function testVerifyAtTreatsNegativeClockSkewAsZero(): void
    {
        $signer = new RouteSignature('top-secret');
        $signed = $signer->signTemporary('https://example.com/downloads/42', ttlSeconds: 10, now: 1_700_000_000);

        self::assertFalse($signer->verifyAt($signed, now: 1_700_000_011, clockSkewSeconds: -5));
    }

    public function testSignTemporaryUntilBuildsVerifiableSignatureAtAbsoluteExpiry(): void
    {
        $signer = new RouteSignature('top-secret');

        $signed = $signer->signTemporaryUntil(
            'https://example.com/downloads/42?disposition=inline',
            expiresAt: 1_700_000_120,
            now: 1_700_000_000,
        );

        self::assertStringContainsString('_exp=1700000120', $signed);
        self::assertTrue($signer->verifyAt($signed, now: 1_700_000_119));
        self::assertFalse($signer->verifyAt($signed, now: 1_700_000_121));
    }

    public function testSignTemporaryUntilThrowsWhenExpiryIsNotInFuture(): void
    {
        $signer = new RouteSignature('top-secret');

        $this->expectException(RouteSignatureException::class);
        $this->expectExceptionMessage('Temporary signature expiry instant must be in the future');

        $signer->signTemporaryUntil(
            'https://example.com/downloads/42',
            expiresAt: 1_700_000_000,
            now: 1_700_000_000,
        );
    }

    /**
     * hash_hmac('sha256', $payload, '') is a value anyone can compute, so an
     * empty secret turns every signed URL into a public one while every
     * verify() still returns true. Same fail-open class as an empty BLAKE2b key.
     */
    public function testConstructorRejectsAnEmptySecret(): void
    {
        $this->expectException(RouteSignatureException::class);
        $this->expectExceptionMessage('signing secret');

        new RouteSignature('');
    }

    public function testConstructorRejectsAWhitespaceOnlySecret(): void
    {
        $this->expectException(RouteSignatureException::class);
        $this->expectExceptionMessage('signing secret');

        new RouteSignature("  \t ");
    }
}
