# CLAUDE.md — Zephyrus 2 Core Framework

## What Is This

`dadajuice/zephyrus2` is the **core PHP 8.4+ framework library**. It provides routing, HTTP abstraction, middleware, controllers, validation, security, sessions, localization, formatting, database access, templating (Latte), file uploads, events, mailer, and a DI container. It is the foundation that all other Zephyrus ecosystem packages depend on.

**Package:** `dadajuice/zephyrus2` (type: library)
**Branch:** `dev`
**License:** MIT
**PHP:** `^8.4`
**Tests:** ~2154 tests, ~4533 assertions (PHPUnit 11)
**Namespace:** `Zephyrus\`

## Ecosystem

| Repo | Path | Purpose |
|---|---|---|
| **zephyrus2** (this) | `/Users/dtucker/www/zephyrus2` | Core framework library |
| **zephyrus2-framework** | `/Users/dtucker/www/zephyrus2-framework` | Application template (`composer create-project`) |
| **zephyrus-docs** | `/Users/dtucker/www/zephyrus-docs` | Documentation website (29 content pages) |
| **zephyrus-leaf** | `/Users/dtucker/www/zephyrus-leaf` | Static content site template |

## Architecture

### Request Lifecycle

```
Request::fromGlobals()           — immutable Request from superglobals
  → KernelBuilder::build()       — wire Router → Pipeline → HandlerResolver
  → HttpKernel::handle()         — dispatch, run middleware, call controller
  → Response::send()             — emit status line, headers, body
```

### Module Structure (`src/Zephyrus/`)

| Module | Key Classes | Purpose |
|---|---|---|
| `Http/` | `Request`, `Response`, `Uri`, `RequestBody`, `HeaderBag`, `CookieJar`, `MiddlewarePipeline`, `SapiEmitter`, `SseEmitter` | HTTP abstraction with sub-objects |
| `Http/Error/` | `HttpExceptionResponder`, `HttpErrorPayload` | Exception → HTTP response mapping |
| `Routing/` | `Router`, `Route`, `RouteCollection`, `RouteDispatcher`, `HandlerResolver`, `RouteCache`, `RouteUrlGenerator` | Attribute-based routing, dispatching, parameter injection |
| `Routing/Attribute/` | `Get`, `Post`, `Put`, `Patch`, `Delete`, `Head`, `Options`, `Route`, `Middleware`, `MiddlewareGroup` | PHP 8 attribute classes for route definition |
| `Routing/Exception/` | `RouteNotFoundException`, `MethodNotAllowedException`, `RouteParameterException`, `HandlerResolverException`, etc. | Routing error types |
| `Core/` | `Application`, `ApplicationBuilder`, `HttpKernel`, `KernelBuilder`, `Kernel` | App bootstrap and lifecycle |
| `Core/Config/` | `Configuration`, `ConfigSection`, `ApplicationConfig`, `DatabaseConfig`, `SecurityConfig`, `SessionConfig`, `LocalizationConfig` | YAML-based typed configuration |
| `Core/Bootstrap/` | `ApplicationBootstrap` | Full-stack bootstrap helper |
| `Controller/` | `Controller`, `ControllerLifecycleInterface` | Base controller with `before()`/`after()` hooks |
| `Validation/` | `FormValidator`, `Rule`, `Rules`, `ErrorBag`, `ValidationException` | Form validation with tag-based optional detection |
| `Security/` | `CsrfMiddleware`, `AuthGuardMiddleware`, `SecureHeadersMiddleware`, `ForceHttpsMiddleware`, `AllowedHostsMiddleware`, `ContentSecurityPolicy*`, `Cryptography`, auth guards | Security middleware and guards |
| `Session/` | `SessionManager`, `SessionMiddleware`, `SessionCsrfTokenManager` | Session management |
| `Data/` | `Database`, `Broker`, `PaginatedResult`, `FilterRequest` | PDO database layer, pagination |
| `Localization/` | `Translator`, `LocaleResolver`, `JsonLocaleLoader`, `CachedLocaleLoader` | i18n with JSON locale files |
| `Formatting/` | `Formatter` | Number, currency, date, time, duration, filesize formatting (ICU) |
| `Rendering/` | `LatteEngine`, `PhpEngine`, `RenderResponses`, `Asset` | Latte/PHP template rendering |
| `Mailer/` | `Mailer`, `MailerConfig` | Email via PHPMailer |
| `Upload/` | `FileUpload`, `Uploader` | File upload handling |
| `Container/` | `Container`, `ContainerInterface` | Simple DI container |
| `Event/` | `EventDispatcher`, `Event`, `EventSubscriberInterface` | Event system |
| `FileSystem/` | `File`, `Directory` | File/directory abstractions |
| `Exceptions/` | `ZephyrusException`, `ZephyrusRuntimeException` | Base exception classes |

### Request Sub-Objects

The `Request` class composes 4 immutable sub-objects accessed via methods:

```php
$request->uri()        // Uri — scheme(), host(), port(), path(), queryString(), fragment(), isSecure(), baseUrl(), full()
$request->body()       // RequestBody — get(key, default), has(key), all(), isEmpty(), raw()
$request->headers()    // HeaderBag — get(name, default), has(name), all(), bearerToken(), contentType(), isJson()
$request->cookies()    // CookieJar — get(name, default), has(name), all()
```

Flat properties/methods that stay on Request:
- `$request->method`, `$request->query`, `$request->attributes`, `$request->files`, `$request->clientIp`
- `query(key)`, `file(field)`, `filesOf(field)`, `attribute(key)`, `isMethod()`, `clientIp()`, `withAttribute()`, `withAttributes()`

### Routing

Routes use PHP 8 attributes: `#[Get('/')]`, `#[Post('/users')]`, `#[Route('/path', 'GET', name: 'route.name')]`.

