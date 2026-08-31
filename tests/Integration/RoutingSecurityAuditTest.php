<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\HttpKernel;
use Zephyrus\Core\KernelBuilder;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Router;
use Zephyrus\Security\AuthGuardMiddleware;
use Zephyrus\Security\RequestAttributeGuard;

/**
 * Three routing defects, each reproduced against the real HttpKernel pipeline
 * because each one only exists once matching, the attribute merge and handler
 * resolution are wired together.
 *
 *  1. Request::path() was not the path the router dispatched on. The router
 *     rawurldecode()d every segment, so "/%61dmin/secret" reached
 *     "/admin/secret" while the guard inspecting path() saw "/%61dmin/secret".
 *  2. A matched route parameter satisfied a RequestAttributeGuard, so a URL
 *     segment supplied the value a guard authorised on.
 *  3. A route constraint validated the URL segment while the handler received
 *     whatever a middleware had since written into the attributes.
 */
final class RoutingSecurityAuditTest extends TestCase
{
    private function get(string $target, array $headers = []): Request
    {
        $server = [
            'REQUEST_URI' => $target,
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST' => 'app.test',
        ];

        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::fromGlobals(
            server: $server,
            get: [],
            post: [],
            cookie: [],
            files: [],
            rawBody: '',
        );
    }

    // =====================================================================
    // 1. The percent-encoded prefix bypass
    // =====================================================================

