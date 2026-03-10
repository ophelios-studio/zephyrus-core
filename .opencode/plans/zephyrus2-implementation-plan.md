# Zephyrus v2 -- Full Implementation Plan

## Overview

This plan covers all remaining work to bring Zephyrus v2 to a usable framework state. It includes bug fixes in the existing code, new modules (rendering, crypto, formatter, filesystem, assets, mailer), and infrastructure changes (YAML config, .env loading, Tracy debugging).

## Dependencies to Add

```json
{
  "require": {
    "php": "^8.4",
    "latte/latte": "^3.0",
    "symfony/yaml": "^7.0",
    "vlucas/phpdotenv": "^5.6",
    "phpmailer/phpmailer": "^6.9",
    "tracy/tracy": "^2.10",
    "ext-pdo": "*",
    "ext-pgsql": "*",
    "ext-sodium": "*",
    "ext-intl": "*",
    "ext-mbstring": "*"
  }
}
```

---

## Phase A: Bug Fixes (12 items)

### A1. Fix Container::autoWire() -- skips constructors with zero params
**File:** `src/Zephyrus/Container/Container.php:246-248`
**Bug:** Uses `newInstanceWithoutConstructor()` when constructor has 0 params, skipping all constructor logic.
**Fix:** Change to `$reflector->newInstance()` which calls the constructor properly.
```php
// Before:
if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
    return $reflector->newInstanceWithoutConstructor();
}
// After:
if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
    return $reflector->newInstance();
}
```

### A2. Fix Database::selectValue() -- PDO fetchColumn() ambiguity
**File:** `src/Zephyrus/Data/Database.php:116-121`
**Bug:** `fetchColumn()` returns `false` for both "no rows" and a column with value `false`. Can silently corrupt boolean/zero queries.
**Fix:** Use `fetch()` and check if the row itself is false:
```php
public function selectValue(string $sql, array $params = [], mixed $default = null): mixed
{
    $row = $this->query($sql, $params)->fetch();
    if ($row === false) {
        return $default;
    }
    return reset($row);
}
```

### A3. Switch Database layer from MySQL to PostgreSQL
**File:** `src/Zephyrus/Data/Database.php:40-65` and `src/Zephyrus/Core/Config/DatabaseConfig.php:35-69`
**Changes:**
- `DatabaseConfig::fromArray()`: Change default port from 3306 to 5432, default charset from 'utf8mb4' to 'utf8'
- `Database::fromConfig()`: Change DSN from `mysql:host=...` to `pgsql:host=...`, remove `MYSQL_ATTR_INIT_COMMAND`, use `SET client_encoding` via PDO exec instead
- Add `driver` property to `DatabaseConfig` (defaults to 'pgsql') -- fixes the `Configuration::toArray()` reference to `$this->database->driver` which currently doesn't exist
- Update `Database::fromConfig()` docblock

### A4. Fix Request::withAttributes() -- replaces instead of merging
**File:** `src/Zephyrus/Http/Request.php:267-280`
**Bug:** `withAttributes()` completely replaces all existing attributes. Middleware that sets attributes before routing loses them.
**Fix:** Merge with existing attributes:
```php
public function withAttributes(array $attributes): self
{
    return new self(
        // ... all other fields ...
        attributes: array_merge($this->attributes, $attributes),
        // ...
    );
}
```

### A5. Fix AllAuthGuard/AnyAuthGuard -- throw on empty guards array
**Files:** `src/Zephyrus/Security/AllAuthGuard.php:14`, `src/Zephyrus/Security/AnyAuthGuard.php:14`
**Bug:** `AllAuthGuard([])` returns `true` (vacuously authorizes everyone). `AnyAuthGuard([])` returns `false`.
**Fix:** Add validation in both constructors:
```php
public function __construct(private readonly array $guards)
{
    if ($guards === []) {
        throw new \InvalidArgumentException('At least one auth guard must be provided.');
    }
}
```

