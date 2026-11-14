# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). Before 1.0, a minor version may break the API: those
changes are listed first, each with the change to make in your code.

## [Unreleased]

### Breaking changes

#### Data

- `Database` refuses a persistent PDO, and `Database::fromConfig()` refuses a factory that returns a persistent connection it did not ask for, because the session of a persistent connection (role, advisory locks, prepared statements, a transaction left open) used to reach the next request unchanged. `new Database($pdo)` with `PDO::ATTR_PERSISTENT` throws `DatabaseException` (`Database refuses a persistent PDO, whose session would carry over from an earlier request: remove PDO::ATTR_PERSISTENT from the PDO and set database.persistent: true in the configuration, so Database::fromConfig() resets the session each time it reuses the connection.`), and a factory that returns one while `database.persistent` is `false`, or while a persistent handle for the same DSN and user is still held, throws `DatabaseException` (`The PDO factory given to Database::fromConfig() returned a persistent connection it was not asked for, whose session would carry over from an earlier request: remove PDO::ATTR_PERSISTENT from the factory and set database.persistent: true in the configuration to reuse connections.`). To migrate: remove `PDO::ATTR_PERSISTENT` from the factory or the PDO, set `persistent: true` in the `database` section, and open the connection with `Database::fromConfig()`.

### Security

- A YAML syntax error in `config.yml` no longer quotes the file. `v0.16.0` appended the parser's message to `ConfigurationException` and chained the parser's exception, and both can quote a value of the file (a malformed `password` line shows it in the message and in the excerpt), which then reached traces, error pages, logs and `cache:warm`. The message is now `Failed to parse configuration file [config.yml]: invalid YAML near line 3 (the parser's detail is withheld, since it can quote a secret from the file).` (without `near line N` when the parser gives no line), `getPrevious()` returns null, and the parser's exception is no longer an argument of any call made after the failure, so a trace that records arguments (Tracy does) holds neither it nor the file's text: code that read the line from the cause finds it in the message. `ConfigurationException::parseFailed()` takes the line (`?int`) instead of the exception.
- A refused `!env` name is no longer quoted. `!env tag at "a": ...` names the tag's key and the reason (`the variable name is refused: names must not contain a NUL byte or "=".`), and the `InvalidArgumentException` of `EnvironmentVariable::read()` is no longer chained, since a value pasted where a name belongs (a base64 secret ends with `=`) would otherwise reach the message and `previous`. The loading methods that hold the file's text or an `!env` value (the whole parsed configuration, the tag, its default) are marked `#[\SensitiveParameter]`, so a trace that records arguments (Tracy does) shows `SensitiveParameterValue` for them; this also hides a neighbouring secret that Tracy's key masking does not recognise.
- A value a section getter refuses (`ConfigSection::getString()`, `getInt()`, ...) is hidden from the trace's argument list.

### Added

- `database.persistent` (default `false`) reuses the PostgreSQL connection across requests of one process. When a connection is taken from the pool, `fromConfig()` sends `ROLLBACK` if a transaction is open, then `DISCARD ALL`, then drops the notifications the client had already received. A connection whose reset fails is terminated and replaced once; a second failure throws `DatabaseException`.
- The pool is per process (`zephyrus:<pid>`), and a persistent handle still held in the process is never handed out again: for the same DSN and user, a second `fromConfig()` opens a non-persistent connection instead.

### Changed

- The default connection factory of `Database::fromConfig()` builds with `PDO::connect()`, so the handle is a `Pdo\Pgsql`. A factory you pass keeps working; on PHP 8.5 one used with `persistent: true` should build with `PDO::connect()` too, or each acquisition emits a deprecation.
- A dotted key written flat under a section whose first part is a mapping of that section (`smtp.host: h` under `mailer:`) is refused with `... is written flat: nest it under 'smtp', such as smtp: { host: ... }.`, where it listed the accepted keys or suggested a spelling. The example shows the nested key written (or the closest one when it is misspelled) and is left out when that key is not a plain field name.

### Fixed

- An unreadable `config.yml` is reported as `Configuration file is not readable: config.yml`, where it was reported as invalid YAML.
- `MailerConfig::toArray()` exports `smtp.port` as `587` when the key is written as null (an empty `!env`), as `smtpPort` holds, where it exported null.
- A type refusal under `mailer:` or `render:` names the section by its key (`'mailer'`, `'render'`), as an unknown key does, where it named the class. `ConfigSection::sectionName()` (protected, defaults to the class name) supplies it: a section of your own may override it.

## [0.16.0] - 2026-11-13

### Breaking changes

#### Routing

- `RouteCache` is `@internal` and its public API is gone: `new RouteCache($cacheFile)`, `filePath()`, `isFresh()`, `metadata()`, `generatedAt()`, `routeCount()`, `routesHash()`, `metadataVersion()`, `age()`, `expiresAt()`, `isExpired()`, `isFreshWithin()`, `ensureFreshWithin()`, `canUseWithin()`, `inspect()` and `warmIfStale()` no longer exist, nor does `RouteCacheException::refused()`. A cache of a build artifact is judged by what it was read from, not by its age. Replace the calls with the `router:` section and `vendor/bin/zephyrus cache:warm` (see Added), and delete any code that warms or inspects a `RouteCache` itself.

