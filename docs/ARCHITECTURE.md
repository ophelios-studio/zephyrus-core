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
- Unknown named route middleware now throws `Routing\Exception\RouteMiddlewareException` for a typed failure path.
- Added named route middleware support on `Route` definitions (e.g., `['auth', 'audit']`) and middleware resolver binding in dispatcher.
- Added reusable router middleware groups via `Router::middlewareGroup(name, [...])`, including nested group expansion at registration-time and typed circular-reference failure via `RouteMiddlewareException`.
- Integration wiring now covers middleware-group aliases across grouped routes to verify end-to-end expansion + named middleware resolution in `KernelBuilder` dispatch.
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
- Added `Validation\ValidationException` — typed exception carrying the originating `ErrorBag` for fail-fast validation flows.
- Added `Validation\FormValidator` — orchestrates named `FieldValidator` instances against a `string => mixed` payload. Missing keys are validated as `null` (required-rule catches absent fields). `withField(name, validator)` returns an immutable copy. `validate(array): ErrorBag` collects all field errors, and `validateOrFail(array): ErrorBag` throws `ValidationException` when any field fails.
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

## Implemented Slice: Data module seed — Database, Broker, DatabaseException (Phase 5)
- Added `Data\Database` — thin PDO wrapper providing a clean construction path from `DatabaseConfig` or an injected PDO, uniform `DatabaseException` wrapping for all PDO failures, a `transaction()` helper that commits on success and rolls back on throws (with nested-transaction reuse), and a `query()` helper that prepares + binds params returning a `PDOStatement`.
- Added `Data\Broker` — abstract base for domain-specific data brokers with protected query helpers: `select()` (fetch all rows), `selectOne()` (first row or null), `selectCount()` (scalar aggregate cast to int), `execute()` (INSERT/UPDATE/DELETE row count), `lastInsertId()`, and `transaction()` delegation. Encapsulates all SQL inside subclasses; accepts and returns plain arrays.
- Added `Data\DatabaseException` — typed `ZephyrusRuntimeException` subclass with named factories: `connectionFailed(dsn, reason)`, `queryFailed(sql, reason)`, `transactionFailed(reason)`, `fromPdoException(PDOException, context)`.
- Added 32 unit tests across 3 test classes (`DatabaseTest` 13, `BrokerTest` 13, `DatabaseExceptionTest` 6) using an in-memory SQLite PDO — no real DB required.

## Implemented Slice: Security module seed — SecureHeadersConfig + SecureHeadersMiddleware (Phase 5)
- Added `Security\SecureHeadersConfig` — immutable config for HTTP security response headers, following current OWASP guidance:
  - `xFrameOptions` (default `'SAMEORIGIN'`), `xContentTypeOptions` (default `'nosniff'`), `referrerPolicy` (default `'strict-origin-when-cross-origin'`), `xssProtection` (default `'0'` — disables the legacy XSS Auditor which modern browsers no longer use).
  - `hstsMaxAge: int` (default `0` — disabled; set to e.g. `31_536_000` to enable), `hstsIncludeSubdomains: bool`.
  - `csp: string` (default `''` — no CSP emitted by default; every app needs its own policy), `permissionsPolicy: string` (default `''`).
  - An empty string value for any header disables that header entirely (useful to opt-out of specific defaults).
  - `fromArray()` accepts camelCase and snake_case key variants; camelCase takes precedence. `defaults()` factory provides the full recommended baseline.
  - `hstsHeaderValue(): string` returns the formatted HSTS header value or empty string when disabled.
- Added `Security\SecureHeadersMiddleware` — implements `MiddlewareInterface`; calls `$next`, then appends configured headers via `Response::withHeader()` (immutable). HSTS is only emitted when `$request->isSecure()` returns true (HTTPS URI). Headers with empty string config values are skipped.
- Added 34 unit tests across 2 test classes (`SecureHeadersConfigTest` 16, `SecureHeadersMiddlewareTest` 18) covering: all default values, camelCase/snake_case key handling, precedence, empty-string opt-out, HSTS header value formatting (enabled/disabled/includeSubDomains), HSTS gating on HTTP vs HTTPS, CSP/Permissions-Policy conditional emission, individual header opt-out, response immutability, and a full all-headers-together HTTPS snapshot.
- Total test suite: **467 tests, 906 assertions**, line coverage **95.25%** (922/968), Security module at **100%** line and method coverage.