### A6. Fix ForceHttpsMiddleware -- defensive check for http:// prefix
**File:** `src/Zephyrus/Security/ForceHttpsMiddleware.php:55-58`
**Bug:** `buildHttpsUrl()` blindly slices at offset 7 assuming URI starts with `http://`.
**Fix:**
```php
private function buildHttpsUrl(string $uri): string
{
    if (!str_starts_with($uri, 'http://')) {
        return $uri;
    }
    $https = 'https://' . substr($uri, strlen('http://'));
    return preg_replace('#^(https://[^/:]+):80(?=(?:[/?\#]|$))#', '$1', $https) ?? $https;
}
```

### A7. Normalize Response header storage to lowercase
**File:** `src/Zephyrus/Http/Response.php:99-109`
**Bug:** Response headers are case-sensitive while Request normalizes to lowercase. Can cause duplicate headers.
**Fix:** Normalize header names to lowercase in `withHeader()`, `withHeaders()`, and static factories. Update `withoutHeader()` to use lowercase too.

### A8. Fix SortRequest direction normalization
**File:** `src/Zephyrus/Data/SortRequest.php:9-21`
**Bug:** Constructor validates uppercase but stores original (potentially lowercase) via property promotion.
**Fix:** Remove property promotion for `direction`, normalize in constructor body:
```php
public readonly string $direction;

public function __construct(
    public readonly string $column,
    string $direction = 'ASC',
) {
    // ... validation ...
    $this->direction = strtoupper($direction);
}
```

### A9. Fix Translator::applyPlural() -- strict float comparison
**File:** `src/Zephyrus/Localization/Translator.php:223-225`
**Bug:** `$numeric === 1.0` uses strict identity comparison with float, which is unreliable.
**Fix:** Use epsilon comparison or cast to int when appropriate:
```php
$numeric = is_numeric($value) ? abs((float) $value) : 1.0;
return (abs($numeric - 1.0) < PHP_FLOAT_EPSILON) ? $singular : $plural;
```

### A10. Add charset validation in DatabaseConfig
**File:** `src/Zephyrus/Core/Config/DatabaseConfig.php`
**Bug:** Charset is interpolated directly into SQL (`SET NAMES {$charset}`) without validation.
**Fix:** Validate charset against `/^[a-zA-Z0-9_]+$/` in `fromArray()`:
```php
if (!preg_match('/^[a-zA-Z0-9_]+$/', $charset)) {
    throw ConfigurationException::invalidValue('database', 'charset', $charset, 'must be alphanumeric');
}
```

### A11. Remove dead code in RouteCache::save()
**File:** `src/Zephyrus/Routing/RouteCache.php:231-233`
**Bug:** `$json === false` check is unreachable after `JSON_THROW_ON_ERROR`.
**Fix:** Remove the dead code block.

### A12. Add identifier quoting in SortRequest::toSql()
**File:** `src/Zephyrus/Data/SortRequest.php:54-57`
**Bug:** Column name is interpolated without quoting (PostgreSQL uses double quotes for identifiers).
**Fix:** Quote column identifiers for PostgreSQL:
```php
public function toSql(): string
{
    $quotedColumn = implode('.', array_map(
        fn(string $part) => '"' . str_replace('"', '""', $part) . '"',
        explode('.', $this->column)
    ));
    return sprintf(' ORDER BY %s %s', $quotedColumn, $this->direction);
}
```
Similarly, add quoting in `FilterRequest::toWhereClause()` for column names.

---

## Phase B: Environment & Configuration Overhaul

### B1. Add phpdotenv integration
- Add `vlucas/phpdotenv` to `composer.json` require
- Create `src/Zephyrus/Core/Environment.php` (not the enum -- a new class for env loading):
  - `Environment::load(string $directory)` -- loads `.env` file
  - `Environment::loadIfExists(string $directory)` -- silently skips if missing
  - Uses `Dotenv\Dotenv::createImmutable()` internally
- Add `env()` global helper function in `functions.php`

### B2. Add Symfony YAML integration with !env tag
- Add `symfony/yaml` to `composer.json` require
- Create `src/Zephyrus/Core/Config/ConfigurationFile.php`:
  - `__construct(string $path)` -- takes path to YAML file
  - Uses `Yaml::parseFile($path, Yaml::PARSE_CUSTOM_TAGS)` for parsing
  - Implements `processYamlTags(array $config): array` to recursively resolve `!env VAR,default` tags
  - `read(?string $section = null): mixed` -- returns full config or a specific section
  - `toArray(): array` -- returns the full parsed config

