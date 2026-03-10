# Zephyrus Roadmap

## Phase 0 - Foundations (complete)
- Bootstrap repository, coding standards, CI, PHPUnit, static analysis.
- Define architecture contracts and semantic versioning policy.

## Phase 1 - Core Kernel + HTTP (complete)
- Kernel lifecycle and request/response abstractions.
- Exception and error handling pipeline.
- Request::fromGlobals() with superglobal parsing.
- Localization core seed (Translator, JsonLocaleLoader).
- Upload/FileUpload core seed.
- Remove IDS entirely from v2 core.

## Phase 2 - Routing + Controller (complete)
- Attribute-first route definitions.
- Middleware pipeline (MiddlewareInterface, MiddlewarePipeline).
- Route caching with integrity validation.
- Named route URL generation with signing support.
- Controller base class with lifecycle hooks.
- HandlerResolver with typed argument injection.

## Phase 3 - Typed Configuration (complete)
- Section-based config objects (application, security, session, db, localization).
- Immutable configuration tree with validation and defaults.

## Phase 4 - Forms + Validation (complete)
- Fluent validation API.
- Nested payload handling and precise error pathing.
- Expanded built-in rule set (50+ rules).

## Phase 5 - Data Layer + Security Essentials (complete)
- Broker-first data access model (PostgreSQL primary).
- Transaction boundaries and safe SQL helpers.
- SecureHeadersMiddleware, ForceHttpsMiddleware, CsrfMiddleware.
- Authorization guard system (AllAuthGuard, AnyAuthGuard, PredicateAuthGuard, etc.).

## Phase 6 - Sessions + Auth Guards (complete)
- Session manager with configurable storage.
- Session-backed CsrfTokenManager.
- IP allowlist, header token, request attribute, composite, and predicate guards.
- No legacy IDS module.

## Phase 7 - Bug Fixes + PostgreSQL Migration (complete)
- Fixed 12 bugs across Container, Database, HTTP, Security, Routing, and Data modules.
- Switched database layer from MySQL to PostgreSQL.
- Added identifier quoting for PostgreSQL in SortRequest and FilterRequest.

## Phase 8 - YAML Configuration Overhaul (complete)
- Replaced PHP array config with YAML-only (symfony/yaml).
- Added !env custom tag for environment variable resolution.
- Added ConfigSection base class for user-extensible config.
- Added phpdotenv integration and env() global helper.

## Phase 9 - Template Rendering (complete)
- Added Latte 3.x engine and raw PHP engine.
- RenderResponses trait for Controller.
- Tracy debugging integration.

## Phase 10 - Cryptography (complete)
- XChaCha20-Poly1305 AEAD encryption via libsodium.
- Argon2id password hashing with pepper support.
- BLAKE2b hashing, random generation, key management.

## Phase 11 - Formatter (complete)
- Locale-aware formatting via ext-intl.
- Numeric, temporal, filesize, list, truncate, and custom formatters.

## Phase 12 - FileSystem (complete)
- File and Directory wrapper classes.
- Read, write, copy, move, delete, glob operations.

## Phase 13 - Asset Management + Mailer (complete)
- Cache-busted asset URLs with content hashing.
- PHPMailer wrapper with fluent API and template rendering.

## Phase 14 - Security Hardening (complete)
- Trusted proxy support with CIDR matching.
- X-Forwarded-* header trust gating.

## Phase 15 - Session Middleware (complete)
- SessionMiddleware for automatic session management in request pipeline.

## Phase 16 - Global Helpers (complete)
- App static registry for framework services.
- config(), session(), localize(), i18n(), format(), asset(), embed(), nonce() helpers.

## Phase 17 - Exception Cleanup (complete)
- Typed exceptions with named constructors across all modules.
- Proper exception chaining with $previous parameter support.
- Replaced raw \RuntimeException and \InvalidArgumentException usage.

## Future
- Feature documentation for each module.
- Zephyrus v1 -> v2 migration guide.
- Web3 extension track (separate package).
