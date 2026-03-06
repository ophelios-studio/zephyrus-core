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
}
