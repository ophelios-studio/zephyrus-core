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
    - immutable `Response` value object with helpers (`text`, `json`, `noContent`, `redirect`, `withHeader`, `withStatus`); `send()` SAPI emitter with status-line and header emission; `statusPhrase()` and `toStatusLine()` helpers
    - immutable `Request` value object with normalized method/headers and helpers (`query`, `input`, `header`, `cookie`, `path`, `isMethod`, `isJson`, `isSecure`, `attribute`, `withAttribute`, `withAttributes`); `fromGlobals()` production factory with superglobal parsing, header extraction, body negotiation, and method override
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

## Implemented Slice: Request::fromGlobals() superglobal bootstrap (Phase 1)
- Added `Request::fromGlobals()` as the production entry point for building an immutable `Request` from PHP superglobals (`$_SERVER`, `$_GET`, `$_POST`, `$_COOKIE`, `php://input`). All superglobal arrays are injectable as parameters for deterministic, no-mock unit testing.
- Added `cookies` property to `Request` and a `cookie()` accessor. All `withAttribute` / `withAttributes` wither methods preserve `cookies` through immutable copies.
- Header extraction strips the PHP `HTTP_` prefix, converts underscores to hyphens, and lowercases all names. `CONTENT_TYPE`, `CONTENT_LENGTH`, and `CONTENT_MD5` are extracted without the prefix.
- URI constructed from `HTTPS`, `HTTP_HOST` / `SERVER_NAME`, and `REQUEST_URI`; absolute REQUEST_URIs (reverse-proxy scenario) are passed through unchanged.
- Body parsing: GET/HEAD always produce an empty `parsedBody`; `application/json` is JSON-decoded from `php://input`; form content types use `$_POST`.
- Method override applied only for POST: `X-Http-Method-Override` header (highest priority), then `_method` field in parsed body. Override value is uppercased.
- Added `isJson()` and `isSecure()` convenience helpers.
- Added 35 new tests in `RequestTest` covering: URI construction (HTTP/HTTPS, off, fallback, minimal), header extraction (HTTP_ prefix, unprefixed, underscores, case-insensitivity, empty-string skipping), body parsing (JSON with/without charset, empty body, form, multipart, GET/HEAD ignored), method override (field, header, priority, non-POST ignored), cookies, query population, absolute URI pass-through, DELETE/PATCH with JSON body.

## Implemented Slice: Http Response object (Phase 1 seed)
- Added immutable `Http\Response` value object (`status`, `body`, `headers`).
- Added factory constructors for common responses: `text()`, `json()`, and `noContent()`.
- Added immutable mutation helpers: `withHeader()` and `withStatus()`.
- JSON responses automatically set `Content-Type: application/json; charset=utf-8`.

## Implemented Slice: Response::send() SAPI emission (Phase 1)
- Added `Response::send()` to complete the `fromGlobals → handle → send` lifecycle loop.
- `send()` emits the HTTP status line via `header($statusLine, true, $code)`, then each response header via `toHeaderLines()`, then echoes the body.
- Header emission is guarded by `headers_sent()` to avoid PHP warnings when output has already started.
- Added `statusPhrase(): string` — maps the response's status code to its IANA reason phrase (covers 100–504); unknown codes return `'Unknown Status'`.
- Added `toStatusLine(): string` — returns the formatted `HTTP/1.1 {code} {phrase}` string; independently testable without touching the SAPI.
- Added `toHeaderLines(): string[]` — returns each header as a `"Name: value"` string in map-insertion order; `send()` passes these directly to `header()`, and tests can assert on them without a web SAPI context (PHP CLI does not surface custom headers via `headers_list()`).
- Added 30 new tests in `ResponseTest` covering: `withStatus()` and `withHeader()` immutability, `statusPhrase()` for 18 common codes (via data provider) and two unknown fallbacks, `toStatusLine()` for 5 variants, `toHeaderLines()` for empty/single/multiple/ordered cases, `send()` body output (6 cases: plain text, JSON, 204, empty, multiline, binary), and SAPI status-code emission (5 `@RunInSeparateProcess` cases verifying `http_response_code()` for 200/201/404/204/405).

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

## Implemented Slice: Controller lifecycle hooks — before/after (Phase 2)
- New `Controller\ControllerLifecycleInterface` with two hook contracts:
  - `before(Request): ?Response` — pre-dispatch; return a Response to short-circuit, null to continue.
  - `after(Request, Response): Response` — post-dispatch; may decorate or replace the handler's Response.