## Implemented Slice: ForceHttpsMiddleware + CsrfMiddleware (Phase 5 Security continuation)

### ForceHttpsMiddleware
- Added `Security\ForceHttpsMiddleware` — implements `MiddlewareInterface`; short-circuits on plain-HTTP requests, returning a **308 Permanent Redirect** to the HTTPS equivalent URL. 308 is used (not 301) so the original HTTP method and body are preserved across the redirect.
- HTTPS requests pass straight through to the next middleware with zero overhead.
- Port rewriting: default HTTP port `:80` is stripped from the HTTPS URL so the redirect is clean (e.g. `http://host:80/path` → `https://host/path`). Non-standard ports are preserved (useful for local dev on `:8080`).
- Recommended placement: first in the global middleware pipeline, before auth, CSRF, or any other gate middleware.
- Added 9 unit tests in `ForceHttpsMiddlewareTest` covering: secure pass-through (GET/POST), HTTP→HTTPS redirect with path/query preservation, port-80 stripping, non-standard port preservation, 308 on DELETE, inner-handler-not-called verification.

### CsrfTokenManagerInterface
- Added `Security\CsrfTokenManagerInterface` — two-method contract: `getToken(): string` (returns the current session token) and `isTokenValid(string $submitted): bool` (constant-time comparison). Encourages timing-safe implementations via `hash_equals()`.
- Designed for inversion of control: the Phase 6 session-backed implementation will satisfy this interface without changing the middleware.

### CsrfMiddleware
- Added `Security\CsrfMiddleware` — implements `MiddlewareInterface`; enforces synchronizer-token CSRF protection on all state-changing requests.
- **Safe methods** (GET, HEAD, OPTIONS, TRACE) pass through without token check.
- **State-changing methods** (POST, PUT, PATCH, DELETE) must supply a valid CSRF token or the middleware returns `403 Forbidden` with a JSON error body without calling the inner handler.
- **Token lookup order** (first match wins): request body field (default `_csrf_token`) → request header (default `X-CSRF-Token`). Both sources are checked so HTML forms and AJAX/fetch clients work with the same token.
- Body field name and header name are injectable at construction time (`bodyField`, `headerName`) for framework interoperability (e.g. `_token` / `X-XSRF-TOKEN`).
- Added 18 unit tests in `CsrfMiddlewareTest` covering: all four safe methods pass-through, all four state-changing methods rejected without token, valid token via body field (POST/PUT), valid token via header (POST/DELETE), invalid token in body/header returns 403, body-field takes precedence over header, custom body/header names, JSON 403 body shape, inner-handler-not-called on rejection, manager `getToken()` surface.

### Totals after this slice
- Total test suite: **495 tests, 942 assertions**, line coverage TBD (run with `XDEBUG_MODE=coverage`).
- Security module: `SecureHeadersConfig`, `SecureHeadersMiddleware`, `ForceHttpsMiddleware`, `CsrfTokenManagerInterface`, `CsrfMiddleware`.

## Implemented Slice: Route Name Propagation Through Groups (Phase 10)

### Problem fixed
Previously `Router::group()` silently discarded route names. Any route named via `->name()` or `#[Route(name:)]` inside a group registrar had its name lost when routes were merged back into the outer collection.

### Changes

#### `Router::add()` — optional `name` parameter
- Added `?string $name = null` as a named optional parameter.
- Forwards the name to `Route::define()` so internal paths that re-add routes can carry names through.
- Public API: callers may now pass an explicit `name:` to `add()` if they want to bypass the `->name()` chaining pattern.

#### `Router::group()` — name propagation + `namePrefix`
- Added `?string $namePrefix = null` optional named parameter.
- For each route merged from the scoped registrar, its `$route->name` is now forwarded to `add()`:
  - Named route + no prefix → name preserved verbatim.
  - Named route + prefix → `$namePrefix . $routeName` (e.g. `'api.' . 'users.index'` = `'api.users.index'`).
  - Unnamed route + prefix → name stays `null` (the prefix is never applied to unnamed routes).

