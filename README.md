# Zephyrus

[![CI](https://github.com/zephyrus-framework/core/actions/workflows/ci.yml/badge.svg?branch=dev)](https://github.com/zephyrus-framework/core/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/zephyrus-framework/core/graph/badge.svg)](https://codecov.io/gh/zephyrus-framework/core)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

A cohesive PHP 8.4+ framework core. Attribute-based routing, immutable HTTP objects, typed configuration, and a full security middleware stack — with ~98% test coverage throughout.

---

## Getting Started

The fastest way to start a new project is the official application template:

```bash
composer create-project zephyrus-framework/core-framework my-app
cd my-app
composer dev
```

This gives you a working application structure with controllers, views, config, and a dev server ready to go.

To use the core library directly in an existing project:

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

Use `#[Root]` to apply a URL prefix to an entire controller. It supports inheritance — child controller prefixes are appended to parent prefixes:

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
$router->discoverControllers('App\\Controllers', 'app/Controllers/');
```

### Middleware

Implement `MiddlewareInterface` and register globally or on specific routes:

```php
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

class AuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        if ($request->headers()->bearerToken() === null) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }
        return $next($request);
    }
}
```

Register globally on the kernel, or as a named middleware for use in route attributes:

```php
#[Middleware('auth')]
#[Get('/account')]
public function account(): Response { ... }
```

### Request

The `Request` object is immutable and composed of typed sub-objects:

```php
$request->uri()      // scheme, host, path, query string
$request->body()     // POST/JSON body — get(key), all(), has(key)
$request->headers()  // HeaderBag — get(name), bearerToken(), isJson()
$request->cookies()  // CookieJar — get(name), all()
$request->query      // query string parameters (array)
$request->files      // uploaded files
```

### Response

```php
Response::json(['key' => 'value']);
Response::json($data, 201);
Response::redirect('/login');
Response::html('<p>Hello</p>');
Response::plain('OK');
```

Responses are immutable — `withHeader()`, `withStatus()`, and `withBody()` return new instances.

### Validation

```php
use Zephyrus\Validation\FormValidator;
use Zephyrus\Validation\Rules;

$form = new FormValidator([
    'email' => [Rules::required(), Rules::email()],
    'name'  => [Rules::required(), Rules::name()],
    'bio'   => [Rules::maxLength(500)],  // optional — skipped when empty
]);

// In a controller (throws ValidationException → auto 422):
$this->validate($form, $request->body()->all());
```

### Bootstrap

Wire everything together once at startup:

```php
use Zephyrus\Core\KernelBuilder;
use Zephyrus\Http\Request;
use Zephyrus\Routing\Router;

$router = (new Router())
    ->discoverControllers('App\\Controllers', 'app/Controllers/');

$kernel = KernelBuilder::create()
    ->withRouter($router)
    ->withMiddleware(new CsrfMiddleware())
    ->withMiddleware(new SecureHeadersMiddleware())
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
| Extensions | `mbstring`, `pdo`, `intl`, `sodium` |

Runtime dependencies: `symfony/yaml`, `vlucas/phpdotenv`, `latte/latte`, `tracy/tracy`, `phpmailer/phpmailer`.

---

## Development

```bash
git clone https://github.com/zephyrus-framework/core zephyrus-core
cd zephyrus-core
composer install
```

Run the test suite:

```bash
composer test
# or without coverage instrumentation:
php vendor/bin/phpunit --no-coverage
```

Run with coverage (requires Xdebug):

```bash
XDEBUG_MODE=coverage php vendor/bin/phpunit --coverage-text
```

The project targets ~98% coverage. Every change should come with tests.

---

## Documentation

Full documentation — including guides for sessions, security, validation, database access, localization, file uploads, events, mailer, and more — is available on the docs site (coming soon).

---

## License

MIT — see [LICENSE](LICENSE).