### B3. Rewrite Configuration to use YAML
- Modify `Configuration::fromFile()` to detect `.yml`/`.yaml` extension and use `ConfigurationFile` for YAML, keeping PHP array file support as well
- Actually -- per user decision, YAML only: rewrite `fromFile()` to always use YAML parsing
- Remove PHP `require` loading
- Keep `fromArray()` for programmatic/test usage
- Update `fromFiles()` and `fromOptionalFiles()` to use YAML

### B4. Add ConfigSection base class for user-extensible config
- Create `src/Zephyrus/Core/Config/ConfigSection.php`:
  - Abstract class that typed config sections extend
  - Provides `fromYaml(array $values): static` -- hydration from YAML array
  - Dot-notation access: `get(string $key, mixed $default = null): mixed`
  - Type-coerced accessors: `getString()`, `getInt()`, `getBool()`, `getFloat()`, `getArray()`
  - Supports both camelCase and snake_case keys (existing pattern)
- Refactor existing config classes (`ApplicationConfig`, `SessionConfig`, `SecurityConfig`, `LocalizationConfig`, `DatabaseConfig`) to extend `ConfigSection`

### B5. Add custom section registration to Configuration
- Add to `Configuration`:
  - `withSection(string $name, ConfigSection $section): self`
  - `section(string $name): ?ConfigSection`
  - `hasSection(string $name): bool`
  - Store custom sections in a `private array $customSections`
- The `fromArray()` method should pass unknown top-level keys through to registered section factories

### B6. Wire all config sections into ApplicationBuilder
- `ApplicationBuilder::withConfiguration(Configuration)` should:
  - Wire localization (already done)
  - Wire security config (auto-add CSRF middleware if enabled, secure headers, force-HTTPS, allowed hosts)
  - Wire session config (auto-configure SessionMiddleware)
  - Wire application config (set debug mode, environment)
  - Wire database config (make available for Broker classes)

### B7. Add global helper functions
- Create `src/Zephyrus/functions.php`:
  - `env(string $key, mixed $default = null): mixed`
  - `config(string $section, ?string $property = null, mixed $default = null): mixed`
  - `session(string $key, mixed $default = null): mixed`
  - `localize(string $key, ...$args): string` / `i18n()` alias
  - `format(string $type, ...$args): string`
  - `asset(string $path): string`
  - `embed(string $path): string`
  - `nonce(): string`
- Register in `composer.json` under `autoload.files`

---

## Phase C: View / Template Rendering

### C1. Add RenderEngine interface
- Create `src/Zephyrus/Rendering/RenderEngine.php`:
  - `render(string $page, array $args = []): string`
  - `exists(string $page): bool`

### C2. Add LatteEngine
- Create `src/Zephyrus/Rendering/LatteEngine.php`:
  - Constructor takes template directory, cache directory, optional Latte extensions
  - Wraps Latte 3.x `Engine` class
  - Configurable cache mode (always/never)
  - Auto-registers framework Latte extensions (asset, nonce, localize helpers)

### C3. Add PhpEngine
- Create `src/Zephyrus/Rendering/PhpEngine.php`:
  - Raw PHP templates using `extract()` + `require` + output buffering
  - Template directory configurable
  - File extension configurable (default `.php`)

### C4. Add RenderResponses trait for Controller
- Create rendering helpers mixed into Controller:
  - `render(string $page, array $args = [], int $status = 200): Response`
  - `renderPhp(string $page, array $args = [], int $status = 200): Response`
  - `html(string $content, int $status = 200): Response`

### C5. Add rendering configuration
- Add `render` section to YAML config:
  ```yaml
  render:
    engine: latte   # latte or php
    directory: app/Views
    cache: cache/latte
    mode: always    # always or never
  ```
- Create `RenderConfig` extending `ConfigSection`

