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
}
