# Request Lifecycle

This document describes the full path a request takes from the application entry
point through to the final `Response`, covering every component in the
`HttpKernel → Router → RouteDispatcher → HandlerResolver → Controller` chain.

---

## High-level flow

```
$_SERVER / $_GET / $_POST / $_COOKIE / php://input
        │
        ▼
  Request::fromGlobals()    ← production entry point (parses superglobals)
        │   (or)
  Request::fromArray()      ← test/synthetic entry point
        │
        ▼
  (immutable Request value object)
        │
        ▼
  HttpKernel::handle()      ← single public entry point
   ├─ try
   │    │
   │    ▼
   │  RouteDispatcher::dispatch()
   │    ├─ RouteCollection::match(method, path)
   │    │     ├─ RouteNotFoundException    ─┐
   │    │     └─ MethodNotAllowedException ─┤ bubble up to kernel
   │    │                                   │
   │    ├─ Request::withAttributes(params)  │ hydrate route parameters
   │    │                                   │
   │    ├─ MiddlewarePipeline::handle()     │ global middlewares wrap the chain
   │    │     └─ per-route middlewares      │ injected dynamically per match
   │    │           └─ resolver callable    │ HandlerResolver::resolve()
   │    │                 └─ Controller method invocation
   │    │                       └─ Response ◀─────────────────────────────────
   │    └─ Response ◀── returned up through pipeline layers
   │
   └─ catch (Throwable)
         │
         ▼
   HttpExceptionResponder::toResponse()
         ├─ RouteNotFoundException     → 404 Not Found
         ├─ MethodNotAllowedException  → 405 Method Not Allowed (Allow header set)
         └─ anything else             → 500 Internal Server Error
               └─ content negotiated: JSON when Accept includes application/json
        │
        ▼
  (immutable Response value object)
        │
        ▼
  Response::send()          ← SAPI emission (completes the loop)
        ├─ header("HTTP/1.1 {status} {phrase}", true, $status)
        ├─ header("{Name}: {value}")  ← for each header in the map
        └─ echo $body
```

---

## Components

### `Request`

An immutable value object that carries all input for one HTTP transaction.

| Property      | Description                                              |
|---------------|----------------------------------------------------------|
| `method`      | Normalized uppercase HTTP verb (`GET`, `POST`, …)        |
| `uri`         | Full URI string including scheme, host, and query string |
| `query`       | Parsed query string parameters (`$_GET`)                 |
| `parsedBody`  | Decoded request body (POST fields or JSON payload)       |
| `headers`     | Lowercased header map                                    |
| `cookies`     | Cookie name → value map (`$_COOKIE`)                     |
| `attributes`  | Mutable overlay populated during dispatch                |

Route parameters (e.g. `{id}`) are injected into `attributes` by
`RouteDispatcher` before the middleware pipeline runs — so both middleware
and the final handler see the enriched request.

#### `Request::fromGlobals()`

The production bootstrap factory. It reads PHP superglobals and applies several
normalization steps before constructing the immutable value object:

```php
// public/index.php
$request  = Request::fromGlobals();
$response = $kernel->handle($request);
$response->send();
```

**Superglobal mapping**

| Parameter   | Default source  | Notes                                                      |
|-------------|-----------------|------------------------------------------------------------|
| `$server`   | `$_SERVER`      | Used for method, URI, and header extraction                |
| `$get`      | `$_GET`         | Becomes `query`                                            |
| `$post`     | `$_POST`        | Used for form bodies; ignored for JSON                     |
| `$cookie`   | `$_COOKIE`      | Becomes `cookies`                                          |
| `$rawBody`  | `php://input`   | Injected for JSON decoding; useful to override in tests    |

**Header extraction**

PHP surfaces HTTP headers in `$_SERVER` using the `HTTP_` prefix with
underscores instead of hyphens (e.g. `X-Request-Id` → `HTTP_X_REQUEST_ID`).
`fromGlobals()` strips the prefix, lowercases the name, and restores hyphens.
`CONTENT_TYPE`, `CONTENT_LENGTH`, and `CONTENT_MD5` are extracted without a
prefix and stored under their normalized forms (`content-type`, etc.).

**Body parsing**

| Condition                          | Result                                           |
|------------------------------------|--------------------------------------------------|
| Method is `GET` or `HEAD`          | `parsedBody` is always empty                     |
| Content-Type contains `application/json` | JSON-decoded from `php://input` (or `$rawBody`) |
| Content-Type is form-encoded / multipart | `$_POST` passed through directly             |
| No matching content-type           | `$_POST` used if non-empty                       |

**Method override** (POST-only)

HTML forms can only send `GET` and `POST`. Two override conventions are
supported, in priority order:

1. `X-Http-Method-Override` request header — intended for AJAX clients.
2. `_method` hidden field in the request body — intended for HTML `<form>`.

The override value is uppercased and applied only when the raw method is
`POST`.

---

### `HttpKernel`

The single entry point for request handling. Its only job is:

1. Dispatch `RequestEvent` (when an `EventDispatcher` is attached).
2. Delegate to `RouteDispatcher::dispatch()` unless a request listener
   short-circuits with `$event->setResponse(...)`.
3. Catch any `Throwable` and convert it to a `Response` via
   `HttpExceptionResponder`.
4. Dispatch `ResponseEvent` before returning the final response.

```php
$response = $kernel->handle($request);
```

For class-based listeners, `KernelSubscriber` provides a typed convenience base:

- `onRequest(RequestEvent $event)`
- `onResponse(ResponseEvent $event)`

