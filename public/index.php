<?php

declare(strict_types=1);

/**
 * Zephyrus2 bootstrap — public entry point.
 *
 * This is the only PHP file that the web server exposes. All non-file
 * requests should be rewritten here (see nginx/apache examples at the
 * bottom of this file).
 *
 * Full lifecycle:
 *
 *   Request::fromGlobals()         — build an immutable Request from superglobals
 *     → KernelBuilder::build()     — wire Router → Pipeline → HandlerResolver
 *     → HttpKernel::handle()       — dispatch, run middleware, call controller
 *     → Response::send()           — emit status line, headers, body to SAPI
 *
 * In a real application the controllers below live in app/Controllers/,
 * the middleware in app/Http/Middleware/, and the router in app/bootstrap/
 * or a service-provider equivalent.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Zephyrus\Controller\Controller;
use Zephyrus\Core\KernelBuilder;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Localization\JsonLocaleLoader;
use Zephyrus\Localization\Translator;
use Zephyrus\Routing\Attribute\Route;
use Zephyrus\Routing\Router;

// ---------------------------------------------------------------------------
// Controllers
// ---------------------------------------------------------------------------

/**
 * Responds to GET /health with a JSON liveness probe.
 */
final class HealthController extends Controller
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

/**
 * Minimal REST-style user resource demonstrating:
 *   - attribute-based routing with constraints and named routes
 *   - typed route-parameter injection (int $id)
 *   - Request injection for POST bodies
 *   - redirect factory for POST → GET redirect-after-POST pattern
 */
final class UserController extends Controller
{
    public function __construct(private readonly Translator $translator)
    {
    }

    // Require before() to pass (no auth header → 401).
    public function before(Request $request): ?Response
    {
        if ($request->headers()->get('X-Api-Key') === null) {
            return $this->respond(['error' => 'API key required'], 401);
        }

        return null;
    }

    // Stamp every response from this controller with a security header.
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
        $name = (string) ($request->body()->get('name') ?? 'Anonymous');

        // Redirect-after-POST: 303 See Other to the new resource URL.
        // The created ID would normally come from the database insert.
        $newId = 42;
        return Response::redirect('/users/' . $newId, 303)
            ->withHeader('X-Frame-Options', 'DENY'); // after() won't run on redirect short-circuit
    }
}

/**
 * Public read-only endpoint — no auth guard required.
 * Demonstrates that Controllers without lifecycle hooks are unaffected.
 */
final class ArticleController extends Controller
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

// ---------------------------------------------------------------------------
// Middleware
// ---------------------------------------------------------------------------

/**
 * Stamps every response with a unique X-Request-Id header for correlation.
 * Global middleware runs before route-level middleware and the handler.
 */
final class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);

        return $response->withHeader('X-Request-Id', bin2hex(random_bytes(8)));
    }
}

final class LocaleMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        $header = $request->headers()->get('Accept-Language', '');
        $locale = str_starts_with(strtolower($header), 'fr') ? 'fr' : 'en';

        return $next($request->withAttribute('locale', $locale));
    }
}

// ---------------------------------------------------------------------------
// Router — register all routes
// ---------------------------------------------------------------------------

$router = (new Router())
    ->controller(HealthController::class)   // GET /health
    ->controller(UserController::class)      // GET /users, GET /users/{id}, POST /users
    ->controller(ArticleController::class);  // GET /articles, GET /articles/{slug}

// ---------------------------------------------------------------------------
// Kernel assembly — done once at startup
// ---------------------------------------------------------------------------

$translator = new Translator(
    new JsonLocaleLoader(dirname(__DIR__) . '/resources/lang'),
    'en'
);

$kernel = KernelBuilder::create()
    ->withRouter($router)
    ->withMiddleware(new RequestIdMiddleware())
    ->withMiddleware(new LocaleMiddleware())
    ->withControllerFactory(static fn (string $class): object => new $class($translator))
    // Example: register named middleware for routes that need auth:
    // ->registerMiddleware('auth', new JwtAuthMiddleware($jwtSecret))
    ->build();

// ---------------------------------------------------------------------------
// Dispatch the incoming request and emit the response
// ---------------------------------------------------------------------------

$request  = Request::fromGlobals();
$response = $kernel->handle($request);
$response->send();

// ---------------------------------------------------------------------------
// Web-server configuration hints
// ---------------------------------------------------------------------------

/*
 * nginx — rewrite all non-file requests to this entry point:
 *
 *   location / {
 *       try_files $uri $uri/ /index.php?$query_string;
 *   }
 *
 *   location ~ \.php$ {
 *       fastcgi_pass unix:/run/php/php8.4-fpm.sock;
 *       include fastcgi_params;
 *       fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
 *   }
 *
 * Apache — add a .htaccess in public/:
 *
 *   Options -MultiViews
 *   RewriteEngine On
 *   RewriteCond %{REQUEST_FILENAME} !-f
 *   RewriteRule ^ index.php [QSA,L]
 */