#### Core

- `ApplicationBuilder::fromConfigurationFile()` and `buildFromConfigurationFile()` pass the real path of the file's directory as `basePath`, so a relative `localization.locale_path` resolves against that directory, where it used to resolve against the current directory. Write the path relative to the configuration file, or absolute.

#### Configuration

- `router` is a reserved section name: a section factory registered under it throws `InvalidArgumentException` (`Section factory "router" collides with the built-in section "router"; read it with $configuration->router instead.`), and a `router:` key in `config.yml` is now read and validated, where it used to be ignored unless registered. An application that has its own `router:` section: rename it (`routing`, for instance). A section named `routes` is now refused as a typo of `router`: rename it too.

### Added

- A built-in `router:` section in `config.yml` declares the controllers (`controllers`, a PSR-4 namespace to directory map, required), the `middleware_groups` (as `Router::middlewareGroup()`) and the routes `cache` file (a `.php` file under the application root, default `var/zephyrus/routes.php`). Relative paths resolve against `basePath`, no value may carry a YAML tag (`!env` included), and a controller directory that is missing or holds no class of its namespace is refused, where `discoverControllers()` registers nothing silently. The cache holds every route path, name and constraint in clear: never build a route from a secret.
- `Router::fromConfiguration(Configuration $configuration, string $basePath)` builds the router of that section. In production and staging it reads the routes cache, and falls back to discovery with one `error_log` line when the cache is absent, stale or refused, or when `basePath` holds no `config.yml` (the routes cache is read only from the `config.yml` of the application root); in development and testing it always discovers. The cache is stale once `config.yml`, a file under a controller directory, or the file of a parent class, trait or interface of a controller changes (to the second). A constant of another class read by an attribute is not tracked: run `cache:warm` on every build. `#[RequiresEnv]` is evaluated on every request, so one cache serves every `MODE` and `APP_ENV`. Two routes of one name that can be registered together are refused, by the warm and by the discovery in development.
- `ApplicationBuilder::build()` builds that router when the configuration declares `router:` and there is no `withRouter()`; it refuses a `withConfiguration()` without `basePath` and a `withRouter()` whose router `Router::fromConfiguration()` did not build (or derive), so that a half-done migration cannot write a cache nobody reads. To upgrade: add `router: { controllers: { App\Controllers: app/Controllers } }` to `config.yml`, call `withConfiguration($config, basePath: $root)` and delete the `discoverControllers()` and `withRouter()` lines (or replace the router with `Router::fromConfiguration($config, basePath: $root)` when it is needed before `build()`). Without a `router:` section nothing changes. Declare the groups of `middlewareGroup()` under `router.middleware_groups`. `ApplicationBuilder::fromConfigurationFile()` and `buildFromConfigurationFile()` take the directory of the file as `basePath`.
- `vendor/bin/zephyrus cache:warm` and `cache:clear` (`--config=PATH`, repeatable, default the `config.yml` of the project root) write and delete the caches: `var/zephyrus/config.yml.php` and, with a `router:` section, `var/zephyrus/routes.php`. Exit codes: 0, 1 refused, 2 usage error. The command reads no `.env` and resolves no `!env`, and before it writes anything it checks every configuration and its `router:` section, the process owner, and that a file already at a cache path carries the warm header. The directory guards (mode, owner, link) and the regular-file check run when each file is written, so a refused directory can leave the caches written before it (valid, `.gitignore` included); `cache:clear` checks the header only when it deletes, so it can remove the caches of a first configuration before refusing a foreign file in the next. The files are protected by their owner and file modes, and are not included otherwise; a file at a cache path that `cache:warm` did not write is never replaced or deleted. Warming writes `var/zephyrus/.gitignore` (`*`) unless one is there. To upgrade: run `RUN php vendor/bin/zephyrus cache:warm` in the image build, after `COPY` and `composer dump-autoload`, before any `USER`, on a path no `chown` touches afterwards; add `var/zephyrus/` to `.dockerignore`.
- Limits: `cache:warm` does not warm `router:` from a `config.yml` that is a symbolic link, because it resolves the directories from the link target, where `fromConfigurationFile()` uses the directory of the link. When the target is itself named `config.yml` it refuses with a directory-not-found message; when it has another name (`config.yml -> other/real.yml`) it refuses with `Configuration file real.yml declares router:, but router: is cached only from config.yml at the application root: move the router: section to that file.` (exit 1). Either way the boot discovers the routes and logs `no routes cache`. `ApplicationBuilder::create()->withConfigurationFile()->build()` and `buildFromConfigurationFiles([...])` still refuse a `router:` section for lack of a `basePath`, with the fix in the message (only `fromConfigurationFile()` and `buildFromConfigurationFile()` take the directory of the file).
- `Configuration::fromYamlFile()` reads the parsed YAML from `var/zephyrus/<file name>.php` beside the file when it matches the file's content, with no change to your code. The cache holds the YAML before any tag is resolved, so `!env` is still read from the environment on every load and never written to disk. A cache that does not match, or that the owner and mode checks refuse, is ignored with one `error_log` line.

## [0.15.0] - 2026-11-12

### Breaking changes

#### Configuration

