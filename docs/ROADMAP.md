# Zephyrus Roadmap

## Phase 0 - Foundations
- Bootstrap repository, coding standards, CI, PHPUnit, static analysis.
- Define architecture contracts and semantic versioning policy.

## Phase 1 - Core Kernel + HTTP
- Kernel lifecycle and request/response abstractions.
- Exception and error handling pipeline.
- Remove IDS entirely from v2 core.

## Phase 2 - Routing + Controller
- Attribute-first route definitions.
- Middleware pipeline.
- Typed argument resolution and constraints.

## Phase 3 - Typed Configuration
- Section-based config objects (application, security, session, db, etc.).
- Immutable configuration tree with validation and defaults.

## Phase 4 - Forms + Validation
- Fluent validation API.
- Nested payload handling and precise error pathing.
- Expanded built-in rule set.

## Phase 5 - Data Layer
- Broker-first data access model.
- Transaction boundaries and safe SQL helpers.
- Optional composable query helpers.

## Phase 6 - Sessions + Security Essentials
- Session manager redesign.
- CSRF, authorization guard, secure headers.
- No legacy IDS module.

## Phase 7 - Docs + Migration
- Feature docs for each module.
- Zephyrus1 -> Zephyrus migration guide.
- CodeQuill migration checklist and compatibility bridge.

## Phase 8 - Web3 Extension Track
- Keep blockchain capabilities as extensions first.
- `zephyrus2/web3-core`, `zephyrus2/web3-eth`, and later CodeQuill bridge.
