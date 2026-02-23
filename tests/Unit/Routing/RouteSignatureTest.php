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
}