- A key the `render:` or `mailer:` section does not accept throws `ConfigurationException` at boot, where it used to be ignored: the message names the key and the closest accepted one (`Configuration section 'mailer' field 'smtp.prot' is an unknown key: did you mean "smtp.port"?`), or lists the accepted keys. Fix or delete the key.

#### Core

- Without an allowlist, the debugger admits a loopback client only under PHP's built-in server (`php -S`), where it used to admit one under every server API; under FPM, CGI or Apache it admits no one, because a proxy on the same host that adds no forwarding header makes every client look like loopback. Declare `withDebugClientAllowlist(['127.0.0.1'])` for a browser on the same machine, or `secret@127.0.0.1` and the `tracy-debug` cookie behind a proxy on the same host.

#### Data

- `Entity::build()` hydrates an ISO date string (`YYYY-MM-DD`, with optional time, fraction and UTC offset) into a `DateTimeImmutable`, `DateTimeInterface` or `DateTime` property, and throws `InvalidArgumentException` for a value it cannot hydrate, where it used to skip the property silently (a nested entity given something other than a `stdClass`, a union or intersection type, any other class type) or fail with a `TypeError` (a value of the wrong type for its backed enum) or an `Error` (a pure enum). A date string that does not fit is refused too. Give such a property a type `Entity` hydrates, or assign it after `build()`.

#### Session

- `DatabaseSessionHandler` refuses to write a resumed session whose row changed or was deleted since this request read it: the later of two concurrent requests loses its changes (PHP warning `Failed to write session data`, and a `Zephyrus:` line in the error log) instead of overwriting the first. To lower privileges (role, password), call `$session->regenerate()`.

#### Routing

- Two routes sharing a name (in two classes, or a repeated `Router::get()->name()`) make `build()` throw `DuplicateRouteNameException` (`Zephyrus\Routing\Exception`, a `ZephyrusRuntimeException`: `Duplicate route names detected: <names>`), where a duplicate between classes used to be accepted and `route()` took the first. `RouteCollection::assertNoDuplicateRouteNames()` throws it too, where it used to throw `RouteSignatureException`: a `catch (RouteSignatureException)` around that call no longer catches it. Rename one of the routes. Two attribute routes of one class sharing a name are still refused when the class is registered, with `RouteAttributeException`.

### Changed

- A pgsql connection built by `Database::fromConfig()` sends every query as an unnamed statement (`\Pdo\Pgsql::ATTR_DISABLE_PREPARES`, set after connecting): one round trip per query instead of three (prepare, execute, close), parameters still bound by the server and never emulated. `new Database($pdo)` is unchanged; `pdo()->setAttribute(\Pdo\Pgsql::ATTR_DISABLE_PREPARES, false)` brings named statements back.

### Fixed

- The column type cache serves only statements whose first element, after blanks and comments (a block comment holding another is not skipped), is `(` or `SELECT`, `WITH`, `VALUES`, `TABLE`, `INSERT`, `UPDATE`, `DELETE` or `MERGE`; any other (`FETCH`, `EXECUTE`, `SHOW`, `CALL`) describes its columns on every call, at one to two round trips per column. A `FETCH` on a second cursor of the same width used to inherit the first cursor's converters, so its `jsonb` columns came back as strings.
- `MailerConfig::toArray()` exports `smtp.port` as an integer, as `MailerConfig::$smtpPort` already read it, where a port given as a string (`!env` of a variable) was exported as that string.

## [0.14.1] - 2026-11-12

### Security

- `Uploader` no longer maps SVG and XML by default, as both can run script when served back: an application that accepts one must map it through `$mimeExtensionMap`.
- An explicit upload target name must end with an extension of the detected type (its mapped extension, or an alias such as `jpeg` for `image/jpeg`) and carry no executable extension (`php`, `phtml`, `phar`, `cgi`, `asp`, `jsp` and others) before it, or `UploadException` is thrown: derive the extension from the detected type, or map the type.
- An upload target name containing `:` or an ASCII control character, or ending with a dot or a space, is refused with `UploadException`: pass a clean name.
- `KernelBuilder` used alone: an exception no handler answers, one its handler rethrows, or the one a failing handler throws, no longer leaves `handle()`: it is written to the error log and answered with a generic 500. Call `withUnhandledExceptionsRethrown()` to keep an unhandled exception leaving `handle()`; `ApplicationBuilder` does it for you when errors reach the output.
- With debug on in the command line, an exception no handler answers now leaves `handle()` instead of the generic 500. With debug on in a web request, it leaves `handle()` only when the debugger admits the client, and is logged with a generic 500 otherwise.
- `display_errors` and `display_startup_errors` are turned off at the start of `ApplicationBuilder::build()` unless errors reach this boot's output (debug on, and the command line or a client the debugger admits), so PHP no longer prints errors into a response when no `php.ini` hides them.
- The debugger admits loopback only when no allowlist is declared. With `withDebugClientAllowlist()`, list loopback explicitly; behind a proxy on the same host, list `secret@127.0.0.1` and send the `tracy-debug` cookie, because a bare loopback entry no longer admits a request carrying `X-Forwarded-For`, `Forwarded` or `X-Real-IP`, and a loopback client carrying `X-Real-IP` is no longer admitted without an allowlist either.
- The debugger masks the `Set-Cookie` lines of a response in dumps and stack traces.
- `Request::fromGlobals()` reads the last entry of `X-Forwarded-Host`, `X-Forwarded-Proto` and `X-Forwarded-Port` (the value the nearest proxy wrote) instead of the first, which a client could set: the nearest proxy must write each of them on every request, by overwriting or appending.
- Deleting a symbolic link through `File` or `Directory` removes only the link, and links inside a deleted tree are unlinked without touching their targets, where the target's content used to be deleted.
- `File::copy()` gives a new file no wider mode than its source, the default permissions or the umask allow, where it used to be widened regardless of the umask.

