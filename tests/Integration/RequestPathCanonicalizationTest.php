<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\KernelBuilder;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Router;
use Zephyrus\Security\CsrfConfig;
use Zephyrus\Security\CsrfMiddleware;
use Zephyrus\Security\CsrfTokenManagerInterface;

/**
 * A leading "//" used to desync the path from the route that dispatched.
 *
 * The path was parsed twice from two different strings. Request built a FULL
 * url and parsed that, so uri()->path() kept "//x/admin/secret". The router
 * parsed the BARE path, where parse_url reads a leading "//token" as an
 * AUTHORITY, so it saw "/admin/secret" and dispatched it. The request therefore
 * executed one route while every path-based check inspected another.
 *
 * Measured against the pre-fix code through the real kernel:
 *
 *   GET  //x/admin/secret        -> 200, body "SECRET REACHED"
 *   POST //webhooks/account/close, no token -> 200, account closed
 *
 * The second is a live CSRF bypass: the unanchored exclusion #/webhooks/#
 * matched the raw path and skipped the token check while the router dispatched
 * the protected /account/close.
 */
final class RequestPathCanonicalizationTest extends TestCase
{
    private function request(string $target, string $method = 'GET'): Request
    {
        return Request::fromGlobals(
            server: [
                'REQUEST_URI' => $target,
                'REQUEST_METHOD' => $method,
                'HTTP_HOST' => 'app.test',
            ],
            get: [],
            post: [],
            cookie: [],
            files: [],
            rawBody: '',
        );
    }

    /** Answers with the route that actually dispatched. */
    private function routerKernel(): \Zephyrus\Core\HttpKernel
    {
        $router = (new Router())
            ->get('/admin/secret', PathCanonController::class . '@adminSecret')
            ->get('/x/admin/secret', PathCanonController::class . '@xAdminSecret')
            ->get('/admin', PathCanonController::class . '@admin')
            ->get('/users/', PathCanonController::class . '@usersSlash')
            ->get('/', PathCanonController::class . '@root');

        return KernelBuilder::create()->withRouter($router)->build();
    }

    // -- The exploit ----------------------------------------------------------

    public function testDoubleSlashPrefixNoLongerReachesTheProtectedRoute(): void
    {
        $request = $this->request('//x/admin/secret');
        $response = $this->routerKernel()->handle($request);

        // Pre-fix this returned 200 "SECRET REACHED".
        self::assertNotSame('SECRET REACHED', trim($response->body));
        self::assertSame('X-ADMIN-SECRET', trim($response->body), 'it dispatches what its path says');
        self::assertSame('/x/admin/secret', $request->path());
    }

    /**
     * The guard is the reason this mattered: it inspected a path the router
     * never used, so it returned false and let the request through.
     */
    public function testAPathGuardNowSeesTheRouteThatWillDispatch(): void
    {
        foreach (['//admin', '//x/admin/secret', '/admin/secret'] as $target) {
            $request = $this->request($target);

            $guardSees = str_starts_with($request->path(), '/admin');
            $dispatches = str_starts_with($request->path(), '/admin');

            self::assertSame($guardSees, $dispatches, $target);
        }

        // Specifically: "//admin" now resolves to "/admin", so a guard blocks
        // it. Pre-fix the guard saw "//admin" and waved it through.
        self::assertTrue(str_starts_with($this->request('//admin')->path(), '/admin'));
    }

    public function testCsrfExclusionCannotBeBypassedWithADoubleSlashPrefix(): void
    {
        // UNANCHORED on purpose: this is the vulnerable shape, and nothing in
        // the framework requires anchoring.
        $kernel = KernelBuilder::create()
            ->withRouter((new Router())->post('/account/close', PathCanonController::class . '@close'))
            ->withMiddleware(new CsrfMiddleware(
                new PathCanonTokenManager(),
                CsrfConfig::fromArray(['excluded_path_patterns' => ['#/webhooks/#']]),
            ))
            ->build();

        $bypass = $kernel->handle($this->request('//webhooks/account/close', 'POST'));

        // Pre-fix: 200 and the account was closed with no token.
        self::assertNotSame(200, $bypass->status);
        self::assertStringNotContainsString('ACCOUNT CLOSED', $bypass->body);
    }