### C6. Integrate Tracy for debugging
- When `application.debug: true`:
  - Register Tracy as error handler via `Tracy\Debugger::enable()`
  - Add Tracy bar panels (request info, route info, DB queries if available)
  - Latte uses Tracy's Latte bridge for template debugging
- When `application.debug: false`:
  - Tracy is not initialized
  - Production error handling via `HttpExceptionResponder`

### C7. Add rendering-related exceptions
- `RenderException` -- template not found, rendering errors
- `RenderEngineException` -- engine configuration errors

---

## Phase D: Cryptography Utilities (libsodium)

### D1. Add Cryptography class
- Create `src/Zephyrus/Security/Cryptography.php`:
  - **Encryption/Decryption:**
    - `encrypt(string $plaintext, string $key): string` -- XChaCha20-Poly1305 (AEAD) via `sodium_crypto_aead_xchacha20poly1305_ietf_encrypt()`
    - `decrypt(string $ciphertext, string $key): string`
    - Output format: base64-encoded JSON with nonce + ciphertext
  - **Password Hashing:**
    - `hashPassword(string $password, ?string $pepper = null): string` -- `sodium_crypto_pwhash_str()` with Argon2id
    - `verifyPassword(string $password, string $hash, ?string $pepper = null): bool` -- `sodium_crypto_pwhash_str_verify()`
    - `needsRehash(string $hash): bool` -- `sodium_crypto_pwhash_str_needs_rehash()`
  - **Random Generation:**
    - `randomString(int $length): string` -- URL-safe random string
    - `randomBytes(int $length): string` -- `random_bytes()`
    - `randomHex(int $length): string`
    - `randomInt(int $min, int $max): int`
  - **Hashing:**
    - `hash(string $data, ?string $key = null): string` -- BLAKE2b via `sodium_crypto_generichash()`
    - `hashFile(string $path): string`
  - **Key Management:**
    - `generateEncryptionKey(): string` -- `sodium_crypto_aead_xchacha20poly1305_ietf_keygen()`
    - `generateSigningKeyPair(): array` -- Ed25519 keypair

### D2. Add CryptographyConfig
- YAML section under `security.encryption`:
  ```yaml
  security:
    encryption:
      key: !env APP_ENCRYPTION_KEY
  ```

### D3. Add CryptographyException
- Extends `ZephyrusException`
- Named factories: `encryptionFailed()`, `decryptionFailed()`, `invalidKey()`

---

## Phase E: Formatter Class

### E1. Add Formatter class
- Create `src/Zephyrus/Formatting/Formatter.php`:
  - Uses `ext-intl` for locale-aware formatting
  - Constructor takes locale string
  - **Numeric:**
    - `money(float $amount, ?string $currency = null): string` -- `NumberFormatter::CURRENCY`
    - `decimal(float $value, int $precision = 2): string` -- `NumberFormatter::DECIMAL`
    - `percent(float $value, int $precision = 0): string` -- `NumberFormatter::PERCENT`
    - `ordinal(int $value): string` -- "1st", "2nd", "3rd" (`NumberFormatter::ORDINAL`)
    - `spellOut(float $value): string` -- "forty-two" (`NumberFormatter::SPELLOUT`)
  - **Temporal:**
    - `date(mixed $date, string $pattern = 'medium'): string` -- `IntlDateFormatter`
    - `time(mixed $time, string $pattern = 'short'): string`
    - `datetime(mixed $datetime, string $pattern = 'medium'): string`
    - `relativeTime(mixed $datetime): string` -- "2 hours ago", "in 3 days" using `IntlDateFormatter` relative patterns
    - `duration(int $seconds): string` -- "2h 15m 30s"
  - **Specialized:**
    - `filesize(int $bytes, int $precision = 1): string` -- "1.5 MB", "320 KB"
    - `list(array $items, string $type = 'conjunction'): string` -- "a, b, and c" using `IntlListFormatter` or manual
    - `truncate(string $value, int $length, string $suffix = '...'): string`
  - **Custom:**
    - `register(string $name, callable $formatter): void`
    - `format(string $name, mixed ...$args): string`