- `Controller` base class now implements the interface with no-op defaults (before → null, after → identity passthrough).
- `HandlerResolver::resolve()` checks `instanceof ControllerLifecycleInterface` around the handler invocation:
  - Calls `before()`; returns early if non-null (handler method is never invoked).
  - Calls `after()` on the handler's Response before returning.
  - Plain POPOs (no interface) bypass hook logic entirely — zero overhead and no breaking change.
- Typical use cases: auth guards (return 401/403 from `before()`), secure-header decoration, audit logging (`after()`).
- Added 8 unit tests in `ControllerTest`, 6 unit tests in `HandlerResolverTest`, and 5 integration tests in `HttpKernelWiringTest` covering every hook combination (short-circuit, pass-through, decorate, both hooks together/halted).

## Implemented Slice: Response::redirect() + public/index.php bootstrap (Phase 1)
- Added `Response::redirect(string $url, int $status = 302): self` — a redirect-response factory that sets the `Location` header and an empty body. Covers the full 3xx range: 301 Moved Permanently, 302 Found (default), 303 See Other (redirect-after-POST), 307 Temporary Redirect, 308 Permanent Redirect.
- Added `public/index.php` as the canonical framework bootstrap example, demonstrating the complete entry-point loop:
  - `Request::fromGlobals()` builds an immutable `Request` from PHP superglobals.
  - `KernelBuilder::create()->withRouter(…)->withMiddleware(…)->build()` assembles the kernel.
  - `$kernel->handle($request)->send()` dispatches and emits the response.
- The bootstrap example covers: attribute-based routing (`#[Route]` on controller methods), a `before()` auth guard (API-key check), an `after()` hook that stamps security headers, typed route-parameter injection (`int $id`), `Request` injection for POST bodies, redirect-after-POST pattern via `Response::redirect('/users/42', 303)`, and a global `RequestIdMiddleware`.
- Included nginx and Apache web-server configuration hints as comments at the end of `public/index.php`.
- Added `tests/Integration/BootstrapExampleTest.php` (13 integration tests) validating the full bootstrap pattern: health endpoint JSON, per-request unique `X-Request-Id`, 401 without API key, 200 with API key, `X-Frame-Options` header from `after()`, typed route parameter injection, 303 redirect on POST, public article routes, 404/405 error handling, and `Response::redirect()` standalone assertions.
- Added 6 unit tests in `ResponseTest` covering default 302, 301/303/307/308 overrides, absolute URL, and empty body guarantee.
- Http primitives bullet updated: `redirect()` added to the `Response` helper list.

## Implemented Slice: SecurityConfig + Configuration aggregate (Phase 3)
- Added `Core\Config\SecurityConfig` — immutable typed config section for HTTP security behaviour:
  - `forceHttps: bool` (default: `false`) — redirect plain-HTTP requests to HTTPS.
  - `csrfEnabled: bool` (default: `true`) — enable CSRF token verification on mutating requests.
  - `allowedHosts: string[]` (default: `[]`) — restrict accepted `Host` headers; empty = any host.
  - `maxBodySize: int` (default: `2_097_152` = 2 MB; `0` = unlimited) — max request body bytes.
  - Accepts both camelCase and snake_case key variants; camelCase takes precedence when both are supplied.
  - Validation: `maxBodySize < 0` throws; non-string or empty-string entries in `allowedHosts` throw; throws `ConfigurationException` in all cases.
- Added `Core\Config\Configuration` — immutable top-level config tree aggregating all typed sections:
  - Sections: `application: ApplicationConfig`, `session: SessionConfig`, `security: SecurityConfig`, `database: ?DatabaseConfig` (null when absent — DB is optional).
  - `fromArray(array $config): self` — builds the full tree from a single nested array, propagating `ConfigurationException` from any section on invalid values.
  - `defaults(): self` — factory for a fully populated tree using every section's built-in defaults; useful in tests and minimal bootstraps.
  - Callers no longer need to construct section objects individually — one `Configuration::fromArray(require 'config.php')` provides the full typed tree.
