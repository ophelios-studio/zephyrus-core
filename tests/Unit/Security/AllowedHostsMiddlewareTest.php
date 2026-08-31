<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Security\AllowedHostsMiddleware;

final class AllowedHostsMiddlewareTest extends TestCase
{
    public function testEmptyAllowlistPassesThrough(): void
    {
        $mw = new AllowedHostsMiddleware([]);
        $request = new Request('GET', 'https://example.com/ping');

        $response = $mw->process($request, static fn (Request $r): Response => Response::text('ok'));

        self::assertSame(200, $response->status);
        self::assertSame('ok', $response->body);
    }

    public function testExactHostMatchPassesIncludingPortInHeader(): void
    {
        $mw = new AllowedHostsMiddleware(['example.com']);
        $request = new Request('GET', 'https://example.com/ping', headers: [
            'host' => 'example.com:443',
        ]);

        $response = $mw->process($request, static fn (Request $r): Response => Response::text('ok'));

        self::assertSame(200, $response->status);
    }

    public function testHostCanBeResolvedFromUriWhenHostHeaderMissing(): void
    {
        $mw = new AllowedHostsMiddleware(['api.example.com']);
        $request = new Request('GET', 'https://api.example.com/v1');

        $response = $mw->process($request, static fn (Request $r): Response => Response::text('ok'));

        self::assertSame(200, $response->status);
    }

    public function testHostCanBeResolvedFromIpv6UriWhenHostHeaderMissing(): void
    {
        $mw = new AllowedHostsMiddleware(['::1']);
        $request = new Request('GET', 'http://[::1]/v1');

        $response = $mw->process($request, static fn (Request $r): Response => Response::text('ok'));

        self::assertSame(200, $response->status);
    }

    public function testIpv6UriWithPortIsNormalizedAndAllowed(): void
    {
        $mw = new AllowedHostsMiddleware(['2001:db8::1']);
        $request = new Request('GET', 'https://[2001:db8::1]:443/secure');

        $response = $mw->process($request, static fn (Request $r): Response => Response::text('ok'));

        self::assertSame(200, $response->status);
    }

    /**
     * THE TRAP THIS PINS. The middleware used to read the raw Host header first
     * and fall back to the URI, while every downstream consumer (links,
     * redirects, cookie domains, baseUrl()) reads uri()->host(), which a trusted
     * X-Forwarded-Host overrides. The two could therefore describe different
     * hosts, and the allowlist checked the one nothing else used.
     *
     * This case is that divergence in its smallest form: an allowed Host header
     * over a URI pointing somewhere else. It used to PASS, which is the bug. The
     * allowlist now judges uri()->host(), so it refuses.
     *
     * The previous version of this case asserted the opposite (an allowed IPv6
     * Host header over an example.com URI returning 200) and was the only test
     * whose expectation had to change.
     */
    public function testAnAllowedHostHeaderCannotAdmitARequestWhoseUriPointsElsewhere(): void
    {
        $called = false;
        $mw = new AllowedHostsMiddleware(['app.agreely.ca']);
        $request = new Request('GET', 'https://evil.attacker.test/path', headers: [
            'host' => 'app.agreely.ca',
        ]);

        $response = $mw->process($request, static function (Request $r) use (&$called): Response {
            $called = true;

            return Response::text('unreachable');
        });

        self::assertFalse($called);
        self::assertSame(400, $response->status);
        self::assertSame('evil.attacker.test', $request->uri()->host());
    }

    /**
     * The mirror image: the URI is the allowed host, so the request is served no
     * matter what the raw Host header claims. A trusted proxy rewriting Host and
     * forwarding the public name through X-Forwarded-Host is a legitimate and
     * common topology, and Request::fromGlobals already gates that header on the
     * trusted-proxy allowlist. Refusing on disagreement would break exactly the
     * deployments allowed_hosts exists to protect.
     */
    public function testAnAllowedUriIsServedEvenWhenTheRawHostHeaderDisagrees(): void
    {
        $mw = new AllowedHostsMiddleware(['app.agreely.ca']);
        $request = new Request('GET', 'https://app.agreely.ca/dashboard', headers: [
            'host' => 'internal-backend.flycast',
        ]);

        $response = $mw->process($request, static fn (Request $r): Response => Response::text('ok'));

        self::assertSame(200, $response->status);
    }

    public function testRejectsUnknownHostAndSkipsInnerHandler(): void
    {
        $called = false;
        $mw = new AllowedHostsMiddleware(['example.com']);
        $request = new Request('GET', 'https://evil.example.net/path', headers: [
            'host' => 'evil.example.net',
        ]);

        $response = $mw->process($request, static function (Request $r) use (&$called): Response {
            $called = true;

            return Response::text('unreachable');
        });

        self::assertFalse($called);
        self::assertSame(400, $response->status);
        self::assertStringContainsString('Invalid Host header.', $response->body);
    }

    public function testWildcardAllowsSubdomainsButNotApexDomain(): void
    {
        $mw = new AllowedHostsMiddleware(['*.example.com']);

        $subdomainRequest = new Request('GET', 'https://api.example.com/items');
        $subdomainResponse = $mw->process($subdomainRequest, static fn (Request $r): Response => Response::text('ok'));
        self::assertSame(200, $subdomainResponse->status);

        $apexRequest = new Request('GET', 'https://example.com/items');
        $apexResponse = $mw->process($apexRequest, static fn (Request $r): Response => Response::text('nope'));
        self::assertSame(400, $apexResponse->status);
    }

    public function testHostNormalizationHandlesCaseAndTrailingDot(): void
    {
        $mw = new AllowedHostsMiddleware(['Example.COM']);
        $request = new Request('GET', 'https://example.com/home', headers: [
            'host' => 'EXAMPLE.com.',
        ]);

        $response = $mw->process($request, static fn (Request $r): Response => Response::text('ok'));

        self::assertSame(200, $response->status);
    }
}
