# Zephyrus

[![CI](https://github.com/ophelios-studio/zephyrus-core/actions/workflows/ci.yml/badge.svg?branch=dev)](https://github.com/ophelios-studio/zephyrus-core/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/ophelios-studio/zephyrus-core/graph/badge.svg)](https://codecov.io/gh/ophelios-studio/zephyrus-core)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

A cohesive PHP 8.4+ framework core. Attribute-based routing, immutable HTTP objects, typed configuration, and a full security middleware stack.

---

## Getting Started

Install the core library:

```bash
composer require zephyrus-framework/core
```

---

## Overview

### Routing

Define routes with PHP 8 attributes directly on controller methods:

```php
use Zephyrus\Controller\Controller;
use Zephyrus\Routing\Attribute\Get;
use Zephyrus\Routing\Attribute\Post;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

class UserController extends Controller
{
    #[Get('/users')]
    public function index(): Response
    {
        return Response::json(['users' => []]);
    }

    #[Get('/users/{id}')]
    public function show(int $id): Response
    {
        return Response::json(['id' => $id]);
    }

    #[Post('/users')]
    public function store(Request $request): Response
    {
        $data = $request->body()->all();
        return Response::json(['created' => true], 201);
    }
}
```

Available verb attributes: `#[Get]`, `#[Post]`, `#[Put]`, `#[Patch]`, `#[Delete]`, `#[Head]`, `#[Options]`.

Route parameters are injected by name with automatic type coercion. A type mismatch (e.g. `"abc"` for `int $id`) returns a 404.

#### Route Prefixing

Use `#[Root]` to apply a URL prefix to an entire controller. It supports inheritance: child controller prefixes are appended to parent prefixes:

```php
#[Root('/admin')]
class AdminController extends Controller {}

#[Root('/users')]
class AdminUserController extends AdminController
{
    #[Get('/list')]  // resolves to /admin/users/list
    public function list(): Response { ... }
}
```

#### Auto-Discovery

Instead of registering controllers one by one, scan a directory:

```php
$router = $router->discoverControllers('App\\Controllers', __DIR__ . '/../app/Controllers');
```

### Middleware

Middleware implements `MiddlewareInterface`: `process()` receives the request and the next handler, and returns the response.

```php
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

final class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        return $next($request)->withHeader('X-Request-Id', bin2hex(random_bytes(8)));
    }
}
```

Register it globally with `withMiddleware()`, as the bootstrap does, or under a name with `registerMiddleware()`. For authentication, `AuthGuardMiddleware` answers 401 when its guard refuses the request. `HeaderTokenGuard` checks the bearer token with a constant-time comparison. Load the token from the environment, never from source code:

```php
use Zephyrus\Security\AuthGuardMiddleware;
use Zephyrus\Security\HeaderTokenGuard;

$apiToken = getenv('API_TOKEN') ?: throw new \RuntimeException('API_TOKEN is not set');
$apiAuth = new AuthGuardMiddleware(new HeaderTokenGuard($apiToken));
```

Register it under a name on the bootstrap's builder, then reference that name in route attributes. Mutating API calls authenticate with the bearer token instead of the CSRF token, so exclude the API prefix from the CSRF check under `security.csrf.exceptions` in your configuration file:

```yaml
security:
  csrf:
    exceptions: ['#^/api/#']
```

The middleware reads that section through `CsrfConfig::fromSecurityConfig()`. This chain replaces the one in the Bootstrap section, and needs the `use` line for `CsrfConfig`; the other imports are those of the Bootstrap block:

```php
use Zephyrus\Security\CsrfConfig;

$kernel = KernelBuilder::create()
    ->withRouter($router)
    ->registerMiddleware('auth', $apiAuth)
    ->withMiddleware(new SecureHeadersMiddleware($configuration->security->headers))
    ->withMiddleware(new SessionMiddleware(SessionConfig::fromArray([]), $session))
    ->withMiddleware(new CsrfMiddleware($csrf, CsrfConfig::fromSecurityConfig($configuration->security)))
    ->build();
```

Global middlewares run in registration order, the first registered being the outermost. Registration order also decides what a short-circuit response carries: `SecureHeadersMiddleware` comes first so that a CSRF 403 still has its security headers.

Every route under an excluded prefix must carry the auth middleware, since the exclusion removes CSRF protection from all of them. Never exclude a prefix whose routes trust the session cookie.

```php
use Zephyrus\Controller\Controller;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Middleware;
use Zephyrus\Routing\Attribute\Post;

#[Middleware('auth')]
class ItemApiController extends Controller
{
    #[Post('/api/items')]
    public function store(): Response
    {
        return Response::json(['created' => true], 201);
    }
}
```

#### Skipping a global middleware on a route

A route can opt out of a global middleware with `#[WithoutMiddleware]`, for instance a public page that needs no session cookie and should stay cacheable. Every global middleware that is an instance of the class is skipped, so a parent class or an interface skips all its implementations. A class-level attribute is inherited by subclasses and merged with the method-level ones:

```php
use Zephyrus\Controller\Controller;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;
use Zephyrus\Routing\Attribute\WithoutMiddleware;
use Zephyrus\Session\SessionMiddleware;

class PricingController extends Controller
{
    #[Get('/pricing')]
    #[WithoutMiddleware(SessionMiddleware::class)]
    public function show(): Response
    {
        return Response::html('<h1>Pricing</h1>');
    }
}
```

The fluent form applies to the route added just before it:

```php
$router = $router
    ->get('/pricing', 'PricingController@show')
    ->withoutMiddleware(SessionMiddleware::class);
```

`withoutMiddleware()` affects only the last route registered, even after `controller()`, `group()`, `resource()` or `discoverControllers()`: to cover a whole controller, put the attribute on the class.

Only a matched route skips: a 404 or 405 still runs every global middleware. Route middlewares named with `#[Middleware]` are not affected. The framework security middlewares (`ForceHttpsMiddleware`, `AllowedHostsMiddleware`, `CsrfMiddleware`, `MaxBodySizeMiddleware`, `SecureHeadersMiddleware`, `ContentSecurityPolicyMiddleware`) cannot be skipped, nor can a parent of one: the route registration throws a `RouteMiddlewareException`. To exempt a path from the CSRF check, use `security.csrf.exceptions`.

Excluding `AuthGuardMiddleware` skips every guard mounted globally: mount a guard that must stay on that route under a name, or in a middleware class of your own. A consumer's wrapper around a security middleware is not recognised, so excluding the wrapper skips the security middleware inside it. A route that skips `SessionMiddleware` has no session: no CSRF-protected form, no flash message, no signed-in user. A `SessionManager` without an active session throws a `SessionException` on a write: `set()` and `regenerate()` always; `remove()` when the key is present or the session was never started; `flash()` when the key is present; and `SessionCsrfTokenManager::getToken()` when it mints a token. With no manager registered, `session()` and the `Flash` writes are ignored instead. So an error page that mints a CSRF token on a path that never reached `SessionMiddleware` fails with that exception.

### Request

The `Request` object is immutable and composed of typed sub-objects:

```php
$request->uri()      // scheme, host, path, query string
$request->body()     // POST/JSON body (RequestBody: get(key), all(), has(key))
$request->headers()  // HeaderBag: get(name), bearerToken(), isJson()
$request->cookies()  // CookieJar: get(name), all()
$request->query      // query string parameters (array)
$request->files      // uploaded files
```

### Response

```php
Response::json(['key' => 'value']);
Response::json($data, 201);
Response::redirect('/login');
Response::localRedirect($request->query['next'] ?? '/'); // a target from the request: local paths only
Response::html('<p>Hello</p>');
Response::text('OK');
```

Responses are immutable: `withHeader()`, `withHeaders()`, `withoutHeader()` and `withStatus()` return new instances.

### Validation

```php
use Zephyrus\Validation\FormValidator;
use Zephyrus\Validation\Rules;

$form = new FormValidator([
    'email' => [Rules::required(), Rules::email()],
    'bio'   => [Rules::maxLength(500)],  // optional, skipped when empty
]);

// In a controller (throws ValidationException → auto 422):
$this->validate($form, $request->body()->all());
```

### Bootstrap

Wire everything together once at startup:

```php
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Core\Config\SessionConfig;
use Zephyrus\Core\KernelBuilder;
use Zephyrus\Http\Request;
use Zephyrus\Routing\Router;
use Zephyrus\Security\CsrfMiddleware;
use Zephyrus\Security\SecureHeadersMiddleware;
use Zephyrus\Session\SessionCsrfTokenManager;
use Zephyrus\Session\SessionManager;
use Zephyrus\Session\SessionMiddleware;

$configuration = Configuration::fromYamlFile(__DIR__ . '/../config.yml');

$router = (new Router())
    ->discoverControllers('App\\Controllers', __DIR__ . '/../app/Controllers');

$session = new SessionManager();
$csrf = new SessionCsrfTokenManager($session);

$kernel = KernelBuilder::create()
    ->withRouter($router)
    ->withMiddleware(new SecureHeadersMiddleware($configuration->security->headers))
    ->withMiddleware(new SessionMiddleware(SessionConfig::fromArray([]), $session))
    ->withMiddleware(new CsrfMiddleware($csrf))
    ->build();

$request  = Request::fromGlobals();
$response = $kernel->handle($request);
$response->send();
```

`CsrfMiddleware` rejects any request other than GET, HEAD, OPTIONS or TRACE that lacks a valid token, sent as a `_csrf_token` body field or an `X-CSRF-Token` header. The token is never injected into your HTML: each form must render the `_csrf_token` field itself. Pass `$csrf` to your views and echo the token escaped:

```php
<input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf->getToken(), ENT_QUOTES, 'UTF-8') ?>">
```

A refused browser form post (an `Accept` header listing `text/html`) gets a plain-text 403 that tells the person to reload the page. Every other client gets a JSON 403. To answer refusals yourself, pass a callback that returns a `Response`, or `null` to keep the default:

```php
use Zephyrus\Http\Response;
use Zephyrus\Security\CsrfConfig;
use Zephyrus\Security\CsrfFailure;

$middleware = new CsrfMiddleware(
    $csrf,
    CsrfConfig::fromSecurityConfig($configuration->security),
    onFailure: static fn (Request $request, CsrfFailure $failure): ?Response => $request->path() === '/login'
        ? Response::redirect('/login?expired=1')
        : null,
);
```

The callback receives attacker-controlled input: it should only answer the refusal, never replay the request or act on the account from it.

### Process model

`App` holds its services (configuration, translator, formatter, session, asset, URL generator, CSP nonce) for the whole process, and `SessionMiddleware` sets the session per request. This assumes one request per process (php-fpm, mod_php), as does the PHP session. Persistent workers (RoadRunner, Swoole, FrankenPHP worker mode) are not supported: `App::reset()` is a testing tool, not a between-requests hook.

---

## Requirements

| Requirement | Version |
|---|---|
| PHP | `^8.4` |
| Extensions | `mbstring`, `pdo`, `intl`, `sodium`, `fileinfo` |

Runtime dependencies: `symfony/yaml`, `vlucas/phpdotenv`, `latte/latte`, `tracy/tracy`, `phpmailer/phpmailer`.

---

## Development

```bash
git clone https://github.com/ophelios-studio/zephyrus-core zephyrus-core
cd zephyrus-core
composer install
```

Run the test suite:

```bash
composer test
```

Run with coverage (requires Xdebug):

```bash
XDEBUG_MODE=coverage php vendor/bin/phpunit --coverage-text
```

A pull request to `dev` must pass the CI checks (commit messages and the PHP test matrix) and `codecov/patch` before it can merge. Codecov requires 90% coverage on each patch, and the project may not drop more than 0.5%. Every change should come with tests.

Commits are one semantic line (`type(scope): description`), with no body and no co-author trailer. Check yours before pushing:

```bash
.github/scripts/check-commit-messages.sh origin/dev..HEAD
```

The pull request title is checked the same way, because GitHub squash-merges with it. Check it locally with `.github/scripts/check-commit-messages.sh --title "feat(scope): description"`.

---

## Documentation

Full documentation (guides for sessions, security, validation, database access, localization, file uploads, events, mailer, and more) is coming soon.

---

## License

MIT, see [LICENSE](LICENSE).