### Usage examples

```php
// Preserve names through a group
$router->group('/api/v1', fn ($r) => $r
    ->get('/users', 'UserController@index')->name('users.index'));
// findByName('users.index') → '/api/v1/users'

// Auto-prefix all named routes in a group
$router->group('/api/v1', fn ($r) => $r
    ->get('/users', 'UserController@index')->name('users.index')
    ->get('/posts', 'PostController@index')->name('posts.index'),
    namePrefix: 'api.',
);
// findByName('api.users.index') + findByName('api.posts.index')
```

### Tests added (7 new)
- `testGroupPreservesRouteNameDefinedInsideGroup` — single named route preserved after prefix join.
- `testGroupPreservesMultipleNamesDefinedInsideGroup` — multiple named routes preserved.
- `testGroupLeavesUnnamedRoutesWithNullName` — unnamed routes stay null.
- `testGroupWithNamePrefixPrependsToNamedRoutes` — prefix is prepended.
- `testGroupWithNamePrefixDoesNotNameUnnamedRoutes` — prefix not applied to null names.
- `testGroupWithNamePrefixAndSharedMiddlewaresCombineCorrectly` — prefix + shared middlewares work together.
- `testAddAcceptsExplicitNameParameter` — `add()` now accepts `name:` directly.

### Totals after this slice
- Total test suite: **621 tests, 1131 assertions**, all green.

## Implemented Slice: PHPUnit 12 readiness cleanup (test metadata attributes)
- Migrated remaining PHPUnit doc-comment metadata usage to native attributes.
- `SessionConfigTest::testAcceptsValidSameSiteValues` now uses `#[DataProvider('sameSiteProvider')]`.
- This removes the last PHPUnit 11 test-runner deprecation and keeps the suite compatible with PHPUnit 12's metadata model.

## Implemented Slice: RouteCache hardening on payload/hash encoding paths
- Hardened `Routing\RouteCache` around JSON encoding edge cases where invalid byte sequences can make route payload hashing fail.
- `save()` now catches hash-computation `JsonException` and rethrows a typed `RouteCacheException('Unable to encode route cache payload')`.
- `load()` now catches hash-validation `JsonException` and rethrows a typed `RouteCacheException('Unable to validate route cache payload hash')`.
- Added `RouteCacheTest::testSaveThrowsOnUnencodableRoutePayload` to cover invalid UTF-8 route payload handling.
- Result: cache-encoding failures now consistently surface as framework-typed route-cache exceptions instead of raw JSON exceptions.

## Implemented Slice: RouteUrlGenerator edge-path coverage hardening
- Added explicit coverage for `generateTemporarySignedUntil()` failure path when no `RouteSignature` is configured.
- Added fragment-normalization edge case coverage where fragment is only `"#"` (URL remains unchanged, no trailing hash).
- Result: signing-path precondition behavior is now fully asserted across both temporary-signing entry points.

## Implemented Slice: Validation utility-class contract assertion
- Added explicit unit coverage that `Validation\Rules` keeps a private constructor as a static utility class contract.
- Constructor path is now executed through reflection in tests to enforce non-instantiable utility semantics.
- Prevents accidental public instantiation regressions while preserving the static factory-only API surface.

## Implemented Slice: RouteCache filesystem failure-path hardening
- Added explicit tests for RouteCache write-path operational failures:
  - directory creation failure when cache path parent cannot be created (`/dev/null/...`)
  - file write failure when cache target is a directory path
- Result: filesystem edge failures are now asserted as typed `RouteCacheException` paths instead of silent environmental assumptions.

## Implemented Slice: RouteCache cache-path observability helper
- Added `RouteCache::filePath(): string` to expose the resolved cache file location for diagnostics and tooling.
- Added unit coverage to assert the configured path is returned exactly.
- Improves debuggability in kernel/runtime status reporting without changing cache read/write semantics.

## Implemented Slice: RouteCache warm-up convenience API
- Added `RouteCache::warm(RouteCollection): array` convenience method to save routes and return generated metadata in one call.
- Warm-up now supports orchestration code that needs immediate cache metadata (`routes_hash`, `route_count`, `generated_at`) without a second explicit metadata read step.
- Added unit coverage validating that warm-up returns complete metadata and materializes the cache file.

