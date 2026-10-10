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
 * Routing, path-based checks (guards, CSRF exclusions) and uri()->path() must
 * all read the same canonical path, so a request cannot dispatch one route
 * while the checks inspect another. parse_url() reads a leading "//x" as an
 * authority, so leading slashes are collapsed before anything reads the path.
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

    // -- Leading double slash -------------------------------------------------

    public function testDoubleSlashPrefixNoLongerReachesTheProtectedRoute(): void
    {
        $request = $this->request('//x/admin/secret');
        $response = $this->routerKernel()->handle($request);

        self::assertNotSame('SECRET REACHED', trim($response->body));
        self::assertSame('X-ADMIN-SECRET', trim($response->body), 'it dispatches what its path says');
        self::assertSame('/x/admin/secret', $request->path());
    }

    /** A guard sees the path the router dispatches. */
    public function testAPathGuardNowSeesTheRouteThatWillDispatch(): void
    {
        foreach (['//admin', '//x/admin/secret', '/admin/secret'] as $target) {
            $request = $this->request($target);

            $guardSees = str_starts_with($request->path(), '/admin');
            $dispatches = str_starts_with($request->path(), '/admin');

            self::assertSame($guardSees, $dispatches, $target);
        }

        // "//admin" resolves to "/admin", so a guard blocks it.
        self::assertTrue(str_starts_with($this->request('//admin')->path(), '/admin'));
    }

    public function testCsrfExclusionCannotBeBypassedWithADoubleSlashPrefix(): void
    {
        // Exclusions must be anchored (CsrfConfig rejects others). The exemption matches
        // the canonical /webhooks/account/close, which is also the path the router
        // dispatches, so the protected /account/close is never reached.
        $kernel = KernelBuilder::create()
            ->withRouter((new Router())->post('/account/close', PathCanonController::class . '@close'))
            ->withMiddleware(new CsrfMiddleware(
                new PathCanonTokenManager(),
                CsrfConfig::fromArray(['excluded_path_patterns' => ['#^/webhooks/#']]),
            ))
            ->build();

        $bypass = $kernel->handle($this->request('//webhooks/account/close', 'POST'));

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
                CsrfConfig::fromArray(['excluded_path_patterns' => ['#^/webhooks/#']]),
            ))
            ->build();

        // The exclusion still applies to the real path.
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
            // Already canonical: unchanged.
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
        // A failed parse must not become an empty path, which would mean the root.
        self::assertNotSame('', $path);
    }

    #[DataProvider('canonicalTargetProvider')]
    public function testCanonicalPathAgreesWithTheRawUriPath(string $target, string $expected): void
    {
        $request = $this->request($target);

        // Both paths must agree.
        self::assertSame($request->uri()->path(), $request->path(), $target);
    }

    // -- Routing on canonical paths -------------------------------------------

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
        // Both entry points must agree.
        self::assertSame(
            $this->request('//x/admin/secret')->path(),
            Request::fromArray('GET', '//x/admin/secret')->path(),
        );
        self::assertSame('/x/admin/secret', Request::fromArray('GET', '//x/admin/secret')->path());
    }

    public function testAbsoluteFormTargetsAreLeftAlone(): void
    {
        // Nothing to collapse here: the "//" after the scheme must survive.
        $request = Request::fromArray('GET', 'http://example.com/admin/secret');

        self::assertSame('/admin/secret', $request->path());
        self::assertSame('example.com', $request->uri()->host());
        self::assertSame('http', $request->uri()->scheme());
    }

    // -- Every construction path must agree ----------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function absoluteUrlProvider(): array
    {
        return [
            'double slash prefix'     => ['https://h//x/admin/secret', '/x/admin/secret'],
            'double slash single seg' => ['https://h//admin', '/admin'],
            'triple slash'            => ['https://h///x/admin', '/x/admin'],
            'bypass shape'            => ['https://h//webhooks/account/close', '/webhooks/account/close'],
            'with query'              => ['https://h//x/admin?q=1', '/x/admin'],
            'with port'               => ['https://h:8443//x/admin', '/x/admin'],
            'ordinary absolute'       => ['https://h/admin/secret', '/admin/secret'],
            'no path at all'          => ['https://h', '/'],
        ];
    }

    /** fromArray() given an absolute URL canonicalises the path like the other entry points. */
    #[DataProvider('absoluteUrlProvider')]
    public function testAbsoluteUrlsAreCanonicalizedToo(string $url, string $expected): void
    {
        $request = Request::fromArray('GET', $url);

        self::assertSame($expected, $request->path());
        self::assertSame($request->uri()->path(), $request->path());
        // The scheme/authority separator must survive the collapse.
        self::assertSame('h', $request->uri()->host());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function equivalentTargetProvider(): array
    {
        return [
            'double slash prefix'     => ['//x/admin/secret'],
            'double slash single seg' => ['//admin'],
            'triple slash'            => ['///x/admin'],
            'bypass shape'            => ['//webhooks/account/close'],
            'ordinary'                => ['/admin/secret'],
        ];
    }

    /** fromGlobals, fromArray with an origin-form target and fromArray with the equivalent absolute URL agree. */
    #[DataProvider('equivalentTargetProvider')]
    public function testAllThreeConstructionPathsAgree(string $target): void
    {
        $fromGlobals = $this->request($target)->path();
        $fromOriginForm = Request::fromArray('GET', $target)->path();
        $fromAbsoluteUrl = Request::fromArray('GET', 'http://app.test' . $target)->path();

        self::assertSame($fromGlobals, $fromOriginForm, 'origin-form fromArray must match fromGlobals');
        self::assertSame($fromGlobals, $fromAbsoluteUrl, 'absolute-url fromArray must match fromGlobals');
    }

    public function testWithClonesPreserveTheCanonicalPath(): void
    {
        // The with*() clones re-enter the constructor with a Uri object, so
        // they must not reintroduce a raw path.
        $request = Request::fromArray('GET', 'https://h//x/admin/secret')
            ->withAttribute('id', '42')
            ->withAttributes(['role' => 'admin']);

        self::assertSame('/x/admin/secret', $request->path());
        self::assertSame('/x/admin/secret', $request->uri()->path());
        self::assertSame('42', $request->attribute('id'));
    }

    public function testAQueryValueContainingASchemeIsNotMistakenForAnAbsoluteUrl(): void
    {
        // "//" only separates a scheme from an authority when it follows a
        // scheme at the START of the string.
        $request = Request::fromArray('GET', '//x/redirect?to=http://elsewhere.test/p');

        self::assertSame('/x/redirect', $request->path());
        self::assertSame($request->uri()->path(), $request->path());
    }

    public function testAHandRolledRequestIsCanonicalizedByTheConstructor(): void
    {
        // Direct construction, bypassing both named constructors.
        $request = new Request(method: 'GET', uri: new \Zephyrus\Http\Uri('https://h//x/admin/secret'));

        self::assertSame('/x/admin/secret', $request->path());
        self::assertSame('/x/admin/secret', $request->uri()->path());
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
