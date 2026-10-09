<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\KernelBuilder;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Router;
use Zephyrus\Security\AllowedHostsMiddleware;

/**
 * The URL a request routes on is assembled from the Host value, so a Host that
 * carries URL syntax must not be able to move the path or pass an allowlist.
 */
final class RequestHostAllowlistTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function hostileHostProvider(): array
    {
        return [
            'slash' => ['allowed.com/evil'],
            'question mark' => ['allowed.com?x'],
            'fragment' => ['allowed.com#x'],
            'userinfo' => ['a@allowed.com'],
            'tab' => ["allowed.com\tx"],
            'nul byte' => ["allowed.com\0x"],
        ];
    }

    /** @return array<string, array{string}> */
    public static function validHostProvider(): array
    {
        return [
            'with port' => ['allowed.com:8443'],
            'ipv6 with port' => ['[::1]:8080'],
        ];
    }

    private function kernel(): \Zephyrus\Core\HttpKernel
    {
        $router = (new Router())->get('/p', HostAllowlistController::class . '@page');

        return KernelBuilder::create()
            ->withRouter($router)
            ->withMiddleware(new AllowedHostsMiddleware(['allowed.com', '[::1]']))
            ->build();
    }

    /** @param array<string, string> $extra */
    private function request(array $extra): Request
    {
        return Request::fromGlobals(
            server: ['REQUEST_URI' => '/p', 'REQUEST_METHOD' => 'GET'] + $extra,
            get: [],
            post: [],
            cookie: [],
            files: [],
            rawBody: '',
        );
    }

    #[DataProvider('hostileHostProvider')]
    public function testHostWithURLSyntaxKeepsThePathAndFailsTheAllowlist(string $host): void
    {
        $request = $this->request(['HTTP_HOST' => $host]);

        self::assertSame('/p', $request->path());
        self::assertSame(400, $this->kernel()->handle($request)->status);
    }

    #[DataProvider('hostileHostProvider')]
    public function testForwardedHostWithURLSyntaxKeepsThePathAndFailsTheAllowlist(string $host): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_URI' => '/p',
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST' => 'allowed.com',
                'HTTP_X_FORWARDED_HOST' => $host,
                'REMOTE_ADDR' => '10.0.0.1',
            ],
            get: [],
            post: [],
            cookie: [],
            files: [],
            rawBody: '',
            trustedProxies: ['10.0.0.1'],
        );

        self::assertSame('/p', $request->path());
        self::assertSame(400, $this->kernel()->handle($request)->status);
    }

    #[DataProvider('validHostProvider')]
    public function testAWellFormedHostWithPortIsUnchanged(string $host): void
    {
        $request = $this->request(['HTTP_HOST' => $host]);

        self::assertSame('/p', $request->path());
        self::assertSame(200, $this->kernel()->handle($request)->status);
    }

    public function testAWellFormedHostWithPortKeepsItsHostAndPort(): void
    {
        $request = $this->request(['HTTP_HOST' => 'allowed.com:8443']);

        self::assertSame('allowed.com', $request->uri()->host());
        self::assertSame(8443, $request->uri()->port());
    }

    public function testAForwardedProtoOutsideHttpAndHttpsIsIgnored(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_URI' => '/p',
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST' => 'allowed.com',
                'HTTP_X_FORWARDED_PROTO' => 'javascript',
                'REMOTE_ADDR' => '10.0.0.1',
            ],
            get: [],
            post: [],
            cookie: [],
            files: [],
            rawBody: '',
            trustedProxies: ['10.0.0.1'],
        );

        self::assertSame('http', $request->uri()->scheme());
    }
}

// ===========================================================================
// Fixtures
// ===========================================================================

final class HostAllowlistController
{
    public function page(): Response
    {
        return Response::text('ok');
    }
}
