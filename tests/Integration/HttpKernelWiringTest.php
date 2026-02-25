<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Zephyrus\Container\Container;
use Zephyrus\Controller\Controller;
use Zephyrus\Core\KernelBuilder;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Route;
use Zephyrus\Routing\Router;
use Zephyrus\Security\AllAuthGuard;
use Zephyrus\Security\AnyAuthGuard;
use Zephyrus\Security\AuthGuardMiddleware;
use Zephyrus\Security\HeaderTokenGuard;
use Zephyrus\Security\RequestAttributeGuard;

/**
 * End-to-end tests for the full HttpKernel → Router → RouteDispatcher
 * → HandlerResolver → Controller dispatch pipeline.
 *
 * Each test builds a real kernel via KernelBuilder with real controllers,
 * real middleware, and real routing — no mocking involved.
 */
final class HttpKernelWiringTest extends TestCase
{
    // -- Basic dispatch -------------------------------------------------------

    public function testGetToPlainController(): void
    {
        $router = (new Router())
            ->get('/ping', WiringPingController::class . '@ping');

        $kernel = KernelBuilder::create()->withRouter($router)->build();

        $response = $kernel->handle(Request::fromArray('GET', '/ping'));

        self::assertSame(200, $response->status);
        self::assertSame('pong', $response->body);
    }

