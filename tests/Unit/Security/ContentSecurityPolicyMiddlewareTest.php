<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

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

        self::assertContains("Content-Security-Policy: default-src 'self'", $response->toHeaderLines());
    }

    public function testEmitsReportOnlyHeaderWhenEnabled(): void
    {
        $policy = ContentSecurityPolicy::create()->withDirective('default-src', ["'none'"]);
        $response = $this->process(new ContentSecurityPolicyMiddleware($policy, reportOnly: true));

        self::assertContains("Content-Security-Policy-Report-Only: default-src 'none'", $response->toHeaderLines());
        self::assertStringNotContainsString(
            'Content-Security-Policy: default-src',
            implode("\n", $response->toHeaderLines()),
        );
    }

    public function testSkipsEmissionForEmptyPolicyString(): void
    {
        $response = $this->process(new ContentSecurityPolicyMiddleware('   '));

        self::assertStringNotContainsString('Content-Security-Policy', implode("\n", $response->toHeaderLines()));
    }

    public function testEmitsHeaderFromRawPolicyString(): void
    {
        $response = $this->process(new ContentSecurityPolicyMiddleware("default-src 'self'; img-src https://cdn.example.com"));

        self::assertContains(
            "Content-Security-Policy: default-src 'self'; img-src https://cdn.example.com",
            $response->toHeaderLines(),
        );
    }
}
