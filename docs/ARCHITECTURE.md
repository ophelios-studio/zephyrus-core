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

## Implemented Slice: Router registration DSL (Phase 2 seed)
- Added `Routing\Router` fluent registration API with verb helpers (`get`, `post`, `put`, `patch`, `delete`) and generic `add`.
- Added `name(routeName)` helper to name the most recently registered route in fluent chains.
- Added `group(prefix, registrar, middlewares)` helper to scope route registration under shared prefixes and middleware names.
- Added `resource()` helper to register conventional CRUD routes for controller-based modules.
- Router registration preserves immutability, returning a new router instance for each added route.
- Added immutable `RouteCollection::withRoute()` helper plus route-name lookup support (`findByName`).
- Added unit tests for verb helper behavior, route naming, grouped routing, constraints/middleware registration, CRUD resource wiring, and immutable chaining.

## Implemented Slice: Named route URL generation (Phase 2 seed)
- Added `Routing\RouteUrlGenerator` to build paths from named routes and parameter maps.
- Route parameters are required and URL-encoded (`rawurlencode`) for safe path generation.
- Added optional query-string generation with deterministic key ordering.
- Query generation uses RFC3986 encoding and supports array parameters.
- Added optional base-URL support for generating absolute route URLs.
- Added optional signed URL generation (`generateSigned`) when a `RouteSignature` instance is configured.
- Added explicit generation exception paths for unknown route names, missing parameters, and missing URL signer.
- Added unit tests for successful generation, encoding, query generation, absolute URL generation, signed URL generation, and failure scenarios.

## Implemented Slice: Route cache persistence (Phase 2 seed)
- Added `Routing\RouteCache` to persist route collections to JSON cache files and restore them.
- Cached payload includes route data plus metadata (`version`, `routes_hash`) for integrity checks.
- Added strict validation + dedicated `RouteCacheException` for cache read/write/decode/shape/hash failures.
- Added unit tests for cache round-trip, missing cache files, invalid JSON payload handling, and hash mismatch detection.

## Implemented Slice: Middleware pipeline (Phase 2 seed)
- Added `Http\MiddlewareInterface` as the common middleware contract.
- Added immutable `Http\MiddlewarePipeline` with `pipe()` and `handle()` methods.
- Pipeline composition runs middleware in registration order and resolves into a destination handler.
- Added unit tests for middleware ordering and immutable pipeline extension.

## Implemented Slice: HTTP exception responder (Phase 1 seed)
- Added `Http\Error\HttpExceptionResponder` to map framework exceptions into baseline HTTP responses.
- Route misses map to `404 Not Found`, method mismatches map to `405 Method Not Allowed` with `Allow` header.
- Unknown exceptions map to `500 Internal Server Error`.
- Added content negotiation for error format: JSON payloads when request `Accept` includes `application/json` (or problem+json), text otherwise.
- Added unit coverage for exception-to-response mappings and format negotiation.

## Implemented Slice: HttpKernel request lifecycle bridge (Phase 1 seed)
- Added `Core\HttpKernel` to centralize request handling flow.
- Kernel delegates dispatch to `RouteDispatcher` and converts thrown exceptions through `HttpExceptionResponder`.
- Establishes baseline lifecycle: `Request -> Dispatcher -> Response` with framework-level exception mapping.
- Added unit tests for successful dispatch and route-not-found handling through the kernel.

## Implemented Slice: Route dispatcher bridge (Phase 2 seed)
- Added `Routing\RouteDispatcher` to connect request path/method matching to middleware execution.
- Dispatcher resolves a `RouteMatch` from `RouteCollection`, then executes a resolver callable through `MiddlewarePipeline`.
- Added named route middleware support on `Route` definitions (e.g., `['auth', 'audit']`) and middleware resolver binding in dispatcher.
- Dispatcher now hydrates matched route parameters into request attributes before middleware/handler execution.
- Enables first end-to-end flow: `Request -> Route match -> Global middleware -> Route middleware -> Response`.
- Added unit test coverage for parameterized route dispatch, request attribute hydration, and middleware-applied response headers.

