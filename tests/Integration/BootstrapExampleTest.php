<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Zephyrus\Controller\Controller;
use Zephyrus\Core\KernelBuilder;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Localization\JsonLocaleLoader;
use Zephyrus\Localization\Translator;
use Zephyrus\Routing\Attribute\Route;
use Zephyrus\Routing\Router;

/**
 * Validates the bootstrap pattern demonstrated in public/index.php:
 *
 *   Request::fromGlobals() → KernelBuilder → HttpKernel::handle() → Response::send()
 *
 * Uses the same KernelBuilder + Controller + attribute routing pattern as
 * the real entry point to catch any regression that would break bootstrap.
 * All requests are built with Request::fromArray() — the equivalent of
 * fromGlobals() but injectable for deterministic testing.
 */
final class BootstrapExampleTest extends TestCase
{
    // -- KernelBuilder + attribute-based routing (mirrors public/index.php) ---

    private function buildBootstrapKernel(): \Zephyrus\Core\HttpKernel
    {
        $router = (new Router())
            ->controller(BootstrapHealthController::class)
            ->controller(BootstrapUserController::class)
            ->controller(BootstrapArticleController::class);

        $translator = new Translator(
            new JsonLocaleLoader(__DIR__ . '/Fixtures/locales'),
            'en'
        );

        return KernelBuilder::create()
            ->withRouter($router)
            ->withMiddleware(new BootstrapRequestIdMiddleware())
            ->withMiddleware(new BootstrapLocaleMiddleware())
            ->withControllerFactory(static function (string $class) use ($translator): object {
                return new $class($translator);
            })
            ->build();
    }

    // -- Health endpoint ------------------------------------------------------

