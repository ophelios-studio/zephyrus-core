<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Security\ContentSecurityPolicy;
use Zephyrus\Security\ContentSecurityPolicyMiddleware;
use Zephyrus\Security\SecureHeadersConfig;

final class ContentSecurityPolicyMiddlewareTest extends TestCase
{
    private function makeRequest(): Request
    {
        return new Request('GET', 'https://example.com/test');
    }

    private function process(ContentSecurityPolicyMiddleware $middleware): Response
    {
        $inner = Response::text('ok');

        return $middleware->process($this->makeRequest(), fn (Request $request): Response => $inner);
    }

    public function testEmitsContentSecurityPolicyHeaderFromPolicyObject(): void
    {
        $policy = ContentSecurityPolicy::create()->withDirective('default-src', ["'self'"]);
        $response = $this->process(new ContentSecurityPolicyMiddleware($policy));

        self::assertContains("content-security-policy: default-src 'self'", $response->toHeaderLines());
    }

    public function testEmitsReportOnlyHeaderWhenEnabled(): void
    {
        $policy = ContentSecurityPolicy::create()->withDirective('default-src', ["'none'"]);
        $response = $this->process(new ContentSecurityPolicyMiddleware($policy, reportOnly: true));

        self::assertContains("content-security-policy-report-only: default-src 'none'", $response->toHeaderLines());
        self::assertStringNotContainsString(
            'content-security-policy: default-src',
            implode("\n", $response->toHeaderLines()),
        );
    }

    public function testSkipsEmissionForEmptyPolicyString(): void
    {
        $response = $this->process(new ContentSecurityPolicyMiddleware('   '));

        self::assertStringNotContainsString('content-security-policy', implode("\n", $response->toHeaderLines()));
    }

    public function testEmitsHeaderFromRawPolicyString(): void
    {
        $response = $this->process(new ContentSecurityPolicyMiddleware("default-src 'self'; img-src https://cdn.example.com"));

        self::assertContains(
            "content-security-policy: default-src 'self'; img-src https://cdn.example.com",
            $response->toHeaderLines(),
        );
    }

    public function testRouteOwnedContentSecurityPolicyIsKept(): void
    {
        $policy = ContentSecurityPolicy::create()->withDirective('default-src', ["'self'"]);
        $inner = Response::text('ok')->withHeader('Content-Security-Policy', "default-src 'none'");
        $middleware = new ContentSecurityPolicyMiddleware($policy);

        $response = $middleware->process($this->makeRequest(), fn (Request $request): Response => $inner);

        self::assertSame("default-src 'none'", $response->headers['content-security-policy']);
    }

    public function testRouteOwnedReportOnlyHeaderIsKeptAndTheConfiguredOneIsNotAddedBesideIt(): void
    {
        $policy = ContentSecurityPolicy::create()->withDirective('default-src', ["'self'"]);
        $inner = Response::text('ok')->withHeader('Content-Security-Policy-Report-Only', "default-src 'none'");
        $middleware = new ContentSecurityPolicyMiddleware($policy, reportOnly: true);

        $response = $middleware->process($this->makeRequest(), fn (Request $request): Response => $inner);

        self::assertSame("default-src 'none'", $response->headers['content-security-policy-report-only']);
        self::assertArrayNotHasKey('content-security-policy', $response->headers);
    }

    /** @return iterable<string, array{string}> */
    public static function blankRouteValues(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => [' '];
        yield 'tab and newline' => ["\t\n"];
    }

    #[DataProvider('blankRouteValues')]
    public function testBlankRouteContentSecurityPolicyGetsTheConfiguredPolicy(string $blank): void
    {
        $policy = ContentSecurityPolicy::create()->withDirective('default-src', ["'self'"]);
        $inner = new Response('ok', 200, ['Content-Security-Policy' => $blank]);
        $middleware = new ContentSecurityPolicyMiddleware($policy);

        $response = $middleware->process($this->makeRequest(), fn (Request $request): Response => $inner);

        self::assertSame("default-src 'self'", $response->headers['content-security-policy']);
    }