- Expanded thin test coverage for `DatabaseConfig` and `SessionConfig`:
  - `DatabaseConfig`: added port boundary tests (1, 65535, 0, 65536), explicit-value round-trip, empty `database`/`username` validation — 10 tests total (was 2).
  - `SessionConfig`: added camelCase/snake_case key acceptance, `sameSite` data provider (Strict/Lax/None), empty-name and negative-lifetime failures — 9 tests total (was 2).
- Added `SecurityConfigTest` (9 tests) and `ConfigurationTest` (14 tests) covering: defaults, camelCase/snake_case keys, precedence, `maxBodySize` zero, `allowedHosts` reindexing, all validation failures, section hydration, null database, all-sections-together, and exception propagation from each section.
- Total test suite: **298 tests, 580 assertions**, line coverage **96.50%** (690/715).

## Implemented Slice: Validation seed — Rule, Rules, FieldValidator, ErrorBag, FormValidator (Phase 4)
- Added `Validation\Rule` — immutable value object wrapping a validator `\Closure` and an error message. Created via `Rule::of(callable, message)`.
- Added `Validation\Rules` — static factory providing 11 built-in rules: `required`, `minLength`, `maxLength`, `email`, `integer`, `numeric`, `min`, `max`, `between`, `regex`, `in`, `url`, `notBlank`. All produce sensible default messages; every message is overridable.
- Added `Validation\FieldValidator` — immutable list of `Rule` objects for a single field. `withRules(Rule...)` constructs; `addRule(Rule)` returns a new copy with the rule appended. `validate(mixed): string[]` runs all rules and returns collected error messages.
- Added `Validation\ErrorBag` — mutable result container mapping field names to `string[]` error lists. Helpers: `hasErrors()`, `hasErrorsFor(field)`, `errorsFor(field)`, `firstFor(field)`, `failingFields()`, `allMessages()`, `toArray()`.
- Added `Validation\FormValidator` — orchestrates named `FieldValidator` instances against a `string => mixed` payload. Missing keys are validated as `null` (required-rule catches absent fields). `withField(name, validator)` returns an immutable copy. `validate(array): ErrorBag` collects all field errors.
- Added 70 unit tests across 5 test classes (`RuleTest` 5, `RulesTest` 32, `FieldValidatorTest` 10, `ErrorBagTest` 12, `FormValidatorTest` 11) covering: pass/fail for every built-in rule, null/empty/type edge cases, default and custom messages, strict `in()` type comparison, immutable `addRule`/`withField` chains, missing-field null treatment, partial and full failures, `between`/`in` integration in full form flow.
- Total test suite: **368 tests, 707 assertions**, line coverage **96.75%** (774/800).

## Implemented Slice: Nested payload validation + expanded rule set (Phase 4)
- Extended `FormValidator` with dot-path field resolution: field names containing `.` (e.g. `user.name`) are resolved as nested array paths against the payload. Missing keys at any depth yield `null`, allowing `required` to catch absent nested fields naturally.
- Added `FormValidator::withNested(string $prefix, FormValidator $sub): self` — merges all fields from a sub-validator under a shared prefix (`address.city`, `address.zip`). Immutable; returns a new clone. Enables composable, reusable sub-validator blocks.
- Added 6 new built-in rules to `Rules` (19 total, up from 13):
  - `boolean()` — accepts `true`, `false`, `1`, `0`, `'1'`, `'0'`, `'true'`, `'false'` (case-insensitive); rejects `'yes'`/`'no'` and other truthy strings.
  - `uuid()` — validates standard 8-4-4-4-12 hex UUID format (any version, case-insensitive).
  - `date(string $format = 'Y-m-d')` — validates date strings against a PHP format string using `DateTime::createFromFormat` with strict overflow checking (e.g. Feb 29 only valid on leap years).
  - `countMin(int $min)` / `countMax(int $max)` — validates that an array has at least/at most N items; non-arrays always fail.
  - `ip()` — validates valid IPv4 or IPv6 addresses via `FILTER_VALIDATE_IP`.
- Added 9 tests in `FormValidatorTest` covering: dot-path field resolution, missing parent/leaf keys, deep three-level nesting, non-array intermediate node, `withNested()` registration/immutability/full-pass/partial-fail.
- Added 28 tests in `RulesTest` covering all six new rules: pass/fail/default-message for every variant.
- Total test suite: **401 tests, 795 assertions**, line coverage **96.94%** (825/851).

## Non-goals for v2 core
- Full ORM
- IDS subsystem
- Blockchain protocol logic in core