Controllers are auto-discovered via `$router->discoverControllers('App\\Controllers', 'app/Controllers/')`.

Route parameters are injected by name into controller methods with type coercion. Type mismatches (e.g. `"abc"` for `int $id`) throw `RouteParameterException` → **404 Not Found**.

### Validation

`FormValidator` accepts `array<string, Rule[]>`. Fields without `Rules::required()` are optional (skipped when null/empty). The `Rule` class has an optional `?string $tag` property; `Rules::required()` is tagged `'required'`.

```php
$form = new FormValidator([
    'email' => [Rules::required(), Rules::email()],
    'name'  => [Rules::required(), Rules::name()],
]);
$this->validate($form, $request->body()->all());
```

### `fromArray()` for Testing

```php
$request = Request::fromArray(
    method: 'POST',
    uri: '/users',
    body: ['name' => 'Alice'],
    query: ['page' => '2'],
    headers: ['content-type' => 'application/json'],
    cookies: ['session' => 'abc'],
    attributes: ['id' => '42'],
    files: [],
    clientIp: '127.0.0.1',
    rawBody: '{"name":"Alice"}',
);
```

## Dependencies

**Runtime:** `symfony/yaml`, `vlucas/phpdotenv`, `latte/latte`, `tracy/tracy`, `phpmailer/phpmailer`
**Extensions:** `mbstring`, `pdo`, `intl`, `sodium`
**Dev:** `phpunit/phpunit ^11.0`

## Commands

```bash
composer test              # Run PHPUnit (2154 tests)
php vendor/bin/phpunit --no-coverage   # Same, without coverage
```

## Conventions

- **Commit format:** `feat: Message` or `fix: Message`
- **No co-author** in commits
- **Tests for every change** — maintain ~98% coverage
- **`final readonly class`** pattern for value objects
- **Immutable Request** — `withAttribute()`/`withAttributes()` return new instances
- **Route attributes:** prefer `#[Get('/')]` over `#[Route('/', 'GET')]`
- **Default locale:** English (`en`)

## File Locations

- Source: `src/Zephyrus/`
- Tests: `tests/Unit/` and `tests/Integration/`
- Test fixtures: `tests/Fixtures/`
- PHPUnit config: `phpunit.xml`
- Example entry point: `public/index.php` (229 lines, living example with controllers/middleware)
- Internal docs: `docs/` (ARCHITECTURE.md, REQUEST_LIFECYCLE.md, ROADMAP.md, etc.)

## Key Design Decisions

1. **Request sub-objects** — `Uri`, `RequestBody`, `HeaderBag`, `CookieJar` are readonly value objects. Query params, files, and attributes stay as flat arrays on Request (not worth sub-object overhead).
2. **RouteParameterException → 404** — URL segment type mismatches return 404, not 500.
3. **FormValidator uses plain Rule arrays** — no `FieldValidator` wrapper. `$tag` property on `Rule` detects required vs optional.
4. **StaticSiteBuilder moved to zephyrus-leaf** — the core framework no longer contains static site generation.
5. **Security is middleware** — CSRF, auth guards, CSP, secure headers, HTTPS enforcement are all middleware, not baked into Request.
6. **ApplicationBuilder has `withExceptionHandler()`** — passthrough to `KernelBuilder` for custom exception handling.
