# Architecture Notes (Draft)

## Design Pillars
1. Cohesion first: one obvious way to structure core concerns.
2. Typed APIs: configuration and service contracts are strongly typed.
3. Low hidden state: reduce static/global coupling.
4. Security defaults: practical controls, no legacy IDS complexity.
5. Extension model: core remains minimal, advanced domains live in packages.

## Planned Core Modules
- Core (kernel, app lifecycle, errors)
- Http (request/response, headers, content negotiation)
  - First v2 primitives in place:
    - immutable `Response` value object with helpers (`text`, `json`, `noContent`, `withHeader`, `withStatus`)
    - immutable `Request` value object with normalized method/headers and helpers (`query`, `input`, `header`, `path`, `isMethod`, `attribute`, `withAttribute`, `withAttributes`)
    - middleware contracts and execution pipeline (`MiddlewareInterface`, `MiddlewarePipeline`)
- Routing (attributes, repository, resolver, middleware)
  - Seed primitive in place: immutable `Route` value object (`method`, `path`, `handler`, `constraints`) with normalized definition helpers
- Controller (base class, route hooks)
- Validation (form/value validators + error bag)
- Data (broker contracts, db connection abstractions)
- Security (csrf, auth guard, secure headers)
- Session (stores, rotation, policies)
- Config (typed section objects)

## Implemented Slice: ApplicationConfig (Phase 3 seed)
- Added `Environment` enum with normalized aliases (`prod`, `dev`, `local`, etc.).
- Added immutable `ApplicationConfig` with typed `environment` and `debug` fields.
- Default behavior is secure by default: unknown or missing environment falls back to `production`.
- Debug defaults to off for production-like environments and on for development/testing unless explicitly overridden.

## Implemented Slice: Http Response object (Phase 1 seed)
- Added immutable `Http\Response` value object (`status`, `body`, `headers`).
- Added factory constructors for common responses: `text()`, `json()`, and `noContent()`.
- Added immutable mutation helpers: `withHeader()` and `withStatus()`.
- JSON responses automatically set `Content-Type: application/json; charset=utf-8`.

## Implemented Slice: Routing Route object (Phase 2 seed)
- Added immutable `Routing\Route` primitive with normalized `method` and `path`.
- Added `Route::define()` to standardize route creation from string inputs.
- Added `matchesMethod()` helper for case-insensitive method checks.
- Added unit tests for method normalization, root path handling, and method matching.

## Implemented Slice: Routing RouteCollection matcher (Phase 2 seed)
- Added `Routing\RouteCollection` to register and resolve route definitions.
- Added `match(method, path)` lookup with method/path normalization.
- Added `Routing\RouteMatch` value object to return both matched route and extracted parameters.
- Added parameterized path matching support (`/users/{id}`) with optional per-parameter regex constraints.
- Path normalization now handles trailing slashes, query strings, and URL-decoded segments before matching.
- Matching failures now distinguish between `RouteNotFoundException` and `MethodNotAllowedException` for clearer HTTP-layer handling.
- Added unit tests for successful matches, path normalization, parameter extraction, constraint checks, and miss handling.

## Implemented Slice: Middleware pipeline (Phase 2 seed)
- Added `Http\MiddlewareInterface` as the common middleware contract.
- Added immutable `Http\MiddlewarePipeline` with `pipe()` and `handle()` methods.
- Pipeline composition runs middleware in registration order and resolves into a destination handler.
- Added unit tests for middleware ordering and immutable pipeline extension.

## Implemented Slice: HTTP exception responder (Phase 1 seed)
- Added `Http\Error\HttpExceptionResponder` to map framework exceptions into baseline HTTP responses.
- Route misses map to `404 Not Found`, method mismatches map to `405 Method Not Allowed` with `Allow` header.
- Unknown exceptions map to `500 Internal Server Error`.
- Added unit coverage for all exception-to-response mappings.

## Implemented Slice: Route dispatcher bridge (Phase 2 seed)
- Added `Routing\RouteDispatcher` to connect request path/method matching to middleware execution.
- Dispatcher resolves a `RouteMatch` from `RouteCollection`, then executes a resolver callable through `MiddlewarePipeline`.
- Added named route middleware support on `Route` definitions (e.g., `['auth', 'audit']`) and middleware resolver binding in dispatcher.
- Dispatcher now hydrates matched route parameters into request attributes before middleware/handler execution.
- Enables first end-to-end flow: `Request -> Route match -> Global middleware -> Route middleware -> Response`.
- Added unit test coverage for parameterized route dispatch, request attribute hydration, and middleware-applied response headers.

## Non-goals for v2 core
- Full ORM
- IDS subsystem
- Blockchain protocol logic in core
