<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Zephyrus\Security\ContentSecurityPolicy;

final class ContentSecurityPolicyTest extends TestCase
{
    public function testEmptyPolicySerializesToEmptyString(): void
    {
        $policy = ContentSecurityPolicy::create();

        self::assertTrue($policy->isEmpty());
        self::assertSame('', $policy->toHeaderValue());
    }

    public function testWithDirectiveSerializesSources(): void
    {
        $policy = ContentSecurityPolicy::create()
            ->withDirective('default-src', ["'self'", 'https://cdn.example.com']);

        self::assertSame(
            "default-src 'self' https://cdn.example.com",
            $policy->toHeaderValue(),
        );
    }

    public function testWithDirectiveAcceptsWhitespaceSeparatedStringValues(): void
    {
        $policy = ContentSecurityPolicy::create()
            ->withDirective('script-src', "'self' https://cdn.example.com");

        self::assertSame(
            "script-src 'self' https://cdn.example.com",
            $policy->toHeaderValue(),
        );
    }

    public function testFlagDirectiveSerializesWithoutValues(): void
    {
        $policy = ContentSecurityPolicy::create()
            ->withDirective('upgrade-insecure-requests');

        self::assertSame('upgrade-insecure-requests', $policy->toHeaderValue());
    }

    public function testAppendValueAddsAndDeduplicatesSources(): void
    {
        $policy = ContentSecurityPolicy::create()
            ->withDirective('img-src', ["'self'"])
            ->appendValue('img-src', 'data:')
            ->appendValue('img-src', 'data:');

        self::assertSame("img-src 'self' data:", $policy->toHeaderValue());
    }

    public function testWithoutDirectiveRemovesConfiguredDirective(): void
    {
        $policy = ContentSecurityPolicy::create()
            ->withDirective('default-src', ["'self'"])
            ->withDirective('object-src', ["'none'"])
            ->withoutDirective('object-src');

        self::assertFalse($policy->hasDirective('object-src'));
        self::assertSame("default-src 'self'", $policy->toHeaderValue());
    }

    public function testAppendNonceFormatsSourceExpression(): void
    {
        $policy = ContentSecurityPolicy::create()
            ->appendNonce('script-src', 'abc123+/==');

        self::assertSame("script-src 'nonce-abc123+/=='", $policy->toHeaderValue());
    }

    public function testAppendHashFormatsSourceExpression(): void
    {
        $policy = ContentSecurityPolicy::create()
            ->appendHash('style-src', 'sha384', 'deadbeef+/==');

        self::assertSame("style-src 'sha384-deadbeef+/=='", $policy->toHeaderValue());
    }

    public function testAppendHashRejectsUnsupportedAlgorithm(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ContentSecurityPolicy::create()->appendHash('script-src', 'md5', 'deadbeef+/==');
    }

    public function testAppendNonceRejectsInvalidBase64Token(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ContentSecurityPolicy::create()->appendNonce('script-src', 'bad token');
    }

    public function testMultipleDirectivesSerializeInInsertionOrder(): void
    {
        $policy = ContentSecurityPolicy::create()
            ->withDirective('default-src', ["'self'"])
            ->withDirective('script-src', ["'self'", "'unsafe-inline'"])
            ->withDirective('upgrade-insecure-requests');

        self::assertSame(
            "default-src 'self'; script-src 'self' 'unsafe-inline'; upgrade-insecure-requests",
            (string) $policy,
        );
    }

    public function testDirectiveNameIsNormalizedToLowercase(): void
    {
        $policy = ContentSecurityPolicy::create()
            ->withDirective('DEFAULT-SRC', ["'self'"]);

        self::assertTrue($policy->hasDirective('default-src'));
        self::assertSame(
            ['default-src' => ["'self'"]],
            $policy->toArray(),
        );
    }

    public function testInvalidDirectiveNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ContentSecurityPolicy::create()->withDirective('default src', ["'self'"]);
    }

    public function testInvalidDirectiveValueThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ContentSecurityPolicy::create()->withDirective('default-src', ["'self'; report-uri /csp"]);
    }

    /**
     * A single value is a single source expression. A space inside one lets a
     * caller smuggle a second source expression into a directive it was only
     * meant to add a host to, which is how "add this tenant's CDN" becomes
     * "and allow 'unsafe-inline'".
     */
    public function testAppendValueRejectsASpaceSmugglingASecondSourceExpression(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ContentSecurityPolicy::create()
            ->withDirective('script-src', ["'self'"])
            ->appendValue('script-src', "https://cdn.tenant.example 'unsafe-inline'");
    }

    public function testWithDirectiveRejectsASpaceInsideAnArrayValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ContentSecurityPolicy::create()
            ->withDirective('script-src', ["'self'", "https://cdn.tenant.example 'unsafe-inline'"]);
    }

    public function testAppendNonceStillProducesASingleSourceExpression(): void
    {
        $policy = ContentSecurityPolicy::create()
            ->withDirective('script-src', ["'self'"])
            ->appendNonce('script-src', 'abc123==');

        self::assertSame("script-src 'self' 'nonce-abc123=='", $policy->toHeaderValue());
    }

    /**
     * The string path split on whitespace BEFORE the separator guard ran, so
     * the guard never saw a CRLF: it had already been consumed as a delimiter.
     * The same input therefore threw as an array and was accepted as a string.
     */
    public function testCrlfIsRejectedOnTheStringPathJustAsItIsOnTheArrayPath(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ContentSecurityPolicy::create()->withDirective('script-src', "'self'\r\nX-Injected: 1");
    }

    public function testSemicolonIsRejectedOnTheStringPath(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ContentSecurityPolicy::create()->withDirective('script-src', "'self' ; object-src 'none'");
    }
}