### E2. Integrate Formatter with Translator pipe transforms
- The `|number`, `|truncate` pipes in `Translator::applyPipes()` should delegate to `Formatter` when available
- Add new pipes: `|money`, `|date`, `|filesize`, `|ordinal`, `|percent`

### E3. Add format() global helper
- `format(string $type, ...$args): string` -- uses a global/singleton Formatter instance

---

## Phase F: FileSystem Utilities

### F1. Add FileSystemNode abstract base
- Create `src/Zephyrus/FileSystem/FileSystemNode.php`:
  - `path(): string`
  - `exists(): bool`
  - `name(): string` (basename)
  - `parent(): string` (dirname)
  - `permissions(): int`
  - `lastModified(): int`
  - `isReadable(): bool`
  - `isWritable(): bool`

### F2. Add File class
- Create `src/Zephyrus/FileSystem/File.php` extends FileSystemNode:
  - `read(): string`
  - `write(string $content): void`
  - `append(string $content): void`
  - `size(): int`
  - `mimeType(): string`
  - `extension(): string`
  - `hash(string $algo = 'sha256'): string`
  - `copy(string $destination): File`
  - `move(string $destination): File`
  - `delete(): void`
  - `lines(): array`
  - Static: `File::create(string $path, string $content = ''): File`

### F3. Add Directory class
- Create `src/Zephyrus/FileSystem/Directory.php` extends FileSystemNode:
  - `files(string $pattern = '*'): array` -- returns File objects
  - `directories(): array` -- returns Directory objects
  - `glob(string $pattern): array`
  - `recursiveGlob(string $pattern): array`
  - `create(int $permissions = 0755): void`
  - `delete(bool $recursive = false): void`
  - `size(): int` (recursive)
  - `isEmpty(): bool`
  - Static: `Directory::ensure(string $path): Directory`

### F4. Add FileSystemException

### F5. Improve Uploader integration
- Refactor `Upload/Uploader.php` to use the new File/Directory classes internally

---

## Phase G: Asset Management

### G1. Add Asset class
- Create `src/Zephyrus/Rendering/Asset.php`:
  - `__construct(string $publicDirectory)`
  - `url(string $path): string` -- returns `/path?v={hash}` with content-hash cache-busting
  - `embed(string $path): string` -- returns inline file content (SVG, CSS, JS)
  - `exists(string $path): bool`
  - In-memory hash cache (per-request) to avoid re-hashing
  - Configurable hash algorithm (default md5 for speed)

### G2. Add global helpers
- `asset(string $path): string`
- `embed(string $path): string`

### G3. Register as Latte extension
- Create `LatteAssetExtension` that provides `{asset}` and `{embed}` tags/filters in templates

---

## Phase H: Mailer System

### H1. Add Mailer class
- Create `src/Zephyrus/Mailer/Mailer.php`:
  - Wraps PHPMailer
  - Fluent API:
    ```php
    $mailer->to('user@example.com')
        ->cc('admin@example.com')
        ->subject('Welcome')
        ->template('emails/welcome', ['name' => 'David'])
        ->attach('/path/to/file.pdf')
        ->send();
    ```
  - Template rendering uses the configured `RenderEngine` (Latte/PHP)
  - Supports plain text body as alternative
  - SMTP configuration from YAML `mailer` section

### H2. Add MailerConfiguration
- Create `src/Zephyrus/Mailer/MailerConfiguration.php` extends `ConfigSection`:
  - SMTP host, port, username, password, encryption (tls/ssl), from address, from name

### H3. Add MailerException
- Extends `ZephyrusException`
- Named factories: `sendFailed()`, `invalidAddress()`, `attachmentNotFound()`, `configurationMissing()`

### H4. YAML config section
```yaml
mailer:
  smtp:
    host: !env MAIL_HOST, localhost
    port: !env MAIL_PORT, 587
    username: !env MAIL_USER
    password: !env MAIL_PASSWORD
    encryption: tls
  from:
    address: !env MAIL_FROM_ADDRESS
    name: !env MAIL_FROM_NAME
```

---

## Phase I: Trusted Proxies & Security Hardening