It auto-registers both hooks via `getSubscribedEvents()` and exposes
`requestPriority()` / `responsePriority()` override points.

---

### `Response::send()`

The final step in the lifecycle: emits the `Response` value object to the SAPI
(web server or CLI). Calling `send()` completes the `fromGlobals → handle → send`
loop:

```php
// public/index.php
$request  = Request::fromGlobals();
$response = $kernel->handle($request);
$response->send();              // ← emits HTTP status line, headers, body
```

**Emission order**

1. **Status line** — `HTTP/1.1 {code} {phrase}` via `header()`, with the
   numeric code passed as the third argument so PHP's SAPI layer records the
   response code correctly.
2. **Headers** — one `header()` call per entry in `Response::$headers`, emitted
   in map-insertion order.
3. **Body** — `echo $body`.

**`headers_sent()` guard**

Header emission is wrapped in `if (!headers_sent())` so that calling `send()`
after output has already started (e.g. a misconfigured entry point) silently
skips header emission rather than triggering PHP warnings. The body is always
echoed regardless.

**SAPI-agnostic inspection helpers**

Three helper methods expose the emission logic as pure return values — no SAPI
calls, fully unit-testable in CLI/PHPUnit:

| Method             | Example return value                                     |
|--------------------|----------------------------------------------------------|
| `statusPhrase()`   | `'OK'`, `'Not Found'`, …                                 |
| `toStatusLine()`   | `'HTTP/1.1 200 OK'`                                      |
| `toHeaderLines()`  | `['Content-Type: application/json; charset=utf-8', …]`  |

`send()` uses `toStatusLine()` and `toHeaderLines()` internally, so testing
these helpers gives complete coverage of what `send()` would emit.
`statusPhrase()` covers all standard HTTP/1.1 codes (100–504); unrecognized
codes return `'Unknown Status'`.

---

### `RouteDispatcher`

Connects route matching to middleware execution.

1. Calls `RouteCollection::match(method, path)` → `RouteMatch`.
2. Hydrates matched path parameters into `$request->attributes`.
3. Resolves per-route middleware names via the registered name resolver.
4. Runs the `MiddlewarePipeline` (global + per-route middlewares in order).
5. Calls the resolver callable `(RouteMatch, Request): Response` as the
   terminal pipeline handler.

---

### `MiddlewarePipeline`

Executes middlewares in registration order, wrapping the terminal handler.

```
global-middleware-1
  └─ global-middleware-2
       └─ route-middleware-auth
            └─ HandlerResolver::resolve()  ← terminal handler
```

Each middleware receives `(Request $request, callable $next): Response`. It may
short-circuit by returning early (e.g. an auth guard returning `401`), or
forward to `$next($request)` and modify the response afterward.

---

### `HandlerResolver`

Translates a `ClassName@method` handler string into a `Response` via
PHP reflection.

**Parameter injection rules (in priority order):**

| Condition                              | Injected value                          |
|----------------------------------------|-----------------------------------------|
| Type-hinted as `Request`               | Current request instance                |
| Parameter name matches a route attribute | `$request->attribute($name)`, cast to declared scalar type (`int`, `float`, `bool`) |
| Parameter has a default value          | Default value (silent fallback)         |
| None of the above                      | Throws `HandlerResolverException`       |

An optional factory callable (`(class-string): object`) may be provided for
DI container integration. Without it, controllers are instantiated with
`new $class()`.

---

### `Controller` (base class, optional)

Abstract base that provides protected response-building helpers so concrete
controllers stay free of boilerplate:

| Method                              | Returns                    |
|-------------------------------------|----------------------------|
| `json(array, status = 200)`         | `application/json` 200     |
| `created(array)`                    | `application/json` 201     |
| `text(string, status = 200)`        | `text/plain` 200           |
| `noContent()`                       | 204 No Content             |
| `respond(array, status)`            | `application/json` custom  |

Controllers are not required to extend `Controller` — `HandlerResolver` works
with any plain object whose methods return a `Response`.

---

## Assembling the kernel with KernelBuilder

`KernelBuilder` wires all of the above so application code never needs to
construct `RouteDispatcher`, `HandlerResolver`, or `MiddlewarePipeline` directly.

```php
use Zephyrus\Core\KernelBuilder;
use Zephyrus\Routing\Router;

$router = (new Router())
    ->get('/health', 'HealthController@show')
    ->controller(UserController::class)       // attribute-based
    ->resource('/posts', PostController::class);

$kernel = KernelBuilder::create()
    ->withRouter($router)
    ->withMiddleware(new CorsMiddleware())
    ->registerMiddleware('auth', new AuthMiddleware($guard))
    ->withControllerFactory(fn (string $class) => $container->get($class))
    ->build();

// In your entry point (public/index.php):
$request  = Request::fromGlobals();
$response = $kernel->handle($request);
$response->send();
```

### Builder immutability

Each `withX` / `registerX` call returns a **new** builder instance, so
configurations can be branched without side-effects:

```php
$base = KernelBuilder::create()->withRouter($router)->withMiddleware($cors);

$web  = $base->registerMiddleware('auth', new SessionAuth())->build();
$api  = $base->registerMiddleware('auth', new TokenAuth())->build();
```

---

## Error response format

`HttpExceptionResponder` performs content negotiation on the request's `Accept`
header:

| Accept contains               | Error format                                    |
|-------------------------------|-------------------------------------------------|
| `application/json` or `problem+json` | `{"error":{"status":N,"message":"..."}}`  |
| anything else                  | Plain text: `Not Found`, `Method Not Allowed`, etc. |