    public function testHealthEndpointReturnsOkJson(): void
    {
        $kernel   = $this->buildBootstrapKernel();
        $response = $kernel->handle(Request::fromArray('GET', '/health'));

        self::assertSame(200, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        self::assertStringContainsString('"status":"ok"', $response->body);
        self::assertStringContainsString('"framework":"zephyrus2"', $response->body);
        self::assertStringContainsString('"message":"System healthy"', $response->body);
    }

    public function testHealthEndpointUsesLocaleFromHeaderWhenAvailable(): void
    {
        $kernel = $this->buildBootstrapKernel();
        $response = $kernel->handle(Request::fromArray('GET', '/health', headers: ['Accept-Language' => 'fr-CA,fr;q=0.9']));

        self::assertSame(200, $response->status);

        $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Système en santé', $payload['message']);
    }

    // -- Request-Id middleware stamps every response --------------------------

    public function testRequestIdMiddlewareStampsEveryResponse(): void
    {
        $kernel = $this->buildBootstrapKernel();

        $r1 = $kernel->handle(Request::fromArray('GET', '/health'));
        $r2 = $kernel->handle(Request::fromArray('GET', '/articles'));

        self::assertArrayHasKey('X-Request-Id', $r1->headers);
        self::assertArrayHasKey('X-Request-Id', $r2->headers);
        // Each request gets its own unique ID.
        self::assertNotSame($r1->headers['X-Request-Id'], $r2->headers['X-Request-Id']);
    }

    // -- Auth guard (before() hook) -------------------------------------------

    public function testUserEndpointReturns401WithoutApiKey(): void
    {
        $kernel   = $this->buildBootstrapKernel();
        $response = $kernel->handle(Request::fromArray('GET', '/users'));

        self::assertSame(401, $response->status);
        self::assertStringContainsString('"error"', $response->body);
    }

    public function testUserEndpointAllowsRequestWithApiKey(): void
    {
        $kernel = $this->buildBootstrapKernel();
        $response = $kernel->handle(
            Request::fromArray('GET', '/users', headers: ['X-Api-Key' => 'secret']),
        );

        self::assertSame(200, $response->status);
        self::assertStringContainsString('"users"', $response->body);
    }

    // -- after() hook stamps security header ----------------------------------

    public function testAfterHookAppendsXFrameOptionsHeader(): void
    {
        $kernel = $this->buildBootstrapKernel();
        $response = $kernel->handle(
            Request::fromArray('GET', '/users', headers: ['X-Api-Key' => 'key']),
        );

        self::assertSame('DENY', $response->headers['X-Frame-Options']);
    }

    // -- Route parameter injection (int $id) ----------------------------------

    public function testUserShowInjectsIntParameterFromPath(): void
    {
        $kernel = $this->buildBootstrapKernel();
        $response = $kernel->handle(
            Request::fromArray('GET', '/users/7', headers: ['X-Api-Key' => 'key']),
        );

        self::assertSame(200, $response->status);
        self::assertStringContainsString('"id":7', $response->body);
    }

    // -- POST → redirect-after-POST pattern ----------------------------------

    public function testUserStoreReturns303Redirect(): void
    {
        $kernel = $this->buildBootstrapKernel();
        $response = $kernel->handle(
            Request::fromArray(
                'POST',
                '/users',
                parsedBody: ['name' => 'Carol'],
                headers: ['X-Api-Key' => 'key'],
            ),
        );

        self::assertSame(303, $response->status);
        self::assertStringContainsString('/users/', $response->headers['Location']);
    }

    // -- Public controller — no auth guard ------------------------------------

    public function testArticleIndexRequiresNoAuth(): void
    {
        $kernel   = $this->buildBootstrapKernel();
        $response = $kernel->handle(Request::fromArray('GET', '/articles'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('"articles"', $response->body);
    }

    public function testArticleShowInjectsStringSlug(): void
    {
        $kernel   = $this->buildBootstrapKernel();
        $response = $kernel->handle(Request::fromArray('GET', '/articles/hello-world'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('"slug":"hello-world"', $response->body);
    }

    // -- Standard error handling ----------------------------------------------

    public function testUnknownRouteYields404(): void
    {
        $kernel   = $this->buildBootstrapKernel();
        $response = $kernel->handle(Request::fromArray('GET', '/not-found'));

        self::assertSame(404, $response->status);
    }

    public function testWrongMethodYields405(): void
    {
        $kernel   = $this->buildBootstrapKernel();
        $response = $kernel->handle(Request::fromArray('DELETE', '/health'));

        self::assertSame(405, $response->status);
        self::assertNotEmpty($response->headers['Allow']);
    }

    // -- Response::redirect() factory (standalone) ----------------------------

    public function testRedirectResponseHasLocationHeader(): void
    {
        $response = Response::redirect('/login');

        self::assertSame(302, $response->status);
        self::assertSame('/login', $response->headers['Location']);
        self::assertSame('', $response->body);
    }

    public function testRedirectResponseStatusCanBeOverridden(): void
    {
        $response = Response::redirect('/new', 301);

        self::assertSame(301, $response->status);
        self::assertSame('/new', $response->headers['Location']);
    }

    public function testRedirectResponseCanBeDecoratedWithAdditionalHeaders(): void
    {
        $response = Response::redirect('/dashboard', 303)
            ->withHeader('X-Reason', 'post-complete');

        self::assertSame(303, $response->status);
        self::assertSame('/dashboard', $response->headers['Location']);
        self::assertSame('post-complete', $response->headers['X-Reason']);
    }
}

// ===========================================================================
// Fixture controllers — mirrors public/index.php controllers exactly
// ===========================================================================

final class BootstrapHealthController extends Controller
{
    public function __construct(private readonly Translator $translator)
    {
    }

    #[Route('/health', 'GET', name: 'health')]
    public function show(Request $request): Response
    {
        return $this->json([
            'status' => 'ok',
            'framework' => 'zephyrus2',
            'message' => $this->translator->trans('health.message', locale: (string) $request->attribute('locale', 'en')),
        ]);
    }
}

final class BootstrapUserController extends Controller
{
    public function __construct(private readonly Translator $translator)
    {
    }

    public function before(Request $request): ?Response
    {
        if ($request->header('X-Api-Key') === null) {
            return $this->respond(['error' => 'API key required'], 401);
        }

        return null;
    }

    public function after(Request $request, Response $response): Response
    {
        return $response->withHeader('X-Frame-Options', 'DENY');
    }

    #[Route('/users', 'GET', name: 'users.index')]
    public function index(): Response
    {
        return $this->json([
            'users' => [
                ['id' => 1, 'name' => 'Alice'],
                ['id' => 2, 'name' => 'Bob'],
            ],
        ]);
    }

    #[Route('/users/{id}', 'GET', constraints: ['id' => '\d+'], name: 'users.show')]
    public function show(int $id): Response
    {
        return $this->json(['id' => $id, 'name' => 'Alice']);
    }

    #[Route('/users', 'POST', name: 'users.store')]
    public function store(Request $request): Response
    {
        $newId = 42;
        return Response::redirect('/users/' . $newId, 303);
    }
}

final class BootstrapArticleController extends Controller
{
    public function __construct(private readonly Translator $translator)
    {
    }

    #[Route('/articles', 'GET', name: 'articles.index')]
    public function index(): Response
    {
        return $this->json(['articles' => []]);
    }

    #[Route('/articles/{slug}', 'GET', name: 'articles.show')]
    public function show(string $slug): Response
    {
        return $this->json(['slug' => $slug]);
    }
}

// ===========================================================================
// Fixture middleware
// ===========================================================================

final class BootstrapRequestIdMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);

        return $response->withHeader('X-Request-Id', bin2hex(random_bytes(8)));
    }
}

final class BootstrapLocaleMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        $header = $request->header('Accept-Language', '');
        $locale = str_starts_with(strtolower($header), 'fr') ? 'fr' : 'en';

        return $next($request->withAttribute('locale', $locale));
    }
}