### I1. Add trusted proxy configuration
- Add to `SecurityConfig`:
  ```yaml
  security:
    trusted_proxies: ['127.0.0.1', '10.0.0.0/8']
  ```
- Modify `Request::resolveClientIp()` and `resolveClientIpFromHeaders()`:
  - Only parse `X-Forwarded-*`, `X-Real-IP`, `CF-Connecting-IP` etc. when `REMOTE_ADDR` is in the trusted proxy list
  - If no trusted proxies configured, always use `REMOTE_ADDR` directly
- Add `trustedProxies` property to `SecurityConfig`

### I2. Add PostgreSQL identifier quoting in FilterRequest
- `FilterRequest::toWhereClause()` should quote column names with double quotes for PostgreSQL:
  ```php
  $quotedColumn = '"' . str_replace('"', '""', $column) . '"';
  ```

### I3. Exception hierarchy audit
- Ensure every module has properly typed exceptions:
  - `Container/`: ContainerException, NotFoundException (exist)
  - `Data/`: DatabaseException (exists)
  - `Http/`: HttpException hierarchy (exists)
  - `Routing/`: RouteCacheException, RouteMiddlewareException, etc. (exist)
  - `Security/`: Add CryptographyException
  - `Rendering/`: Add RenderException, RenderEngineException
  - `Mailer/`: Add MailerException
  - `FileSystem/`: Add FileSystemException
  - `Formatting/`: Add FormatterException
- All should extend `ZephyrusException` or `ZephyrusRuntimeException`

---

## Phase J: Session Middleware & Integration

### J1. Add SessionMiddleware
- Create `src/Zephyrus/Session/SessionMiddleware.php`:
  - Auto-starts session with configured options from `SessionConfig`
  - Injects `SessionManager` into request attributes (`session` key)
  - Configures cookie parameters (name, lifetime, secure, httpOnly, sameSite, path)
  - Handles session regeneration based on configuration

### J2. Wire session config into ApplicationBuilder
- `withConfiguration()` should auto-add `SessionMiddleware` when session is configured
- Session cookie parameters derived from `SessionConfig`

### J3. Add session storage options
- File-based (default) -- PHP's built-in handler
- Database-backed -- PostgreSQL table for session storage (create `DatabaseSessionHandler`)
- Encrypted variants using `Cryptography` class

### J4. Session fingerprinting
- Optional IP and User-Agent binding to detect session hijacking
- Add `fingerprintIp` and `fingerprintUserAgent` options to `SessionConfig`
- Implement `SessionFingerprintMiddleware` or integrate into `SessionMiddleware`

---

## Phase K: Global Helpers (functions.php)

Create `src/Zephyrus/functions.php` and register in `composer.json` `autoload.files`.

Functions:
- `env(string $key, mixed $default = null): mixed` -- reads `$_ENV`/`$_SERVER`
- `config(string $section, ?string $property = null, mixed $default = null): mixed` -- reads from global Configuration instance
- `session(string|array $key, mixed $default = null): mixed` -- session read/write shorthand
- `localize(string $key, array $params = [], ?string $locale = null): string` -- translation
- `i18n(string $key, array $params = [], ?string $locale = null): string` -- alias
- `format(string $type, mixed ...$args): string` -- formatting
- `asset(string $path): string` -- cache-busted URL
- `embed(string $path): string` -- inline file content
- `nonce(): string` -- CSP nonce

Note: These functions need a global application registry to work. Consider a simple static `Zephyrus\Core\App` class that holds the booted application instance.

---

## Phase L: Exception Cleanup

Audit all exception classes to ensure:
1. Consistent inheritance from `ZephyrusException` (logic errors) or `ZephyrusRuntimeException` (runtime errors)
2. Named constructors (static factory methods) for common error cases
3. Meaningful error messages with context
4. Proper `previous` exception chaining

New exception classes needed:
- `Rendering/RenderException`
- `Rendering/RenderEngineException`
- `Security/CryptographyException`
- `Mailer/MailerException`
- `FileSystem/FileSystemException`
- `Formatting/FormatterException`

---

## Phase M: Tests