### Added

- `Response::withCookie()`, which sets a cookie on its own `Set-Cookie` line with `Secure`, `HttpOnly`, `SameSite=Lax` and path `/` by default, and refuses a cookie browsers would discard. The cookies are held by the `Response` `cookies` constructor argument, a list of `Set-Cookie` values.
- An `allowed_hosts` entry can pin a port (`example.com:8443`, `[2001:db8::1]:8080`, `*.example.com:443`): it then admits that port only, a URL without a port standing for its scheme's default, while an entry without a port still admits any port.
- `KernelBuilder::withUnhandledExceptionsRethrown()`, to let an unhandled exception leave `HttpKernel::handle()` for a debugger.
- `HttpExceptionResponder::toMappedResponse()`, which returns null when only the generic 500 applies, and `internalErrorResponse()`.
- `LocaleTag::isWellFormed()`, the locale shape check shared by the resolvers and the catalog loader.
- `LatteEngine::registerFormatterFilters()` accepts a closure returning the formatter, so the filters follow a formatter provider replaced later.

### Fixed

- A response `Set-Cookie` no longer replaces the cookies PHP already queued (the session cookie and `setcookie()` calls): each cookie line is added.
- One CSP nonce is shared by every nonced policy of a request and `nonce()`, so the header and the page agree when several `ContentSecurityPolicyMiddleware` run.
- A malformed locale is ignored when no allowlist is given, instead of being accepted as the request locale.
- With no log directory, the debugger writes what it catches to the PHP error log instead of dropping it.

## [0.14.0] - 2026-11-11

### Breaking changes

#### Requirements

- `ext-fileinfo` is required: uploads sniff the real media type. Enable the extension in every PHP image.
- `symfony/yaml` must be `^7.4.12` (earlier releases carry parser denial-of-service advisories). Run `composer update symfony/yaml`.

#### Configuration

- `env()`, the YAML `!env` tag, `#[RequiresEnv]`, `EnvironmentContract` and the bootstrap variables (`APP_ENV`, `APP_CONFIG_*`) read only `$_ENV` and the process environment, never `$_SERVER`: a value set with `SetEnv` or `fastcgi_param` is no longer seen. Set it in the real process environment (container environment, php-fpm `env[NAME]`).
- Configuration variable names a request or the web server can supply throw `InvalidArgumentException`, case-insensitively: `HTTP_*`, `REDIRECT_*`, `ORIG_*`, `SSL_*`, `H2_*`, CGI and server names such as `REMOTE_ADDR`, `QUERY_STRING`, `HTTPS`, `SERVER_NAME`, `PHP_SELF`, `argv`, `argc`, and any name holding `=` or a NUL byte. Rename the variable.
- An empty `!env` name throws, and `#[RequiresEnv('NAME', '')]` now matches a variable set to an empty string.
- `Configuration::section()` throws `InvalidArgumentException` for a built-in section name (`application`, `session`, `security`, `localization`, `database`) in any spelling, and `config()` for any spelling but the exact one (`config('Database')`). Use the typed property (`$configuration->database`), or `config('database')`, which returns the typed object.
- Section factories throw `InvalidArgumentException` when registered under a blank name or a built-in section name in any spelling, or when two factories name the same section in any spelling. Rename the factory.
- A top-level key throws `ConfigurationException` when it is close to a built-in section name (the same name up to case, `_`, `-` and spaces, one edit away, or two edits away with the same first letter: `Configuration key 'sesion' is not a known section: did you mean "session"?`), when it is another spelling of a registered section, or when a registered section is written in two spellings. Fix the spelling, or register a section of your own through the `$sectionFactories` argument.
- Every built-in section (`application`, `session`, `security`, `localization`, `database`), and the `csrf`, `encryption` and `headers` mappings under `security`, refuses an unknown key and a key written in two spellings (`max_body_size` and `maxBodySize`); the message names the closest accepted key, or lists the accepted keys. Fix or delete the key, keeping one spelling.
- `security.csrf`, `security.encryption` and `security.headers` must be mappings (`csrf: { enabled: false }`), and `security.encryption.key` must be a string. Nest the values under their mapping.
- `ConfigSection::has()` returns true for a key explicitly set to `null`. Test `get('key') !== null` where null meant absent.
- `Configuration::toArray()` no longer exports `security.csrfAutoHtml`. Stop reading that key.
- Boolean settings (`application.debug`, session `httpOnly` and `secure`, `security.forceHttps`, `security.csrf.enabled`, `hstsIncludeSubdomains`, `CsrfConfig`, `ConfigSection::getBool()`) accept only booleans, `0`/`1` and `true`/`false`, `on`/`off`, `yes`/`no` in any case; anything else, including an empty string, throws `ConfigurationException`, and the string `'false'` is now false where it used to read as true. Write `true`/`false`, and give `!env` booleans a default (`!env APP_DEBUG, false`).
- A boolean setting declared `null` throws instead of taking its default, and an unset `!env` without a default reads as `null`. Give the tag a default (`!env APP_DEBUG, false`) or delete the key. Session `secure: null` still means `auto`.
- `security.allowedHosts` and `security.trustedProxies` accept a comma-separated string; an `allowedHosts` value that names no host (`''`) or a declared `null` throws. Write `allowedHosts: []` to accept every host.
- Security response headers are read from a `security.headers` section, and `SecureHeadersConfig::fromArray()` refuses unknown keys, `null` and non-scalar values, control characters and a non-integer `hstsMaxAge`. Keep only `xFrameOptions`, `xContentTypeOptions`, `referrerPolicy`, `xssProtection`, `hstsMaxAge`, `hstsIncludeSubdomains`, `csp` and `permissionsPolicy` (or their snake_case forms).
- `security.maxBodySize` must be an integer or a string of digits: `'2M'` and `1.5` are refused. Write the size in bytes (`max_body_size: 2097152`).
- The `emulate_prepares` database setting and the `DatabaseConfig` `emulatePrepares` argument are removed; the key is refused at boot even when `false`. Delete the key and the named argument.
- Database settings: `columnCacheVersion`, `sslMode` and `sslRootCert` refuse a float, boolean or array (quote a version: `'1.10'`); `host`, `database` and `sslRootCert` refuse whitespace inside the value, `;`, `=`, quotes, backslashes and control characters. Values read from configuration are trimmed first (`host: "db\n"` boots as `db`); `new DatabaseConfig()` trims nothing and also refuses surrounding whitespace. Put only the bare value in each.
- `database.host` must be a string with no user name or password, `database.port` a whole number from 1 to 65535 (an integer or digits; surrounding spaces are trimmed), and `database.database` a name rather than a connection URL. Put credentials in `username` and `password`, and split a URL into these settings.