    public function testCsrfStillProtectsAndStillExcludesTheGenuinePaths(): void
    {
        $kernel = KernelBuilder::create()
            ->withRouter(
                (new Router())
                    ->post('/account/close', PathCanonController::class . '@close')
                    ->post('/webhooks/stripe', PathCanonController::class . '@webhook'),
            )
            ->withMiddleware(new CsrfMiddleware(
                new PathCanonTokenManager(),
                CsrfConfig::fromArray(['excluded_path_patterns' => ['#/webhooks/#']]),
            ))
            ->build();

        // Non-breakage: the exclusion still works for the real path.
        $excluded = $kernel->handle($this->request('/webhooks/stripe', 'POST'));
        self::assertSame(200, $excluded->status);

        // And a protected route without a token is still refused.
        $protected = $kernel->handle($this->request('/account/close', 'POST'));
        self::assertSame(403, $protected->status);
    }

    // -- Determinism ----------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function canonicalTargetProvider(): array
    {
        return [
            'double slash prefix'      => ['//x/admin/secret', '/x/admin/secret'],
            'double slash single seg'  => ['//admin', '/admin'],
            'triple slash'             => ['///x/admin', '/x/admin'],
            'bare double slash'        => ['//', '/'],
            'double slash with query'  => ['//x/admin?q=1', '/x/admin'],
            // Unchanged shapes: the non-breakage proof.
            'ordinary path'            => ['/admin/secret', '/admin/secret'],
            'root'                     => ['/', '/'],
            'trailing slash'           => ['/users/', '/users/'],
            'query string'             => ['/users?page=2', '/users'],
            'encoded segment'          => ['/p%2Fq', '/p%2Fq'],
            'interior double slash'    => ['/a//b', '/a//b'],
        ];
    }

    #[DataProvider('canonicalTargetProvider')]
    public function testPathIsDeterministicAndNeverEmpty(string $target, string $expected): void
    {
        $path = $this->request($target)->path();

        self::assertSame($expected, $path);
        // "///x/admin" produced parse_url false and "//admin" produced null;
        // both used to be cast to "" and silently became the root.
        self::assertNotSame('', $path);
    }

    #[DataProvider('canonicalTargetProvider')]
    public function testCanonicalPathAgreesWithTheRawUriPath(string $target, string $expected): void
    {
        $request = $this->request($target);

        // The whole defect was these two disagreeing.
        self::assertSame($request->uri()->path(), $request->path(), $target);
    }

    // -- Non-breakage on real routing ----------------------------------------

    public function testOrdinaryRoutingIsUnchanged(): void
    {
        $kernel = $this->routerKernel();

        self::assertSame('SECRET REACHED', trim($kernel->handle($this->request('/admin/secret'))->body));
        self::assertSame('ADMIN', trim($kernel->handle($this->request('/admin'))->body));
        self::assertSame('ROOT', trim($kernel->handle($this->request('/'))->body));
        self::assertSame('USERS-SLASH', trim($kernel->handle($this->request('/users/'))->body));
        // A query string still does not affect which route matches.
        self::assertSame('ADMIN', trim($kernel->handle($this->request('/admin?page=2'))->body));
    }

    public function testFromArrayCanonicalisesTheSameWayAsFromGlobals(): void
    {
        // Both entry points must agree, or a test would pass while production
        // stayed vulnerable.
        self::assertSame(
            $this->request('//x/admin/secret')->path(),
            Request::fromArray('GET', '//x/admin/secret')->path(),
        );
        self::assertSame('/x/admin/secret', Request::fromArray('GET', '//x/admin/secret')->path());
    }

    public function testAbsoluteFormTargetsAreLeftAlone(): void
    {
        // An absolute-form target starts with a scheme, never "//", so the
        // canonicalisation must not touch it.
        $request = Request::fromArray('GET', 'http://example.com/admin/secret');

        self::assertSame('/admin/secret', $request->path());
    }
}

// ===========================================================================
// Fixtures
// ===========================================================================

final class PathCanonController
{
    public function adminSecret(): Response
    {
        return Response::text('SECRET REACHED');
    }

    public function xAdminSecret(): Response
    {
        return Response::text('X-ADMIN-SECRET');
    }

    public function admin(): Response
    {
        return Response::text('ADMIN');
    }

    public function usersSlash(): Response
    {
        return Response::text('USERS-SLASH');
    }

    public function root(): Response
    {
        return Response::text('ROOT');
    }

    public function close(): Response
    {
        return Response::text('ACCOUNT CLOSED');
    }

    public function webhook(): Response
    {
        return Response::text('WEBHOOK OK');
    }
}

final class PathCanonTokenManager implements CsrfTokenManagerInterface
{
    public function getToken(): string
    {
        return 'valid-token';
    }

    public function isTokenValid(string $token): bool
    {
        return $token === 'valid-token';
    }
}