Maintain the ~98% coverage standard. For each phase:

### Phase A tests
- Test Container auto-wiring with zero-param constructor (verify constructor runs)
- Test Database::selectValue() with false column value
- Test Database PostgreSQL DSN generation
- Test Request::withAttributes() merges (not replaces)
- Test AllAuthGuard/AnyAuthGuard throw on empty array
- Test ForceHttpsMiddleware with non-http:// URI
- Test Response header case normalization
- Test SortRequest direction normalization
- Test Translator plural with float edge cases
- Test DatabaseConfig charset validation
- Test SortRequest::toSql() identifier quoting

### Phase B tests
- Test ConfigurationFile YAML parsing
- Test !env tag resolution
- Test ConfigSection base class hydration
- Test custom section registration
- Test ApplicationBuilder full wiring from Configuration

### Phase C tests
- Test LatteEngine rendering
- Test PhpEngine rendering
- Test RenderResponses trait
- Test Tracy integration in debug mode

### Phase D tests
- Test Cryptography encrypt/decrypt roundtrip
- Test password hashing and verification
- Test random generation
- Test key generation

### Phase E tests
- Test all Formatter methods with different locales
- Test custom formatter registration
- Test pipe transform delegation

### Phase F tests
- Test File read/write/copy/move/delete
- Test Directory create/delete/glob
- Test FileSystemNode properties

### Phase G tests
- Test Asset URL generation with hash
- Test embed() file inlining

### Phase H tests
- Test Mailer configuration
- Test template email rendering
- Test attachment handling

### Phase I tests
- Test trusted proxy IP resolution
- Test identifier quoting

### Phase J tests
- Test SessionMiddleware auto-start
- Test session fingerprinting
- Test database session handler

---

## Phase N: Documentation Updates

1. Update `docs/ARCHITECTURE.md`:
   - Add slices for Container, Events, Upload, CSP, ApplicationBuilder, Rendering, Crypto, Formatter, FileSystem, Asset, Mailer
   - Update planned modules list
   - Fix naming inconsistencies

2. Update `docs/ROADMAP.md`:
   - Mark Phases 0-6 as complete
   - Add new phases for the work in this plan
   - Remove Web3 track from core roadmap

3. Fix documentation/code naming mismatches:
   - Upload vs Uploader namespace
   - UploadedFile vs FileUpload class names

4. Create feature docs for:
   - Configuration (YAML, !env, custom sections)
   - Routing (attributes, groups, caching, URL generation)
   - Security (CSRF, auth guards, CSP, crypto)
   - Rendering (Latte, PHP, Tracy)
   - Data (Database, Broker, pagination, filtering, sorting)
   - Validation (rules, form validator)

---

## Execution Order

1. Phase A (bug fixes) -- fix existing issues before building on top
2. Phase B (config) -- foundation everything else builds on
3. Phase C (rendering) -- enables template-based features
4. Phase D (crypto) -- needed for encrypted sessions
5. Phase J (session middleware) -- depends on crypto for encrypted sessions
6. Phase E (formatter) -- independent module
7. Phase F (filesystem) -- independent module
8. Phase G (assets) -- depends on rendering for Latte extension
9. Phase H (mailer) -- depends on rendering for template emails
10. Phase I (security hardening) -- refinements
11. Phase K (helpers) -- ties everything together
12. Phase L (exceptions) -- cleanup pass
13. Phase M (tests) -- written alongside each phase
14. Phase N (docs) -- final pass

---

## New Directory Structure After All Phases

```
src/Zephyrus/
  Container/          (existing)
  Controller/         (existing)
  Core/               (existing, expanded)
    Config/           (rewritten for YAML)
  Data/               (existing, PostgreSQL)
  Event/              (existing)
  Exceptions/         (existing, expanded)
  FileSystem/         (new)
  Formatting/         (new)
  Http/               (existing)
  Localization/       (existing)
  Mailer/             (new)
  Rendering/          (new)
  Routing/            (existing)
  Security/           (existing, expanded)
  Session/            (existing, expanded)
  Upload/             (existing)
  Validation/         (existing)
  functions.php       (new)
```