#### Security

- `ApplicationBuilder::build()` throws `ConfigurationException` when a declared security setting (`forceHttps: true`, a non-empty `allowedHosts`, `maxBodySize` above 0, `headers`, an enabled `csrf`) is not enforced by a global middleware carrying the declared value: the same host list, the same CSRF exclusions, a body limit no larger than declared, headers built from `security->headers`. Call `withAcknowledgedSecurityKeys(['maxBodySize'])` for a setting enforced elsewhere.
- Only a middleware passed to `withMiddleware()` on the builder that is built counts: not one registered under a route name, nor one wrapped in another middleware. The builder is immutable, so keep the returned clone: `$builder = $builder->withMiddleware(new MaxBodySizeMiddleware($configuration->security->maxBodySize));`, and likewise for `new CsrfMiddleware($tokens, CsrfConfig::fromSecurityConfig($configuration->security))`. A call whose result is dropped mounts nothing.
- `withAcknowledgedSecurityKeys()` throws `InvalidArgumentException` for a name other than `forceHttps`, `allowedHosts`, `csrf`, `maxBodySize` and `headers` (bare or prefixed with `security.`). Fix the spelling.
- Automatic CSRF token injection is removed: `new CsrfConfig(injectToken: true)` and `security.csrf.auto_html: true` throw. Render the hidden `_csrf_token` field in every form, and delete `auto_html` from the configuration.
- A CSRF refusal answers a browser (`Accept` containing `text/html`) with a 403 `text/plain` body, JSON otherwise. To render your own page, pass `onFailure: fn (Request $request, CsrfFailure $failure): ?Response => ...` to `CsrfMiddleware` instead of matching the response body.
- `AllowedHostsMiddleware` accepts only host names: an entry with a port, a scheme, a space, a comma or a non-punycode international name throws at boot. Write `localhost` for `localhost:8080`, `example.com` for `https://example.com`, and the punycode form of an international name.
- A CIDR range whose address has bits after its prefix (`10.0.0.1/8`, `fdaa::/8`), an IPv4-mapped or embedded-IPv4 range shorter than `/96` or with host bits, is invalid: refused at boot in `trustedProxies`, at construction by `IpAllowlistGuard`, and never matched when passed straight to `Request`. Write the network address (`10.0.0.0/8`) or the prefix you meant (`fdaa::/16`).
- Behind a trusted proxy only `X-Forwarded-For`, `X-Forwarded-Host`, `X-Forwarded-Proto` and `X-Forwarded-Port` are read by default; `Forwarded`, `X-Real-IP`, `CF-Connecting-IP` and `X-Client-IP` are read only when listed in `security.trustedHeaders`. List the header your proxy sets.
- `ContentSecurityPolicy` and the security header values throw at construction on a control character.
- Headers set by a route or an inner response now win over `SecureHeadersMiddleware`, and a route's CSP replaces the configured one whole. Set a header in one place.
- `ApplicationBuilder::build()` and `KernelBuilder::build()` refuse a global `ContentSecurityPolicyMiddleware` with an enforced policy registered before a `SecureHeadersMiddleware` whose `csp` is set. Leave the `SecureHeadersMiddleware` `csp` empty, or register the CSP middleware after it.
- `ApplicationBuilder::build()` and `KernelBuilder::build()` refuse a route that names a middleware that is not registered, where the request used to fail with a 500 at runtime. The message lists each unknown name with its routes. Register each name with `registerMiddleware()` before `build()`, or define a group with `Router::middlewareGroup()` before the routes that name it.
- `Cryptography` refuses an all-zero encryption key, and a `hash()` key that is empty or outside 16 to 64 bytes. Generate keys with `Cryptography::generateEncryptionKey()` and pass `null` for an unkeyed hash.
- `Uploader` moves only a genuine upload (`is_uploaded_file()`) by default. In tests and command-line code, pass a `fileMover` closure to the constructor.