## Implemented Slice: RouteCache stale-aware warm-up orchestration
- Added `RouteCache::warmIfStale(RouteCollection, int $maxAgeSeconds, ?int $now = null): bool`.
- It writes cache only when stale, and returns whether a new write occurred (`true` when warmed, `false` when current cache is already fresh).
- Includes explicit guard for negative max-age input using typed `RouteCacheException`.
- Added unit coverage for: missing-cache warm, fresh-cache no-op, and negative-age failure path.

## Implemented Slice: Database transaction-state observability helper
- Added `Data\Database::inTransaction(): bool` to expose live transaction state from the underlying PDO connection.
- Enables service-layer and broker diagnostics to assert transactional context without reaching into raw PDO.
- Added unit coverage ensuring state transitions are correct before, during, and after `Database::transaction()` execution.

## Implemented Slice: RouteCache metadata route-count accessor
- Added `RouteCache::routeCount(): ?int` to expose cached route cardinality from validated metadata.
- Returns `null` when metadata is unavailable/invalid (mirrors `generatedAt()` and `metadata()` semantics).
- Added unit coverage for both missing-metadata and post-save route-count cases.

## Implemented Slice: RouteCache metadata hash accessor
- Added `RouteCache::routesHash(): ?string` to expose the currently cached route-payload digest from validated metadata.
- Returns `null` when metadata is unavailable/invalid, matching the existing nullable metadata accessor pattern.
- Added unit coverage for both missing-metadata and post-save hash-read cases (SHA-256 format asserted).

## Implemented Slice: RouteCache metadata-version accessor
- Added `RouteCache::metadataVersion(): ?int` to expose the validated cache metadata schema version.
- Returns `null` when metadata is unavailable/invalid, aligned with existing nullable metadata accessors.
- Added unit coverage for missing-metadata and post-save version-read scenarios.

## Implemented Slice: Validation IP rule granularity (IPv4 / IPv6)
- Added `Validation\Rules::ipv4()` and `Validation\Rules::ipv6()` for address-family-specific validation.
- Keeps existing `Rules::ip()` for dual-stack acceptance while enabling explicit API contracts where only one family is allowed.
- Added unit coverage for pass/fail/default-message paths across both new rules.

## Implemented Slice: Validation hostname rule
- Added `Validation\Rules::hostname()` for DNS hostname validation (label boundaries, character set, and host-length constraints).
- Supports common hostname forms including multi-label service names and punycode ASCII labels.
- Added unit coverage for valid/invalid/default-message scenarios.

## Implemented Slice: Validation CIDR rule
- Added `Validation\Rules::cidr()` for network-range validation in CIDR notation.
- Supports both IPv4 and IPv6 CIDR ranges with proper prefix bounds (`0..32` for IPv4, `0..128` for IPv6).
- Added unit coverage for valid ranges, malformed inputs, and out-of-range prefixes.

## Implemented Slice: Validation MAC address rule
- Added `Validation\Rules::macAddress()` to validate hardware address strings using PHP's MAC validation filter.
- Supports common colon-separated and hyphen-separated MAC formats.
- Added unit coverage for pass/fail/default-message paths.

## Implemented Slice: Validation port-number rule
- Added `Validation\Rules::port()` for TCP/UDP port validation.
- Accepts integer and digit-string inputs in the valid range `1..65535`.
- Rejects zero, negatives, out-of-range values, and non-numeric inputs.
- Added unit coverage for pass/fail/default-message scenarios.

## Implemented Slice: Validation host rule (hostname or IP)
- Added `Validation\Rules::host()` to validate endpoint host values that can be either DNS hostnames or literal IPs.
- Composes existing `hostname()` and `ip()` rules for a single expressive API contract in config and request validation.
- Added unit coverage for hostname pass, IPv4/IPv6 pass, invalid input failures, and default message path.

## Implemented Slice: Validation host:port endpoint rule
- Added `Validation\Rules::hostPort()` for endpoint values combining host and TCP/UDP port.
- Supports hostname/IPv4 in `host:port` form and IPv6 in `[ipv6]:port` form.
- Composes existing `host()`, `ipv6()`, and `port()` validation primitives.
- Added unit coverage for valid endpoints and malformed/invalid edge cases.