    public function testGetToControllerSubclass(): void
    {
        $router = (new Router())
            ->get('/status', WiringStatusController::class . '@status');

        $kernel = KernelBuilder::create()->withRouter($router)->build();

        $response = $kernel->handle(Request::fromArray('GET', '/status'));

        self::assertSame(200, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        self::assertStringContainsString('"up":true', $response->body);
    }

    // -- Route parameter injection --------------------------------------------

    public function testIntParameterIsInjectedFromPath(): void
    {
        $router = (new Router())
            ->get('/users/{id}', WiringUserController::class . '@show', ['id' => '\d+']);

        $kernel = KernelBuilder::create()->withRouter($router)->build();

        $response = $kernel->handle(Request::fromArray('GET', '/users/42'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('"id":42', $response->body);
    }

    public function testStringParameterIsInjectedFromPath(): void
    {
        $router = (new Router())
            ->get('/posts/{slug}', WiringPostController::class . '@show');

        $kernel = KernelBuilder::create()->withRouter($router)->build();

        $response = $kernel->handle(Request::fromArray('GET', '/posts/hello-world'));

        self::assertSame(200, $response->status);
        self::assertSame('hello-world', $response->body);
    }

    public function testMultipleParametersAreInjected(): void
    {
        $router = (new Router())
            ->get('/orgs/{org}/repos/{repo}', WiringRepoController::class . '@show');

        $kernel = KernelBuilder::create()->withRouter($router)->build();

        $response = $kernel->handle(Request::fromArray('GET', '/orgs/acme/repos/zephyrus'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('"org":"acme"', $response->body);
        self::assertStringContainsString('"repo":"zephyrus"', $response->body);
    }

    // -- Request injection ----------------------------------------------------

    public function testRequestIsInjectedIntoControllerMethod(): void
    {
        $router = (new Router())
            ->post('/items', WiringItemController::class . '@store');

        $kernel = KernelBuilder::create()->withRouter($router)->build();

        $response = $kernel->handle(
            Request::fromArray('POST', '/items', parsedBody: ['name' => 'Widget', 'qty' => 3]),
        );

        self::assertSame(201, $response->status);
        self::assertStringContainsString('"name":"Widget"', $response->body);
        self::assertStringContainsString('"qty":3', $response->body);
    }

    public function testMixedRequestAndParameterInjection(): void
    {
        $router = (new Router())
            ->put('/users/{id}', WiringUserController::class . '@update', ['id' => '\d+']);

        $kernel = KernelBuilder::create()->withRouter($router)->build();

        $response = $kernel->handle(
            Request::fromArray('PUT', '/users/7', parsedBody: ['name' => 'Alice']),
        );

        self::assertSame(200, $response->status);
        self::assertStringContainsString('"id":7', $response->body);
        self::assertStringContainsString('"name":"Alice"', $response->body);
    }

    // -- HTTP error handling --------------------------------------------------

    public function testRouteNotFoundYields404(): void
    {
        $kernel = KernelBuilder::create()->withRouter(new Router())->build();

        $response = $kernel->handle(Request::fromArray('GET', '/nonexistent'));

        self::assertSame(404, $response->status);
    }

    public function testMethodNotAllowedYields405(): void
    {
        $router = (new Router())
            ->get('/resource', WiringPingController::class . '@ping');

        $kernel = KernelBuilder::create()->withRouter($router)->build();

        $response = $kernel->handle(Request::fromArray('POST', '/resource'));

        self::assertSame(405, $response->status);
        self::assertNotEmpty($response->headers['Allow']);
    }

    public function testRouteNotFoundWithJsonAcceptYieldsJsonError(): void
    {
        $kernel = KernelBuilder::create()->withRouter(new Router())->build();

        $response = $kernel->handle(
            Request::fromArray('GET', '/missing', headers: ['Accept' => 'application/json']),
        );

        self::assertSame(404, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        self::assertStringContainsString('"status":404', $response->body);
    }

    // -- Global middleware ----------------------------------------------------

    public function testGlobalMiddlewareWrapsEveryResponse(): void
    {
        $router = (new Router())
            ->get('/ping', WiringPingController::class . '@ping')
            ->get('/status', WiringStatusController::class . '@status');

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withMiddleware(new WiringHeaderMiddleware('X-Powered-By', 'Zephyrus'))
            ->build();

        $r1 = $kernel->handle(Request::fromArray('GET', '/ping'));
        $r2 = $kernel->handle(Request::fromArray('GET', '/status'));

        self::assertSame('Zephyrus', $r1->headers['X-Powered-By']);
        self::assertSame('Zephyrus', $r2->headers['X-Powered-By']);
    }

    public function testGlobalMiddlewareOrderIsPreserved(): void
    {
        $router = (new Router())
            ->get('/ping', WiringPingController::class . '@ping');

        $trace = [];

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withMiddleware(new WiringTracingMiddleware($trace, 'outer'))
            ->withMiddleware(new WiringTracingMiddleware($trace, 'inner'))
            ->build();

        $kernel->handle(Request::fromArray('GET', '/ping'));

        // Outer wraps inner: outer-before, inner-before, inner-after, outer-after.
        self::assertSame(['outer-before', 'inner-before', 'inner-after', 'outer-after'], $trace);
    }

    public function testGlobalMiddlewareReceivesRequestAttributes(): void
    {
        $router = (new Router())
            ->get('/users/{id}', WiringUserController::class . '@show', ['id' => '\d+']);

        $capturedAttributes = [];

        $captor = new class($capturedAttributes) implements MiddlewareInterface {
            /** @param array<string, mixed> $capture */
            public function __construct(private array &$capture)
            {
            }

            public function process(Request $request, callable $next): Response
            {
                $response = $next($request);
                // After inner execution request attributes (route params) were set.
                // We capture them from a follow-up attribute read.
                // Actually the pipeline receives the enriched request:
                // attributes are set BEFORE the pipeline runs in RouteDispatcher.
                $this->capture = $request->attributes;

                return $response;
            }
        };

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withMiddleware($captor)
            ->build();

        $kernel->handle(Request::fromArray('GET', '/users/5'));

        self::assertSame('5', $capturedAttributes['id']);
    }

    // -- Named route middleware -----------------------------------------------

    public function testNamedRouteMiddlewareIsApplied(): void
    {
        $router = (new Router())
            ->get(
                '/admin',
                WiringPingController::class . '@ping',
                middlewares: ['auth'],
            );

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->registerMiddleware('auth', new WiringHeaderMiddleware('X-Auth', 'ok'))
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/admin'));

        self::assertSame('ok', $response->headers['X-Auth']);
    }

    public function testNamedRouteMiddlewareOnlyAppliesOnMatchingRoutes(): void
    {
        $router = (new Router())
            ->get('/public', WiringPingController::class . '@ping')
            ->get('/private', WiringPingController::class . '@ping', middlewares: ['auth']);

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->registerMiddleware('auth', new WiringHeaderMiddleware('X-Auth', 'ok'))
            ->build();

        $publicResponse  = $kernel->handle(Request::fromArray('GET', '/public'));
        $privateResponse = $kernel->handle(Request::fromArray('GET', '/private'));

        self::assertArrayNotHasKey('X-Auth', $publicResponse->headers);
        self::assertSame('ok', $privateResponse->headers['X-Auth']);
    }

    public function testGlobalAndRouteMiddlewareBothApply(): void
    {
        $router = (new Router())
            ->get('/guarded', WiringPingController::class . '@ping', middlewares: ['auth']);

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withMiddleware(new WiringHeaderMiddleware('X-Global', 'yes'))
            ->registerMiddleware('auth', new WiringHeaderMiddleware('X-Auth', 'ok'))
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/guarded'));

        self::assertSame('yes', $response->headers['X-Global']);
        self::assertSame('ok', $response->headers['X-Auth']);
    }

    public function testAuthGuardMiddlewareRejectsUnauthorizedRequest(): void
    {
        $router = (new Router())
            ->get('/admin', WiringPingController::class . '@ping', middlewares: ['auth.guard']);

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->registerMiddleware('auth.guard', new AuthGuardMiddleware(new HeaderTokenGuard('top-secret')))
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/admin'));

        self::assertSame(401, $response->status);
        self::assertStringContainsString('Unauthorized', $response->body);
    }

    public function testAuthGuardMiddlewareAllowsAuthorizedRequest(): void
    {
        $router = (new Router())
            ->get('/admin', WiringPingController::class . '@ping', middlewares: ['auth.guard']);

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->registerMiddleware('auth.guard', new AuthGuardMiddleware(new HeaderTokenGuard('top-secret')))
            ->build();

        $response = $kernel->handle(Request::fromArray(
            'GET',
            '/admin',
            headers: ['Authorization' => 'Bearer top-secret'],
        ));

        self::assertSame(200, $response->status);
        self::assertSame('pong', $response->body);
    }

    public function testCompositeAuthGuardsCanBeUsedInMiddleware(): void
    {
        $router = (new Router())
            ->get('/composite', WiringPingController::class . '@ping', middlewares: ['auth.guard']);

        $anyGuard = new AnyAuthGuard([
            new HeaderTokenGuard('api-token', headerName: 'X-Api-Key', bearerPrefix: ''),
            new HeaderTokenGuard('bearer-token'),
        ]);

        $allGuard = new AllAuthGuard([
            $anyGuard,
            new HeaderTokenGuard('tenant-42', headerName: 'X-Tenant-Token', bearerPrefix: ''),
        ]);

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->registerMiddleware('auth.guard', new AuthGuardMiddleware($allGuard, 403, 'Forbidden'))
            ->build();

        $denied = $kernel->handle(Request::fromArray(
            'GET',
            '/composite',
            headers: ['X-Api-Key' => 'api-token'],
        ));
        self::assertSame(403, $denied->status);

        $allowed = $kernel->handle(Request::fromArray(
            'GET',
            '/composite',
            headers: [
                'X-Api-Key' => 'api-token',
                'X-Tenant-Token' => 'tenant-42',
            ],
        ));

        self::assertSame(200, $allowed->status);
    }

    public function testRequestAttributeGuardCanProtectRoutes(): void
    {
        $router = (new Router())
            ->get('/role-guarded', WiringPingController::class . '@ping', middlewares: ['auth.guard']);

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withMiddleware(new class implements MiddlewareInterface {
                public function process(Request $request, callable $next): Response
                {
                    return $next($request->withAttribute('role', 'teacher'));
                }
            })
            ->registerMiddleware('auth.guard', new AuthGuardMiddleware(new RequestAttributeGuard('role', ['admin']), 403, 'Forbidden'))
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/role-guarded'));

        self::assertSame(403, $response->status);

        $kernelAllowed = KernelBuilder::create()
            ->withRouter($router)
            ->withMiddleware(new class implements MiddlewareInterface {
                public function process(Request $request, callable $next): Response
                {
                    return $next($request->withAttribute('role', 'admin'));
                }
            })
            ->registerMiddleware('auth.guard', new AuthGuardMiddleware(new RequestAttributeGuard('role', ['admin']), 403, 'Forbidden'))
            ->build();

        $allowed = $kernelAllowed->handle(Request::fromArray('GET', '/role-guarded'));

        self::assertSame(200, $allowed->status);
    }

    // -- Attribute-based route registration -----------------------------------

    public function testAttributeBasedRoutesAreDispatchedCorrectly(): void
    {
        $router = (new Router())->controller(WiringAttributeController::class);

        $kernel = KernelBuilder::create()->withRouter($router)->build();

        $list   = $kernel->handle(Request::fromArray('GET', '/products'));
        $single = $kernel->handle(Request::fromArray('GET', '/products/7'));

        self::assertSame(200, $list->status);
        self::assertStringContainsString('"products":[]', $list->body);

        self::assertSame(200, $single->status);
        self::assertStringContainsString('"product_id":7', $single->body);
    }

    public function testAttributeRoutesCanBeMixedWithFluentRoutes(): void
    {
        $router = (new Router())
            ->get('/ping', WiringPingController::class . '@ping')
            ->controller(WiringAttributeController::class);

        $kernel = KernelBuilder::create()->withRouter($router)->build();

        $ping     = $kernel->handle(Request::fromArray('GET', '/ping'));
        $products = $kernel->handle(Request::fromArray('GET', '/products'));

        self::assertSame('pong', $ping->body);
        self::assertStringContainsString('"products":[]', $products->body);
    }

    // -- Resource routes (CRUD) -----------------------------------------------

    public function testResourceRoutesCoverCrudOperations(): void
    {
        $router = (new Router())->resource('/notes', WiringNoteController::class);

        $kernel = KernelBuilder::create()->withRouter($router)->build();

        $index  = $kernel->handle(Request::fromArray('GET', '/notes'));
        $show   = $kernel->handle(Request::fromArray('GET', '/notes/3'));
        $store  = $kernel->handle(Request::fromArray('POST', '/notes'));
        $update = $kernel->handle(Request::fromArray('PUT', '/notes/3'));
        $patch  = $kernel->handle(Request::fromArray('PATCH', '/notes/3'));
        $delete = $kernel->handle(Request::fromArray('DELETE', '/notes/3'));

        self::assertSame('index', $index->body);
        self::assertSame('show:3', $show->body);
        self::assertSame(201, $store->status);
        self::assertSame('update:3', $update->body);
        self::assertSame('patch:3', $patch->body);
        self::assertSame(204, $delete->status);
    }

    // -- Custom controller factory (DI integration) ---------------------------

    public function testCustomControllerFactoryIsUsedForInstantiation(): void
    {
        $factoryCalls = [];

        $router = (new Router())
            ->get('/greet', WiringGreetController::class . '@hello');

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withControllerFactory(function (string $class) use (&$factoryCalls): object {
                $factoryCalls[] = $class;

                return new $class('Hi');
            })
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/greet'));

        self::assertSame('Hi', $response->body);
        self::assertSame([WiringGreetController::class], $factoryCalls);
    }

    // -- Route grouping -------------------------------------------------------

    public function testGroupedRoutesAreDispatchedWithPrefixedPaths(): void
    {
        $router = (new Router())
            ->group('/api/v1', function (Router $r): Router {
                return $r
                    ->get('/ping', WiringPingController::class . '@ping')
                    ->get('/users/{id}', WiringUserController::class . '@show', ['id' => '\d+']);
            });

        $kernel = KernelBuilder::create()->withRouter($router)->build();

        $ping = $kernel->handle(Request::fromArray('GET', '/api/v1/ping'));
        $user = $kernel->handle(Request::fromArray('GET', '/api/v1/users/9'));

        self::assertSame('pong', $ping->body);
        self::assertStringContainsString('"id":9', $user->body);
    }

    public function testGroupSharedMiddlewareIsAppliedToAllRoutes(): void
    {
        $router = (new Router())
            ->group('/admin', function (Router $r): Router {
                return $r
                    ->get('/dashboard', WiringPingController::class . '@ping')
                    ->get('/users', WiringPingController::class . '@ping');
            }, ['auth']);

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->registerMiddleware('auth', new WiringHeaderMiddleware('X-Auth', 'ok'))
            ->build();

        $dashboard = $kernel->handle(Request::fromArray('GET', '/admin/dashboard'));
        $users     = $kernel->handle(Request::fromArray('GET', '/admin/users'));

        self::assertSame('ok', $dashboard->headers['X-Auth']);
        self::assertSame('ok', $users->headers['X-Auth']);
    }

    public function testMiddlewareGroupAliasAppliesToGroupedRoutes(): void
    {
        $router = (new Router())
            ->middlewareGroup('web', ['session', 'csrf'])
            ->group('/app', function (Router $r): Router {
                return $r
                    ->get('/home', WiringPingController::class . '@ping', middlewares: ['web'])
                    ->get('/profile', WiringPingController::class . '@ping', middlewares: ['web', 'auth']);
            });

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->registerMiddleware('session', new WiringHeaderMiddleware('X-Session', 'on'))
            ->registerMiddleware('csrf', new WiringHeaderMiddleware('X-Csrf', 'ok'))
            ->registerMiddleware('auth', new WiringHeaderMiddleware('X-Auth', 'ok'))
            ->build();

        $home = $kernel->handle(Request::fromArray('GET', '/app/home'));
        $profile = $kernel->handle(Request::fromArray('GET', '/app/profile'));

        self::assertSame('on', $home->headers['X-Session']);
        self::assertSame('ok', $home->headers['X-Csrf']);
        self::assertArrayNotHasKey('X-Auth', $home->headers);

        self::assertSame('on', $profile->headers['X-Session']);
        self::assertSame('ok', $profile->headers['X-Csrf']);
        self::assertSame('ok', $profile->headers['X-Auth']);
    }

    // -- Controller lifecycle hooks -------------------------------------------

    public function testBeforeHookShortCircuitsUnauthorizedRequest(): void
    {
        $router = (new Router())
            ->get('/secure', WiringSecuredController::class . '@act');

        $kernel = KernelBuilder::create()->withRouter($router)->build();

        // No token → before() returns 401.
        $response = $kernel->handle(Request::fromArray('GET', '/secure'));

        self::assertSame(401, $response->status);
        self::assertStringContainsString('"error":"Unauthorized"', $response->body);
    }

    public function testBeforeHookPassesThroughAuthorizedRequest(): void
    {
        $router = (new Router())
            ->get('/secure', WiringSecuredController::class . '@act');

        $kernel = KernelBuilder::create()->withRouter($router)->build();

        $response = $kernel->handle(
            Request::fromArray('GET', '/secure', headers: ['X-Token' => 'valid']),
        );

        self::assertSame(200, $response->status);
        self::assertSame('authorized', $response->body);
    }

    public function testAfterHookDecoratesEveryResponse(): void
    {
        $router = (new Router())
            ->get('/stamp', WiringStampController::class . '@act');

        $kernel = KernelBuilder::create()->withRouter($router)->build();

        $response = $kernel->handle(Request::fromArray('GET', '/stamp'));

        self::assertSame(200, $response->status);
        self::assertSame('stamped', $response->headers['X-Stamp']);
    }

    public function testAfterHookDoesNotRunWhenBeforeShortCircuits(): void
    {
        $router = (new Router())
            ->get('/combo', WiringComboController::class . '@act');

        $kernel = KernelBuilder::create()->withRouter($router)->build();

        // before() returns 403 → after() should never add X-Combo.
        $response = $kernel->handle(
            Request::fromArray('GET', '/combo', headers: ['X-Halt' => '1']),
        );

        self::assertSame(403, $response->status);
        self::assertArrayNotHasKey('X-Combo', $response->headers);
    }

    public function testAfterHookRunsWhenBeforePassesThrough(): void
    {
        $router = (new Router())
            ->get('/combo', WiringComboController::class . '@act');

        $kernel = KernelBuilder::create()->withRouter($router)->build();

        $response = $kernel->handle(Request::fromArray('GET', '/combo'));

        self::assertSame(200, $response->status);
        self::assertSame('yes', $response->headers['X-Combo']);
    }

    // -- Container integration (withContainer) --------------------------------

    public function testWithContainerAutoWiresControllerDependencies(): void
    {
        $container = new Container();
        // WiringGreetingService is auto-wired (no explicit binding required).

        $router = (new Router())
            ->get('/hello', WiringContainerGreetController::class . '@greet');

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withContainer($container)
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/hello'));

        self::assertSame(200, $response->status);
        self::assertSame('Hello from service', $response->body);
    }

    public function testWithContainerHonoursSingletonBinding(): void
    {
        $container = new Container();
        $instances = [];

        $container->singleton(WiringContainerGreetController::class, function () use (&$instances): WiringContainerGreetController {
            $ctrl = new WiringContainerGreetController(new WiringGreetingService());
            $instances[] = $ctrl;

            return $ctrl;
        });

        $router = (new Router())
            ->get('/hello', WiringContainerGreetController::class . '@greet');

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withContainer($container)
            ->build();

        $kernel->handle(Request::fromArray('GET', '/hello'));
        $kernel->handle(Request::fromArray('GET', '/hello'));

        // Singleton: factory called only once even with two requests.
        self::assertCount(1, $instances);
    }

    public function testWithContainerCanProvideExplicitControllerBinding(): void
    {
        $container = new Container();
        $container->bind(WiringGreetController::class, fn (): WiringGreetController => new WiringGreetController('Howdy'));

        $router = (new Router())
            ->get('/greet', WiringGreetController::class . '@hello');

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withContainer($container)
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/greet'));

        self::assertSame(200, $response->status);
        self::assertSame('Howdy', $response->body);
    }
}

// ===========================================================================
// Fixture controllers
// ===========================================================================

final class WiringPingController
{
    public function ping(): Response
    {
        return Response::text('pong');
    }
}

final class WiringStatusController extends Controller
{
    public function status(): Response
    {
        return $this->json(['up' => true]);
    }
}

final class WiringUserController extends Controller
{
    public function show(int $id): Response
    {
        return $this->json(['id' => $id]);
    }

    public function update(int $id, Request $request): Response
    {
        return $this->json(['id' => $id, 'name' => $request->input('name')]);
    }
}

final class WiringPostController
{
    public function show(string $slug): Response
    {
        return Response::text($slug);
    }
}

final class WiringRepoController extends Controller
{
    public function show(string $org, string $repo): Response
    {
        return $this->json(['org' => $org, 'repo' => $repo]);
    }
}

final class WiringItemController extends Controller
{
    public function store(Request $request): Response
    {
        return $this->created([
            'name' => $request->input('name'),
            'qty'  => $request->input('qty'),
        ]);
    }
}

final class WiringNoteController extends Controller
{
    public function index(): Response
    {
        return $this->text('index');
    }

    public function show(int $id): Response
    {
        return $this->text('show:' . $id);
    }

    public function store(): Response
    {
        return $this->created(['created' => true]);
    }

    public function update(int $id): Response
    {
        return $this->text('update:' . $id);
    }

    public function patch(int $id): Response
    {
        return $this->text('patch:' . $id);
    }

    public function delete(): Response
    {
        return $this->noContent();
    }
}

final class WiringGreetController
{
    public function __construct(private readonly string $greeting)
    {
    }

    public function hello(): Response
    {
        return Response::text($this->greeting);
    }
}

final class WiringAttributeController
{
    #[Route('/products', 'GET')]
    public function list(): Response
    {
        return Response::json(['products' => []]);
    }

    #[Route('/products/{id}', 'GET')]
    public function show(int $id): Response
    {
        return Response::json(['product_id' => $id]);
    }
}

/** Guards via before(): requires X-Token: valid header. */
final class WiringSecuredController extends Controller
{
    public function before(Request $request): ?Response
    {
        if ($request->header('X-Token') !== 'valid') {
            return $this->respond(['error' => 'Unauthorized'], 401);
        }

        return null;
    }

    public function act(): Response
    {
        return $this->text('authorized');
    }
}

/** Stamps X-Stamp header via after() on every response. */
final class WiringStampController extends Controller
{
    public function after(Request $request, Response $response): Response
    {
        return $response->withHeader('X-Stamp', 'stamped');
    }

    public function act(): Response
    {
        return $this->text('body');
    }
}

/** Both hooks: before() halts on X-Halt:1; after() adds X-Combo:yes. */
final class WiringComboController extends Controller
{
    public function before(Request $request): ?Response
    {
        if ($request->header('X-Halt') === '1') {
            return Response::text('halted', 403);
        }

        return null;
    }

    public function after(Request $request, Response $response): Response
    {
        return $response->withHeader('X-Combo', 'yes');
    }

    public function act(): Response
    {
        return $this->text('combo');
    }
}

// ===========================================================================
// Fixture middleware
// ===========================================================================

final class WiringHeaderMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $name,
        private readonly string $value,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        return $next($request)->withHeader($this->name, $this->value);
    }
}

final class WiringTracingMiddleware implements MiddlewareInterface
{
    /** @param list<string> $trace */
    public function __construct(
        private array &$trace,
        private readonly string $label,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $this->trace[] = $this->label . '-before';
        $response = $next($request);
        $this->trace[] = $this->label . '-after';

        return $response;
    }
}

// ===========================================================================
// Container integration fixtures
// ===========================================================================

/** Simple service used to verify auto-wiring through the container. */
final class WiringGreetingService
{
    public function greet(): string
    {
        return 'Hello from service';
    }
}

/** Controller with a constructor dependency resolved by the container. */
final class WiringContainerGreetController
{
    public function __construct(private readonly WiringGreetingService $service)
    {
    }

    public function greet(): Response
    {
        return Response::text($this->service->greet());
    }
}