#### Session

- `SessionManager::start()`, `regenerate()`, `destroy()` and `setHandler()` throw `SessionException` when PHP refuses the operation, when output was already sent, or when destroying a closed session that still has an id. Catch `SessionException` where a fallback must still run (sign-out, error pages).
- `SessionException` factories take PHP's warning as `?\ErrorException` instead of `?string`. Pass the `ErrorException`, or read the reason through `getPrevious()`.
- `SessionManager::set()`, `remove()` and `session([...])` throw when no session is active. Start the session before writing to it.
- `DatabaseSessionHandler::write()` and `updateTimestamp()` return `false` with an `E_USER_WARNING` for an id this instance never read, and `updateTimestamp()` returns `false` when no row was refreshed. A wrapping handler must forward `open()`, `read()` before writing, and `close()`.
- `DatabaseSessionHandler` takes its row lock under a savepoint inside the caller's transaction, waits at most five seconds for it, and stores no row for an empty new session. Behind PgBouncer transaction pooling, construct it with `lockSessions: false`.

#### Database

- Query parameters are bound by type: booleans as `0`/`1`, `null` as SQL NULL, floats at full precision, `DateTimeInterface` as `Y-m-d H:i:s.uP`, `BackedEnum` by value, `Stringable` as text, `Binary` and streams as binary data. Arrays, other objects and strings holding a NUL byte throw `InvalidArgumentException`; encode arrays yourself (`json_encode()`).
- `DatabaseException` messages carry no SQL or driver text. Read `sqlState()`, `driverMessage()` and `sql()` instead of parsing the message.
- Nested `transaction()` calls use savepoints. On PostgreSQL, committing a transaction aborted by a caught failure throws (SQLSTATE 25P02). Let the failure propagate, or roll back explicitly.
- `selectValue()` returns a boolean `false` column as `false`, and PostgreSQL arrays come back as PHP arrays. Drop any manual `'t'`/`'f'` or `{...}` parsing.
- `DatabaseException::fromPdoException()` and `transactionFailed()` are removed. Use `DatabaseException::queryExecutionFailed($sql, $pdoException)` or `queryFailed($sql, $reason)`.
- On PostgreSQL and SQLite, `insertGetId()` and `Broker::insertRowGetId()` require a `RETURNING` clause with exactly one column (refused before the query runs), and `lastInsertId()` throws on PostgreSQL. Write `INSERT ... RETURNING id`.

#### HTTP

- `new Response(headers: [...])` stores header names lowercased, so `$response->headers['Location']` is null. Read headers with `getHeader('Location')`.
- `withHeader()`, `withHeaders()` and `redirect()` throw `InvalidArgumentException` on a control character other than a tab. Check untrusted values with `Response::isValidHeaderValue()`, and redirect to user-supplied targets with `Response::localRedirect()`.
- The host, scheme and port of a `Forwarded` header are read from the element appended by the outermost trusted proxy, not from the leftmost element, which a client can write. No change for a proxy that appends its element.
- `Request::fromArray()` throws for a file entry without a readable `tmp_name`. Pass entries shaped like `$_FILES`.
- `new Request()` and `Request::fromArray()` read a target without a leading slash as rooted (`admin` is `/admin`, `*` and `''` are `/`), and a target with a scheme other than http or https as a path, as `fromGlobals()` does. Pass a rooted path or an absolute http(s) URL.

#### Routing

- A static route now wins over a parameterized one, whatever the declaration order. Rename a parameterized route that relied on shadowing a static one.
- A placeholder must fill a whole path segment: `/files/{name}.pdf` throws `RouteSignatureException`. Declare `/files/{name}` and handle the extension in the action.
- A route path holding `?` or `#` throws at boot, and `{id?}` is refused. Declare the path before `?` and read the query string from the request; declare a second route without the optional segment.
- A path `parse_url()` cannot parse (such as `/x:80/admin`) is matched as written instead of as `/`. Declare a route for it if it must answer.
- A control character in a decoded route parameter answers 404; `RouteNotFoundException::refusalReason()` says why. Encode such values differently, or read them from the query string.
- `Router::name()` before any route throws `LogicException`. Call it after the route it names.
- The route cache refuses to load a file without its `meta` section or with a negative timestamp, and `save()` refuses duplicate route names and anything `load()` would refuse. Give every route a unique name and regenerate the cache with `save()`.
- The `RouteCacheException` factories are replaced by `RouteCacheException::refused($file, $problem)`, and `RouteDispatcher::dispatch()` is removed. Catch `RouteCacheException`, and use `match()` followed by `dispatchMatch()`.

