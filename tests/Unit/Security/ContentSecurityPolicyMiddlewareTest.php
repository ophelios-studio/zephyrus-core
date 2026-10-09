<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Security\ContentSecurityPolicy;
use Zephyrus\Security\ContentSecurityPolicyMiddleware;

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
        $inner = Response::text('ok')->withHeader('Content-Security-Policy', $blank);
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
}
