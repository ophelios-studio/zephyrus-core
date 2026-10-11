# Zephyrus

[![CI](https://github.com/ophelios-studio/zephyrus-core/actions/workflows/ci.yml/badge.svg?branch=dev)](https://github.com/ophelios-studio/zephyrus-core/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/ophelios-studio/zephyrus-core/graph/badge.svg)](https://codecov.io/gh/ophelios-studio/zephyrus-core)
[![Packagist](https://img.shields.io/packagist/v/zephyrus-framework/core)](https://packagist.org/packages/zephyrus-framework/core)
[![PHP](https://img.shields.io/packagist/dependency-v/zephyrus-framework/core/php)](https://packagist.org/packages/zephyrus-framework/core)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208-brightgreen)](phpstan.neon.dist)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

A cohesive PHP 8.4+ framework core. Attribute-based routing, immutable HTTP objects, typed configuration, and a full security middleware stack that refuses to boot when a declared protection is not mounted.

---

## Getting Started

Install the core library:

```bash
composer require zephyrus-framework/core
```

This README follows the `dev` branch. Until the next release, install it with `composer require zephyrus-framework/core:dev-dev`.

---

## Overview

### Bootstrap

Wire everything together once, in the front controller:

```php
use Zephyrus\Core\ApplicationBuilder;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Http\Request;
use Zephyrus\Routing\Router;
use Zephyrus\Security\AllowedHostsMiddleware;
use Zephyrus\Security\CsrfConfig;
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

$builder = ApplicationBuilder::create()
    ->withConfiguration($configuration)
    ->withRouter($router)
    ->withMiddleware(new SecureHeadersMiddleware($configuration->security->headers))
    ->withMiddleware(new AllowedHostsMiddleware($configuration->security->allowedHosts))
    ->withMiddleware(new SessionMiddleware($configuration->session, $session))
    ->withMiddleware(new CsrfMiddleware($csrf, CsrfConfig::fromSecurityConfig($configuration->security)));

$application = $builder->build();
$application->handle(Request::fromGlobals())->send();
```

Global middlewares run in registration order, the first registered being the outermost: `SecureHeadersMiddleware` comes first so that a refused request still carries the security headers.

#### Security wiring check

`build()` compares the `security` section of the configuration with the global middlewares. Each declared protection must be enforced by a global middleware built from the declared value:

- `forceHttps: true`: `ForceHttpsMiddleware`
- a non-empty `allowedHosts`: `AllowedHostsMiddleware`
- a `maxBodySize` above 0: `MaxBodySizeMiddleware`, with that limit or a stricter one
- a `headers` mapping: `SecureHeadersMiddleware`
- a declared `csrf` mapping that keeps it enabled: `CsrfMiddleware`, built with `CsrfConfig::fromSecurityConfig()`

Otherwise `build()` throws a `ConfigurationException` that names the middleware to mount.

Only a middleware passed to `withMiddleware()` counts: not one registered under a route name, nor one wrapped in another middleware. The builder is immutable, so keep the instance each call returns. A protection enforced outside the application, by a load balancer or the web server, is acknowledged by name before `build()`:

```php
$builder = $builder->withAcknowledgedSecurityKeys(['forceHttps']);
```

The accepted names are `forceHttps`, `allowedHosts`, `csrf`, `maxBodySize` and `headers`, bare or prefixed with `security.`; any other name throws.

#### Debugger

With `application.debug` on, the Tracy debugger is shown only to a loopback client that did not come through a proxy. Behind Docker the browser arrives from the bridge gateway: allow it as `secret@address`, keep the secret out of the source code, and set the cookie `tracy-debug=<secret>` in that browser. A bare address would admit anyone who can reach the published port.

```php
$debugSecret = getenv('DEBUG_SECRET');
if (is_string($debugSecret) && $debugSecret !== '') {
    $builder = $builder->withDebugClientAllowlist($debugSecret . '@172.17.0.1');
}
```

In a production-like environment (`production`, `prod`, `staging` or `stage`; an unrecognised value reads as `production`), `debug: true` is forced off and an error log line says so. Call `withProductionDebugAcknowledged()` on the builder if debug must stay on there; the client allowlist still applies.

### Configuration

`Configuration::fromYamlFile()` reads the file into typed sections: `$configuration->application`, `session`, `security`, `localization`, and `database`, which is null without a `database:` section. The `!env` tag reads `$_ENV` and the process environment, never `$_SERVER`, so a value set with `SetEnv` or `fastcgi_param` is not seen:

```yaml
application:
  environment: !env APP_ENV, production
  debug: !env APP_DEBUG, false
security:
  allowedHosts: [example.com]
  csrf:
    exceptions: ['#^/api/#']
```

Every built-in section, and the `csrf`, `encryption` and `headers` mappings under `security`, refuses an unknown key and a key written in two spellings (`max_body_size` and `maxBodySize`). At the top level, a key close to a built-in section name (`sesion`) or another spelling of a registered section is refused, and any other key without a factory is ignored. A refusal throws a `ConfigurationException` that says how to fix it:

```text
Configuration key 'sesion' is not a known section: did you mean "session"? A section of your own needs a factory registered under its name in the $sectionFactories argument of the Configuration factories.
```

A section of your own is read by a `ConfigSection` subclass, registered under its name through the `$sectionFactories` argument. The subclass reads the keys it knows and ignores the others:

```yaml
newsletter:
  sender: news@example.com
```

Load the configuration with the factories, in place of the bootstrap's `fromYamlFile()` call, and read the section back with `section()`:

```php
use Zephyrus\Core\Config\ConfigSection;
use Zephyrus\Core\Config\Configuration;

final class NewsletterConfig extends ConfigSection
{
    public readonly string $sender;

    public static function fromArray(array $values): static
    {
        $instance = new static($values);
        $instance->sender = $instance->getString('sender');

        return $instance;
    }
}

$configuration = Configuration::fromYamlFile(__DIR__ . '/../config.yml', [
    'newsletter' => NewsletterConfig::class,
]);
$newsletter = $configuration->section('newsletter'); // a NewsletterConfig
```

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

#### Route paths

A placeholder fills a whole path segment, and a path holds neither `?`, `#` nor an optional placeholder: `/files/{name}.pdf`, `/search?q=1` and `/users/{id?}` throw a `RouteSignatureException` at boot. Declare a second route without the optional segment, and read the query string from the request. A static route wins over a parameterized one, whatever the declaration order.

An unconstrained placeholder accepts any UTF-8 value without `/`. Narrow an identifier segment with `Route::SAFE_SLUG` (ASCII letters, digits, `_` and `-`), as a third argument or as the `constraints` of a route attribute:

```php
use Zephyrus\Routing\Route;

$router = $router->get('/docs/{slug}', 'DocController@show', ['slug' => Route::SAFE_SLUG]);
```

#### Route Prefixing

Use `#[Root]` to apply a URL prefix to an entire controller. It supports inheritance: child controller prefixes are appended to parent prefixes:

```php
use Zephyrus\Controller\Controller;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;
use Zephyrus\Routing\Attribute\Root;

#[Root('/admin')]
class AdminController extends Controller {}

#[Root('/users')]
class AdminUserController extends AdminController
{
    #[Get('/list')]  // resolves to /admin/users/list
    public function list(): Response
    {
        return Response::text('Admin users');
    }
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

Register it globally with `withMiddleware()`, as the bootstrap does, or under a name with `registerMiddleware()`. For authentication, `AuthGuardMiddleware` answers 401 when its guard refuses the request. `HeaderTokenGuard` checks the bearer token with a constant-time comparison. Load the token from the environment, never from source code, and register the middleware under a name on the bootstrap's builder:

```php
use Zephyrus\Security\AuthGuardMiddleware;
use Zephyrus\Security\HeaderTokenGuard;

$apiToken = getenv('API_TOKEN') ?: throw new \RuntimeException('API_TOKEN is not set');
$builder = $builder->registerMiddleware('auth', new AuthGuardMiddleware(new HeaderTokenGuard($apiToken)));
```

Reference that name in route attributes. Mutating API calls authenticate with the bearer token instead of the CSRF token, which is why the configuration above lists the API prefix under `security.csrf.exceptions`. Every route under an excluded prefix must carry the auth middleware, since the exclusion removes CSRF protection from all of them. Never exclude a prefix whose routes trust the session cookie.

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
use Zephyrus\Session\SessionMiddleware;

$router = $router
    ->get('/pricing', 'PricingController@show')
    ->withoutMiddleware(SessionMiddleware::class);
```

`withoutMiddleware()` affects only the last route registered, even after `controller()`, `group()`, `resource()` or `discoverControllers()`. To skip a middleware for a whole batch, pass `excludedMiddlewares` to `group()` or `resource()`:

```php
use Zephyrus\Routing\Router;
use Zephyrus\Session\SessionMiddleware;

$router = $router->group('/public', fn (Router $r) => $r
    ->get('/pricing', 'PricingController@show')
    ->get('/faq', 'FaqController@show'),
    excludedMiddlewares: [SessionMiddleware::class],
);
```

To cover a whole controller, put the attribute on the class.

Only a matched route skips: a 404 or 405 still runs every global middleware. Route middlewares named with `#[Middleware]` are not affected. The framework security middlewares (`ForceHttpsMiddleware`, `AllowedHostsMiddleware`, `CsrfMiddleware`, `MaxBodySizeMiddleware`, `SecureHeadersMiddleware`, `ContentSecurityPolicyMiddleware`) cannot be skipped, nor can a parent of one: the route registration throws a `RouteMiddlewareException`. To exempt a path from the CSRF check, use `security.csrf.exceptions`.

Excluding `AuthGuardMiddleware` skips every guard mounted globally: mount a guard that must stay on that route under a name, or in a middleware class of your own. A wrapper around a security middleware is not recognised, so excluding the wrapper skips the security middleware inside it. A route that skips `SessionMiddleware` has no session: no CSRF-protected form, no flash message, no signed-in user. There, `SessionManager::set()` and `regenerate()` throw a `SessionException`, as does `SessionCsrfTokenManager::getToken()` when it mints a token.

### CSRF

`CsrfMiddleware` rejects any request other than GET, HEAD, OPTIONS or TRACE that lacks a valid token, sent as a `_csrf_token` body field or an `X-CSRF-Token` header. Build its configuration with `CsrfConfig::fromSecurityConfig()`, which reads `security.csrf.enabled` and `security.csrf.exceptions`; a bare `CsrfConfig` ignores them. Each exception is a delimited pattern anchored with `^`, such as `'#^/api/#'`.

The token is never injected into your HTML: each form must render the `_csrf_token` field itself. Pass `$csrf` to your views and echo the token escaped:

```php
<input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf->getToken(), ENT_QUOTES, 'UTF-8') ?>">
```

A refused browser form post (an `Accept` header listing `text/html`) gets a plain-text 403 that tells the person to reload the page. Every other client gets a JSON 403. To answer refusals yourself, give the bootstrap's `CsrfMiddleware` a callback that returns a `Response`, or `null` to keep the default:

```php
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Security\CsrfConfig;
use Zephyrus\Security\CsrfFailure;
use Zephyrus\Security\CsrfMiddleware;

$csrfMiddleware = new CsrfMiddleware(
    $csrf,
    CsrfConfig::fromSecurityConfig($configuration->security),
    onFailure: static fn (Request $request, CsrfFailure $failure): ?Response => $request->path() === '/login'
        ? Response::redirect('/login?expired=1')
        : null,
);
```

The callback receives attacker-controlled input: it should only answer the refusal, never replay the request or act on the account from it.

### Request

The `Request` object is immutable and composed of typed sub-objects:

```php
$request->uri();      // scheme, host, path, query string
$request->body();     // POST/JSON body (RequestBody: get(key), all(), has(key))
$request->headers();  // HeaderBag: get(name), bearerToken(), isJson()
$request->cookies();  // CookieJar: get(name), all()
$request->query;      // query string parameters (array)
$request->files;      // uploaded files
```

### Response

```php
use Zephyrus\Http\Response;

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

// In a controller: throws a ValidationException, answered with a 422
$this->validate($form, $request->body()->all());
```

### Database sessions

`DatabaseSessionHandler` stores sessions in a table. In the bootstrap above, register it after `new SessionManager()` and before `build()`, with a Closure so the `Database` is only built when a session callback first needs it:

```php
use Zephyrus\Data\Database;
use Zephyrus\Session\DatabaseSessionHandler;

$databaseConfig = $configuration->database
    ?? throw new \LogicException('Database sessions need a database: section in config.yml');

$session->setHandler(new DatabaseSessionHandler(fn (): Database => Database::fromConfig($databaseConfig), 'core.session'));
```

`$configuration->database` is a `?DatabaseConfig`, so the example resolves it before registering: a missing `database:` section fails at boot, not at the first request. Return the application's shared `Database` from the Closure instead if it has one. Behind PgBouncer transaction pooling, construct the handler with `lockSessions: false`.

If the Closure throws or returns anything but a `Database`, the callback that needed it throws a `SessionException` (the original error is its previous exception), and so does every later callback of that handler instance: nothing falls back to an empty session. A new session that stays empty is not stored, so a visitor who never writes to the session leaves no row. Under strict mode, which the handler turns on, the next request refuses the id of a session that was never stored, so an anonymous visitor gets a new id and a new `Set-Cookie` on every request until something is stored.

### Process model

`App` holds its services (configuration, translator, formatter, session, asset, URL generator, CSP nonce) for the whole process, and `SessionMiddleware` sets the session per request. This assumes one request per process (php-fpm, mod_php), as does the PHP session. Persistent workers (RoadRunner, Swoole, FrankenPHP worker mode) are not supported: `App::reset()` is a testing tool, not a between-requests hook.

---

## Requirements

| Requirement | Version |
|---|---|
| PHP | `^8.4` |
| Extensions | `mbstring`, `pdo`, `fileinfo`, `intl`, `sodium` |
| Database | `pdo_pgsql`, for a `database:` section (PostgreSQL) |
| Optional | `apcu`, to cache locale catalogs with `CachedLocaleLoader` |

Runtime dependencies: `symfony/yaml`, `vlucas/phpdotenv`, `latte/latte`, `tracy/tracy`, `phpmailer/phpmailer`.

---

## Development

```bash
git clone https://github.com/ophelios-studio/zephyrus-core zephyrus-core
cd zephyrus-core
composer install
```

Run the test suite and the static analysis (PHPStan level 8 on `src/`):

```bash
composer test
composer analyse
```

The PostgreSQL integration tests are skipped unless `ZEPHYRUS_TEST_PGSQL_DSN` holds a PDO DSN with its credentials; CI always runs them. To run them against a throwaway container:

```bash
docker run --rm -d --name zephyrus-pgsql -e POSTGRES_PASSWORD=postgres -p 55432:5432 postgres:16
until docker exec zephyrus-pgsql pg_isready -h 127.0.0.1 -U postgres; do sleep 1; done
ZEPHYRUS_TEST_PGSQL_DSN='pgsql:host=127.0.0.1;port=55432;dbname=postgres;user=postgres;password=postgres' composer test
docker stop zephyrus-pgsql
```

Run with coverage (requires Xdebug):

```bash
XDEBUG_MODE=coverage php vendor/bin/phpunit --coverage-text
```

A pull request to `dev` must pass the CI checks (commit messages, and the PHP matrix running the dependency audit, the static analysis and the tests) and `codecov/patch` before it can merge. Codecov requires 90% coverage on each patch, and the project may not drop more than 0.5%. Every change should come with tests.

Commits are one semantic line (`type(scope): description`), with no body and no co-author trailer. Check yours before pushing:

```bash
.github/scripts/check-commit-messages.sh origin/dev..HEAD
```

The pull request title is checked the same way, because GitHub squash-merges with it. Check it locally with `.github/scripts/check-commit-messages.sh --title "feat(scope): description"`.

---

## Documentation

Release notes, with each breaking change and the change to make in your code, are in the [changelog](CHANGELOG.md). Full documentation (guides for sessions, security, validation, database access, localization, file uploads, events, mailer, and more) is coming soon.

---

## License

MIT, see [LICENSE](LICENSE).