#### Formatting and localization

- `format()` and translation pipes accept only built-in names (any case) and registered custom formatters; an unknown name throws `FormatterException`. Register the formatter, or fix its name.
- `format()` without a `Formatter` throws `FormatterException`. Call `App::setFormatter(new Formatter(...))` in command-line scripts and workers.
- `Formatter` throws for `money()` without a currency on a locale with no country (`fr`), a currency that is not three ASCII letters, a precision outside 0 to 20, a grouping separator other than `,` `.` `'` U+2019, a space, U+00A0, U+202F, U+2009 or empty, and a locale holding a NUL byte. Set `localization.currency` or use a full locale such as `fr_CA`.
- The `plural` pipe follows the plural rule of the resolved locale: in French, 0 takes the singular. Review catalog entries that render a count of zero.
- An unknown translation pipe, and a formatter pipe without a `Formatter`, throw `LocalizationException` instead of rendering the raw value. Fix the pipe name, and register a `Formatter` before translating.

#### Validation

- The `integer()` rule refuses booleans. Cast decoded JSON booleans explicitly before validating.
- `httpPath()` refuses protocol-relative paths (`//host`), backslashes, spaces and control characters, and the other rules refuse control characters. Normalize such input before validating, or reject it.

#### Mailer

- `MailerException` is built as `new MailerException($message, MailerFailure $failure, $previous)`; `invalidAddress()` takes the method name, `sendFailed()` takes no previous throwable, and partly refused recipients raise the new `RecipientsRefused` failure. Construct it with a `MailerFailure` case and branch on `$exception->failure`.
- `MailerException` messages carry no address and no transport text. Log `$exception->transportMessage()` where the server's reply is needed.
- `attach()` and `attachContent()` refuse a display name (or, without one, a file name) that is blank, `0`, `.` or `..`, longer than 255 bytes, ends with a dot, has surrounding spaces, or holds `/`, `\`, `=?`, control, bidi or line separator characters. Pass a plain name such as `report.pdf`.
- Attachment media types are limited to 127 bytes, and their parameters refuse reserved or non-ASCII names and `;` or `=` inside quoted values. Pass a plain type such as `application/pdf`.

#### Container

- A variadic constructor parameter is refused unless it is scalar-typed (which gets no argument); a class-typed variadic used to receive one instance. Bind the class with a factory.

#### Core

- With `application.debug` off, or without a configuration, Tracy runs in production mode, and secrets, credentials, authorization headers and the session cookie are masked in debug views. An application scrubber assigned after boot replaces the framework's: compose it with `DebugIntegration::isSensitiveKey()`.
- With `application.debug` on, the debugger is no longer shown to every client: only to loopback (not through a proxy) and to clients named by `withDebugClientAllowlist()`. Behind Docker the browser arrives from the bridge gateway: call `withDebugClientAllowlist('secret@<gateway-ip>')` and set the cookie `tracy-debug=<secret>`.
- `withDebugClientAllowlist()` and `DebugIntegration::initialize()` throw an `InvalidArgumentException` on an allowlist entry with an empty secret or address (`@172.17.0.1`, `secret@`), a range in the address (`10.0.0.0/8`), or an array entry that is not a string or holds whitespace or a comma. Write each entry as an exact address or `secret@address` with a non-empty secret.
- In the `production` and `staging` environments, `debug: true` is forced off with an error log line. Call `withProductionDebugAcknowledged()` on the builder if debug must stay on there.
- The unused `Zephyrus\Core\Kernel` class is removed. Build the application with `ApplicationBuilder`.
- The exception handler receives a non-null `Request`; a handler may type its parameter as `Request`, and may return `null` to fall back to the built-in response.
- Refused values are quoted the same way in every configuration, security, mailer, routing and formatting message. Update tests that compare exact messages.
- APIs that existed only between releases are removed: `DatabaseException::enableVerboseMessages()` and `verboseMessagesEnabled()` (messages are always terse), the `ZEPHYRUS_FORMAT_METHODS` constant (use `Formatter::BUILT_IN_FORMATTERS`) and `IpRange::shownEntry()`.

### Security

- `symfony/yaml` is required at `^7.4.23`, which fixes CVE-2026-123792: a YAML document could hold the parser for exponential or quadratic time. Run `composer update symfony/yaml`.
- Configuration is never read from request-supplied server variables.
- Sessions: strict mode is forced so a client-chosen id is never adopted, expiry is enforced, a destroyed session cannot be resurrected, concurrent writes are serialized, and a NUL byte can no longer truncate a stored payload.
- CSRF path exemptions are anchored.
- The client IP is resolved by walking the forwarded chain from the right against the trusted proxies; a malformed trusted `Forwarded` header yields no forwarded data.
- The routed path and host can no longer be steered by the `Host` header, the forwarded port or the request target, and a URL that cannot be parsed no longer becomes `http://localhost`.
- Encoded-path guard bypasses and route parameter constraint gaps are closed.
- Uploads are checked against their real bytes, must be genuine uploads, and caller-supplied paths are contained.
- Database connections always use native server-side prepares, and NUL bytes are refused in parameters and SQL text.
- Response headers, security headers, the content security policy, redirects and mail attachment headers refuse control characters, closing header injection.
- Secrets, credentials, mail recipients and bodies, driver messages, exception trace arguments and closure captures are masked in the debugger, dumps and the configuration export, and sensitive parameters are marked `#[\SensitiveParameter]`.
- Database credentials and configuration secrets are kept out of stack traces and connection error messages.
- Empty encryption keys and secrets that would fail open are refused.
- A debug client allowlist entry with an empty secret no longer admits a client without the `tracy-debug` cookie, an empty allowlist no longer matches an empty client address, and the entries are masked in stack traces and dumps.
- `Response::localRedirect()` and `Response::isLocalPath()` refuse protocol-relative and backslash targets, closing open redirects through user-supplied paths.
- `symfony/yaml` is raised above the releases with parser denial-of-service advisories.

