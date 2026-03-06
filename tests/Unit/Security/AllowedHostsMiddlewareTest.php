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
