# Request Lifecycle

This document describes the full path a request takes from the application entry
point through to the final `Response`, covering every component in the
`HttpKernel → Router → RouteDispatcher → HandlerResolver → Controller` chain.

---

## High-level flow

```
$_SERVER / PSR-7 / test array
        │
        ▼
  Request::fromArray()      ← immutable value object
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
```

---

## Components

### `Request`

An immutable value object that carries all input for one HTTP transaction.

| Property      | Description                                    |
|---------------|------------------------------------------------|
| `method`      | Normalized uppercase HTTP verb (`GET`, `POST`) |
| `uri`         | Raw URI string including query string          |
| `query`       | Parsed query string parameters                 |
| `parsedBody`  | Decoded request body (POST/JSON fields)        |
| `headers`     | Lowercased header map                          |
| `attributes`  | Mutable overlay populated during dispatch      |

Route parameters (e.g. `{id}`) are injected into `attributes` by
`RouteDispatcher` before the middleware pipeline runs — so both middleware
and the final handler see the enriched request.

---

### `HttpKernel`

The single entry point for request handling. Its only job is:

1. Delegate to `RouteDispatcher::dispatch()`.
2. Catch any `Throwable` and convert it to a `Response` via
   `HttpExceptionResponder`.

```php
$response = $kernel->handle($request);
```

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
$request  = Request::fromGlobals();   // (planned — currently fromArray for testing)
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
