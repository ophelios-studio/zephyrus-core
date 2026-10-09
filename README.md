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

Middleware implements `MiddlewareInterface`. For authentication, `AuthGuardMiddleware` answers 401 when its guard refuses the request. `HeaderTokenGuard` checks the bearer token with a constant-time comparison:

```php
use Zephyrus\Security\AuthGuardMiddleware;
use Zephyrus\Security\HeaderTokenGuard;

$apiAuth = new AuthGuardMiddleware(new HeaderTokenGuard($apiToken));
```

Register it under a name with `registerMiddleware()`, then reference that name in route attributes:

```php
$kernel = KernelBuilder::create()
    ->withRouter($router)
    ->registerMiddleware('auth', $apiAuth)
    ->build();
```

```php
#[Middleware('auth')]
#[Get('/account')]
public function account(): Response { ... }
```

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
use Zephyrus\Core\Config\SessionConfig;
use Zephyrus\Core\KernelBuilder;
use Zephyrus\Http\Request;
use Zephyrus\Routing\Router;
use Zephyrus\Security\CsrfMiddleware;
use Zephyrus\Security\SecureHeadersConfig;
use Zephyrus\Security\SecureHeadersMiddleware;
use Zephyrus\Session\SessionCsrfTokenManager;
use Zephyrus\Session\SessionManager;
use Zephyrus\Session\SessionMiddleware;

$router = (new Router())
    ->discoverControllers('App\\Controllers', __DIR__ . '/../app/Controllers');

$session = new SessionManager();

$kernel = KernelBuilder::create()
    ->withRouter($router)
    ->withMiddleware(new SessionMiddleware(SessionConfig::fromArray([]), $session))
    ->withMiddleware(new CsrfMiddleware(new SessionCsrfTokenManager($session)))
    ->withMiddleware(new SecureHeadersMiddleware(SecureHeadersConfig::defaults()))
    ->build();

$request  = Request::fromGlobals();
$response = $kernel->handle($request);
$response->send();
```

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

CI gates coverage on Codecov: each patch needs 90% coverage, and the project may not drop more than 0.5%. Every change should come with tests.

Commits are one semantic line (`type(scope): description`), with no body and no co-author trailer. Check yours before pushing:

```bash
.github/scripts/check-commit-messages.sh origin/dev..HEAD
```

---

## Documentation

Full documentation (guides for sessions, security, validation, database access, localization, file uploads, events, mailer, and more) is coming soon.

---

## License

MIT, see [LICENSE](LICENSE).