## Implemented Slice: Validation port-range rule
- Added `Validation\Rules::portRange()` for inclusive port-range strings in `start-end` format.
- Reuses existing `port()` rule for each endpoint and enforces `start <= end`.
- Added unit coverage for valid ranges, missing delimiter, invalid endpoints, reversed ranges, and default message path.

## Implemented Slice: Validation JSON-string rule
- Added `Validation\Rules::json()` for validating JSON-encoded string inputs.
- Accepts object/array/scalar JSON payloads (including `null`) and rejects empty/non-string/invalid JSON input.
- Added unit coverage for pass/fail/default-message scenarios.

## Implemented Slice: Validation slug rule
- Added `Validation\Rules::slug()` for URL/content slugs using lowercase alphanumerics with single hyphen separators.
- Enforces a strict shape suitable for route segments and identifiers (no leading/trailing hyphen, no repeated separators).
- Added unit coverage for pass/fail/default-message scenarios.

## Implemented Slice: Validation hex-color rule
- Added `Validation\Rules::hexColor()` for CSS-style hex color values.
- Supports `#RGB` and `#RRGGBB` forms (case-insensitive hex digits).
- Added unit coverage for pass/fail/default-message scenarios.

## Implemented Slice: Validation Base64 rule
- Added `Validation\Rules::base64()` for strict Base64-encoded string validation.
- Uses strict decode mode and round-trip encode checks to reject malformed or non-canonical inputs.
- Added unit coverage for pass/fail/default-message scenarios.

## Implemented Slice: Validation semantic-version rule
- Added `Validation\Rules::semver()` for SemVer 2.0 compliant version strings.
- Supports core `MAJOR.MINOR.PATCH` plus optional pre-release and build metadata segments.
- Added unit coverage for valid/invalid/default-message scenarios.

## Implemented Slice: Validation ULID rule
- Added `Validation\Rules::ulid()` for 26-character Crockford Base32 ULID identifiers.
- Enforces ULID alphabet restrictions and fixed-length shape.
- Added unit coverage for valid/invalid/default-message scenarios.

## Implemented Slice: Validation SHA-256 digest rule
- Added `Validation\Rules::sha256()` for lowercase hex SHA-256 digests.
- Enforces exact 64-character lowercase hexadecimal shape.
- Added unit coverage for valid/invalid/default-message scenarios.

## Implemented Slice: Validation HTTP-path rule
- Added `Validation\Rules::httpPath()` for request-path-like values.
- Requires a non-empty string starting with `/` and rejects whitespace-containing paths.
- Added unit coverage for pass/fail/default-message scenarios.

## Implemented Slice: Validation HTTP ETag rule
- Added `Validation\Rules::etag()` for HTTP ETag token validation per RFC 7232.
- Accepts strong ETags (`"abc"`) and weak ETags (`W/"abc"`); the opaque tag may be empty or any sequence of visible ASCII characters except `"`.
- Rejects bare values (no quotes), lowercase weak prefix (`w/`), embedded quotes, and trailing whitespace.
- Added unit coverage for strong pass, weak pass, malformed input failures, and default message path.

## Implemented Slice: Validation If-None-Match header rule
- Added `Validation\Rules::ifNoneMatch()` for conditional request header values.
- Accepts wildcard `*` or comma-separated lists of valid ETags (strong and weak forms).
- Rejects malformed list structure (empty items, trailing commas, invalid tokens) and non-string input.
- Added focused unit coverage for wildcard/list pass cases, malformed failures, and default message.

## Implemented Slice: Validation If-Match header rule
- Added `Validation\Rules::ifMatch()` for optimistic concurrency / precondition request handling.
- Accepts wildcard `*` or comma-separated lists of valid ETags (strong and weak forms).
- Rejects malformed list structure (empty items, trailing commas, invalid tokens) and non-string input.
- Added focused unit coverage for wildcard/list pass cases, malformed failures, and default message.

## Non-goals for v2 core
- Full ORM
- IDS subsystem
- Blockchain protocol logic in core