    public function testRouteOwnedContentSecurityPolicyIsKeptOnTheNoncePath(): void
    {
        $policy = ContentSecurityPolicy::create()
            ->withDirective('default-src', ["'self'"])
            ->withDirective('script-src', ["'self'"]);
        $inner = Response::text('ok')->withHeader('Content-Security-Policy', "default-src 'none'");
        $middleware = new ContentSecurityPolicyMiddleware($policy, nonceDirectives: ['script-src']);

        $response = $middleware->process($this->makeRequest(), fn (Request $request): Response => $inner);

        self::assertSame("default-src 'none'", $response->headers['content-security-policy']);
    }

    #[DataProvider('controlCharacterPolicies')]
    public function testRawPolicyWithAControlCharacterIsRefusedAtConstruction(string $policy): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ContentSecurityPolicyMiddleware($policy);
    }

    public function testRawPolicyRefusalDoesNotEchoThePolicy(): void
    {
        try {
            new ContentSecurityPolicyMiddleware("default-src 'self'\x01 evil.example.com");
            self::fail('A control character must be refused.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('Content-Security-Policy', $exception->getMessage());
            self::assertStringNotContainsString('evil.example.com', $exception->getMessage());
        }
    }

    public static function controlCharacterPolicies(): iterable
    {
        yield 'SOH' => ["default-src 'self'\x01"];
        yield 'DEL' => ["default-src 'self'\x7F"];
        yield 'NUL in the middle' => ["default-src 'self'\0 img-src *"];
        yield 'carriage return' => ["default-src 'self'\r\nSet-Cookie: s=1"];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function policiesEndingWithAControlTheConfigKeeps(): iterable
    {
        yield 'vertical tab' => ["default-src 'self'\v", "default-src 'self'\\u000b"];
        yield 'NUL' => ["default-src 'self'\0", "default-src 'self'\\u0000"];
    }

    #[DataProvider('policiesEndingWithAControlTheConfigKeeps')]
    public function testARawPolicyIsRefusedLikeTheSameCspInTheConfig(string $policy, string $escaped): void
    {
        try {
            SecureHeadersConfig::fromArray(['csp' => $policy]);
            self::fail('The config must refuse the policy.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'security.headers' field 'csp' has invalid value \"" . $escaped
                . '": must not contain a control character.',
                $exception->getMessage(),
            );
        }

        try {
            new ContentSecurityPolicyMiddleware($policy);
            self::fail('The middleware must refuse the policy.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'The Content-Security-Policy value contains a control character; write it on one line or build it '
                . 'with ContentSecurityPolicy.',
                $exception->getMessage(),
            );
        }
    }

    public function testARawPolicyIsTrimmedLikeTheSameCspInTheConfig(): void
    {
        $policy = " \t default-src 'self'\r\n";
        $middleware = new ContentSecurityPolicyMiddleware($policy);

        $response = $middleware->process($this->makeRequest(), fn (Request $request): Response => Response::text('ok'));

        self::assertSame("default-src 'self'", SecureHeadersConfig::fromArray(['csp' => $policy])->csp);
        self::assertSame("default-src 'self'", $response->headers['content-security-policy']);
    }

    public function testRefusalNamesTheEnforcedHeaderAndTheWayOut(): void
    {
        try {
            new ContentSecurityPolicyMiddleware("default-src 'self'\x01");
            self::fail('A control character must be refused.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'The Content-Security-Policy value contains a control character; write it on one line or build it with ContentSecurityPolicy.',
                $exception->getMessage(),
            );
        }
    }

    public function testReportOnlyRefusalNamesTheReportOnlyHeader(): void
    {
        try {
            new ContentSecurityPolicyMiddleware("default-src 'self'\x01", reportOnly: true);
            self::fail('A control character must be refused.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'The Content-Security-Policy-Report-Only value contains a control character; write it on one line or build it with ContentSecurityPolicy.',
                $exception->getMessage(),
            );
        }
    }
}