    /**
     * The guard is the literal pattern the docblock on Request::path() used to
     * recommend, applied through a real global middleware.
     */
    private function guardedKernel(): HttpKernel
    {
        return KernelBuilder::create()
            ->withRouter(
                (new Router())
                    ->get('/admin/secret', AuditController::class . '@secret')
                    ->get('/public/page', AuditController::class . '@page'),
            )
            ->withMiddleware(new PathPrefixGuardMiddleware('/admin'))
            ->build();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function encodedAdminTargetProvider(): array
    {
        return [
            'first letter encoded' => ['/%61dmin/secret'],
            'whole segment encoded' => ['/%61%64%6d%69%6e/secret'],
            'mid-segment escape' => ['/adm%69n/secret'],
            'trailing segment escape' => ['/admin/secr%65t'],
        ];
    }

    /**
     * Pre-fix, every one of these answered 200 "TOP SECRET DATA": the guard
     * read the raw path, returned false, and the router decoded the segment and
     * dispatched the protected route anyway.
     */
    #[DataProvider('encodedAdminTargetProvider')]
    public function testAPercentEncodedPrefixCannotReachAGuardedRoute(string $target): void
    {
        $response = $this->guardedKernel()->handle($this->get($target));

        self::assertStringNotContainsString('TOP SECRET DATA', $response->body, $target);
        self::assertNotSame(200, $response->status, $target);
    }

    public function testTheGuardStillBlocksAndTheOrdinaryRoutesStillWork(): void
    {
        $kernel = $this->guardedKernel();

        // Non-breakage: the guard still refuses the plain path.
        self::assertSame(401, $kernel->handle($this->get('/admin/secret'))->status);

        // Non-breakage: an unguarded route is untouched.
        $page = $kernel->handle($this->get('/public/page'));
        self::assertSame(200, $page->status);
        self::assertSame('PAGE', trim($page->body));
    }

    public function testAnEncodedParameterSegmentStillReachesTheHandlerDecoded(): void
    {
        // Only LITERAL segments are compared raw. A placeholder is still
        // decoded before its constraint is applied and before the handler sees
        // it, which is the behaviour every application depends on.
        $kernel = KernelBuilder::create()
            ->withRouter((new Router())->get('/tags/{name}', AuditController::class . '@tag'))
            ->build();

        $response = $kernel->handle($this->get('/tags/hello%20world'));

        self::assertSame(200, $response->status);
        self::assertSame('TAG:hello world', trim($response->body));
    }

    // =====================================================================
    // 2. A route parameter satisfying an attribute guard
    // =====================================================================

    /**
     * The exploitable ordering is the natural one: RolePublishingMiddleware
     * only publishes "role" when a session exists, so an ANONYMOUS caller left
     * the route parameter in place and a logged-in one overwrote it.
     */
    private function roleGuardedKernel(): HttpKernel
    {
        return KernelBuilder::create()
            ->withRouter((new Router())->get('/reports/{role}', AuditController::class . '@reports'))
            ->withMiddleware(new RolePublishingMiddleware())
            ->withMiddleware(new AuthGuardMiddleware(new RequestAttributeGuard('role', ['admin'])))
            ->build();
    }

    public function testARouteParameterCannotSatisfyARequestAttributeGuard(): void
    {
        // Pre-fix: 200 "CONFIDENTIAL REPORTS for role=admin", with no session.
        $response = $this->roleGuardedKernel()->handle($this->get('/reports/admin'));

        self::assertSame(401, $response->status);
        self::assertStringNotContainsString('CONFIDENTIAL REPORTS', $response->body);
    }

    public function testAGenuinelyPublishedAttributeStillSatisfiesTheGuard(): void
    {
        // Non-breakage: the guard must still AUTHORISE when the value comes
        // from the middleware rather than from the URL.
        $kernel = KernelBuilder::create()
            ->withRouter((new Router())->get('/reports', AuditController::class . '@reportsPlain'))
            ->withMiddleware(new RolePublishingMiddleware())
            ->withMiddleware(new AuthGuardMiddleware(new RequestAttributeGuard('role', ['admin'])))
            ->build();

        $response = $kernel->handle($this->get('/reports', ['X-Session-Role' => 'admin']));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('CONFIDENTIAL REPORTS', $response->body);
    }

    public function testProvenanceIsRecordedAndSurvivesAnOverwrite(): void
    {
        $kernel = KernelBuilder::create()
            ->withRouter((new Router())->get('/reports/{role}', AuditController::class . '@provenance'))
            ->withMiddleware(new RolePublishingMiddleware())
            ->build();

        // A logged-in caller: the middleware overwrites the attribute, and the
        // name is STILL flagged as route-sourced. Fail-closed by design; see
        // Request::$routeParameters.
        $response = $kernel->handle($this->get('/reports/admin', ['X-Session-Role' => 'viewer']));

        self::assertSame('attribute=viewer route=admin isRouteParameter=yes', trim($response->body));
    }

    // =====================================================================
    // 3. A constraint that did not govern the handler argument
    // =====================================================================

    public function testAConstrainedParameterCannotBeReplacedByAMiddleware(): void
    {
        $kernel = KernelBuilder::create()
            ->withRouter((new Router())->get(
                '/docs/{docId}',
                AuditController::class . '@doc',
                constraints: ['docId' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'],
            ))
            ->withMiddleware(new HeaderAttributeOverrideMiddleware('X-Doc-Id', 'docId'))
            ->build();

        $response = $kernel->handle($this->get(
            '/docs/3f2504e0-4f89-41d3-9a0c-0305e82c3301',
            ['X-Doc-Id' => '../../../etc/passwd'],
        ));

        // Pre-fix: 200 "LOADING FILE: /var/docs/../../../etc/passwd.pdf".
        self::assertSame(200, $response->status);
        self::assertSame(
            'LOADING FILE: /var/docs/3f2504e0-4f89-41d3-9a0c-0305e82c3301.pdf',
            trim($response->body),
        );
    }

    public function testAMiddlewareCanStillPublishAnAttributeThatIsNotARouteParameter(): void
    {
        // Non-breakage: name-binding for a NON-placeholder attribute is exactly
        // how a middleware feeds a handler, and it is untouched.
        $kernel = KernelBuilder::create()
            ->withRouter((new Router())->get('/tenant/report', AuditController::class . '@tenantReport'))
            ->withMiddleware(new HeaderAttributeOverrideMiddleware('X-Tenant', 'tenant'))
            ->build();

        $response = $kernel->handle($this->get('/tenant/report', ['X-Tenant' => 'acme']));

        self::assertSame('TENANT:acme', trim($response->body));
    }
}

// ===========================================================================
// Fixtures
// ===========================================================================

final class AuditController
{
    public function secret(): Response
    {
        return Response::text('TOP SECRET DATA');
    }

    public function page(): Response
    {
        return Response::text('PAGE');
    }

    public function tag(string $name): Response
    {
        return Response::text('TAG:' . $name);
    }

    public function reports(string $role): Response
    {
        return Response::text('CONFIDENTIAL REPORTS for role=' . $role);
    }

    public function reportsPlain(Request $request): Response
    {
        return Response::text('CONFIDENTIAL REPORTS for role=' . (string) $request->attribute('role'));
    }

    public function provenance(Request $request): Response
    {
        return Response::text(sprintf(
            'attribute=%s route=%s isRouteParameter=%s',
            (string) $request->attribute('role'),
            (string) $request->routeParameter('role'),
            $request->isRouteParameter('role') ? 'yes' : 'no',
        ));
    }

    public function doc(string $docId): Response
    {
        return Response::text('LOADING FILE: /var/docs/' . $docId . '.pdf');
    }

    public function tenantReport(string $tenant): Response
    {
        return Response::text('TENANT:' . $tenant);
    }
}

/** The exact guard shape Request::path()'s docblock used to recommend. */
final class PathPrefixGuardMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $prefix)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        if (str_starts_with($request->path(), $this->prefix)) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        /** @var Response */
        return $next($request);
    }
}

/** Publishes "role" ONLY when a session exists, which is what made it exploitable. */
final class RolePublishingMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        $sessionRole = $request->headers()->get('X-Session-Role');

        if ($sessionRole !== null) {
            $request = $request->withAttribute('role', $sessionRole);
        }

        /** @var Response */
        return $next($request);
    }
}

/** Writes a header value into an attribute, the way a real resolver would. */
final class HeaderAttributeOverrideMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $header,
        private readonly string $attribute,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $value = $request->headers()->get($this->header);

        if ($value !== null) {
            $request = $request->withAttribute($this->attribute, $value);
        }

        /** @var Response */
        return $next($request);
    }
}