### Added

- `security.headers` configuration section, `security.trustedHeaders`, `session.idle_timeout`, `localization.grouping_separator`, `database.columnCacheVersion`, and opt-in `database.sslMode` and `sslRootCert`.
- `EnvironmentContract`, to refuse an unrecognised environment value at boot.
- `MaxBodySizeMiddleware`, and the security wiring check with `withAcknowledgedSecurityKeys()` and `withoutSecurityWiringCheck()`. A refused body is a 413 `text/plain` for a browser and JSON otherwise, or the application's own answer through the `onFailure` callback and `BodyTooLarge`.
- `ApplicationBuilder::withDebugClientAllowlist()`, to name the clients that see the debugger, and `withProductionDebugAcknowledged()`, to keep debug on in `production` or `staging`.
- Per-request CSP nonce and the `nonce()` helper; the `e()` escaping helper; the `route()` URL helper.
- `CsrfConfig::fromSecurityConfig()`, the `CsrfMiddleware` `onFailure` callback and `CsrfFailure`.
- `ExceptionEvent`, so error reporting can listen to every converted exception.
- `Router::withoutMiddleware()`, `#[WithoutMiddleware]`, global middleware exclusions on `group()` and `resource()`, and `MiddlewarePipeline::without()`.
- `RouteCollection::routesForPath()`, `Request::route()`, `RouteNotFoundException::refusalReason()` and `RoutePathRefusal`.
- `Response::localRedirect()`, `isLocalPath()`, `isValidHeaderValue()`, `getHeader()`, `hasHeader()`, `hasNonBlankHeader()` and `withoutHeader()`.
- `IpRange`, the CIDR checker shared by every IP setting.
- `RequestBody::isMalformed()` for a JSON body that cannot be decoded.
- `Mailer::attachContent()` for in-memory attachments, and the `MailerFailure` enum.
- `ApplicationBuilder::withRenderEngine()`, and on `KernelBuilder` `withRenderEngine()`, `namedMiddlewares()`, `hasGlobalMiddleware()` and `globalMiddlewaresOf()`.
- `DatabaseSessionHandler` accepts a closure that resolves the database on first use.
- `ConfigurationException::section()`, `field()`, `reason()`, `path()` and `messageWithoutValue()`.
- The `Binary` query parameter value.

### Changed

- Error responses run through the global middleware pipeline, so the session starts on a 404.
- CSRF answers 404 instead of 403 when no route matched.
- `timeago`, `duration`, `filesize` and `list` write French for a French locale.
- `ZephyrusException::getFile()` and `getLine()` report the throw site rather than the static factory.
- A session payload that is not valid UTF-8 or holds a NUL byte is stored base64-encoded.
- Anchored validation patterns no longer accept a trailing newline.
- `text()` and `html()` on the mailer can be called in either order.
- The container resolves an anonymous class that is already loaded.
- PHPStan runs at level 8 on `src/`.

### Deprecated

- The `CsrfConfig` `injectToken` argument and the `SecurityConfig` `csrfAutoHtml` argument, since 0.14, removed in 0.15. Leave `injectToken` unset (`true` throws) and pass `csrfAutoHtml: false` (nothing reads the value).

### Fixed

- Every `ExceptionEvent` listener runs even when an earlier one throws.
- Native `$_FILES` single-file entries are normalized in `Request`, `file()` and `fromArray()`.
- Port parsing refuses leading zeros, port 0 and a trailing newline, and omits the server port when a proxy supplies the scheme.
- An empty currency in `money()` is treated as no currency.
- A translation pipe on an empty value lets `default` apply.
- Custom formatters registered under a numeric name, and built-in overrides registered in any case, resolve correctly.
- A mixed-case `http` scheme is redirected to https.
- `SapiEmitter` refuses a chunk size of zero or less.

## [0.13.0] - 2026-08-15

Earlier releases have no notes.

[Unreleased]: https://github.com/ophelios-studio/zephyrus-core/compare/v0.16.0...HEAD
[0.16.0]: https://github.com/ophelios-studio/zephyrus-core/compare/v0.15.0...v0.16.0
[0.15.0]: https://github.com/ophelios-studio/zephyrus-core/compare/v0.14.1...v0.15.0
[0.14.1]: https://github.com/ophelios-studio/zephyrus-core/compare/v0.14.0...v0.14.1
[0.14.0]: https://github.com/ophelios-studio/zephyrus-core/compare/v0.13.0...v0.14.0
[0.13.0]: https://github.com/ophelios-studio/zephyrus-core/releases/tag/v0.13.0