## Implemented Slice: Attribute-first route definitions (Phase 2)
- Added `Routing\Attribute\Route` PHP 8 repeatable attribute for decorating controller methods with route metadata (`path`, `method`, `constraints`, `middlewares`, `name`).
- Added `Routing\RouteAttributeReader` to discover all public methods annotated with `#[Route]` on a given class via reflection, returning typed `Route` value objects with handler strings set to `ClassName@methodName`.
- Extended `Router` with a `controller(className)` method that registers all attribute-discovered routes in one call and integrates cleanly with existing fluent chains (`get`, `group`, `resource`, etc.).
- Added `Routing\Exception\RouteAttributeException` for reflection failures on non-existent classes.
- Supports repeatable attributes (one method handles multiple paths/verbs), preserved constraints and middleware names, and named-route lookup after `controller()` registration.
- Added unit tests for attribute discovery (empty, simple, repeatable, protected method filtering, missing class), router integration (combined fluent + controller, named-route lookup, constraint preservation).

## Implemented Slice: Controller base class + HandlerResolver (Phase 2)
- Added `Controller\Controller` abstract base class with protected response-building helpers (`json`, `created`, `text`, `noContent`, `respond`). Controllers are not required to extend it — the resolver works with any plain object returning a `Response`.
- Added `Routing\HandlerResolver` to resolve `ClassName@method` handler strings into `Response` values using PHP reflection-based argument injection:
  - Parameters type-hinted as `Request` receive the current request instance.
  - Parameters whose name matches a hydrated route attribute (e.g. `int $id`) are injected and cast to the declared scalar type (`int`, `float`, `bool`).
  - Parameters with declared default values fall back silently.
  - All other unresolvable parameters throw `HandlerResolverException`.
- An optional factory callable (`(class-string): object`) can be injected into `HandlerResolver` for DI container integration; defaults to `new $class()`.
- Added `Routing\Exception\HandlerResolverException` with named factory methods for invalid format, unresolvable method, and unresolved parameter failure modes.
- `HandlerResolver::resolve(RouteMatch, Request): Response` matches the `$resolver` callable signature expected by `RouteDispatcher`, enabling zero-boilerplate wiring.
- Added `HandlerResolverTest` (14 tests) covering: plain dispatch, Request injection, int/float/string attribute casting, default-value fallback, mixed injection, extended controller helpers, custom factory, invalid format, missing method, unresolved parameter, and full RouteDispatcher integration.
- Added `ControllerTest` (7 tests) covering: json/created/text/noContent/respond helpers and abstract class verification.

## Implemented Slice: HttpKernel end-to-end wiring via KernelBuilder (Phase 1)
- Added `Core\KernelBuilder` fluent builder that assembles a production-ready `HttpKernel` from high-level configuration without requiring callers to understand the internal pipeline construction.
- Builder wires: `Router → RouteCollection → RouteDispatcher (+ MiddlewarePipeline + HandlerResolver) → HttpKernel`.
- `withRouter(Router)` — supplies route definitions (fluent, attribute-based, or resource-style).
- `withMiddleware(MiddlewareInterface)` — appends a global middleware that wraps every request/response pair.
- `registerMiddleware(string, MiddlewareInterface)` — binds a named middleware for route-level dispatch (referenced by name in route definitions).
- `withControllerFactory(callable)` — injects a DI container resolver (`(class-string): object`) used by `HandlerResolver` to instantiate controllers.
- `build()` — assembles and returns an `HttpKernel`; builder is immutable (each `withX` returns a new clone), so `build()` may be called multiple times safely.
- Added `KernelBuilderTest` (15 tests) covering: immutable fluent chain, defaults, empty router yields 404, global middleware, multiple middlewares, named route middleware, controller factory invocation, and multi-call build invariant.
- Added `HttpKernelWiringTest` (20 integration tests, new Integration suite) covering the full end-to-end dispatch path: plain controller dispatch, route parameter injection (int/string/multi), Request injection, mixed injection, POST body, 404/405 error handling, JSON content negotiation, global middleware ordering, named route middleware scoping, global+route middleware combined, attribute-based routes, resource CRUD routes, custom DI factory, grouped routes with shared middleware.
- See `docs/REQUEST_LIFECYCLE.md` for the full annotated request flow.

## Non-goals for v2 core
- Full ORM
- IDS subsystem
- Blockchain protocol logic in core
