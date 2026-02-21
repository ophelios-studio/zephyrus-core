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
    - immutable `Response` value object with helpers (`text`, `json`, `withHeader`, `withStatus`)
    - immutable `Request` value object with normalized method/headers and query/input accessors
- Routing (attributes, repository, resolver, middleware)
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

## Non-goals for v2 core
- Full ORM
- IDS subsystem
- Blockchain protocol logic in core
