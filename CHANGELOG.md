# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). Before 1.0, a minor version may break the API: those
changes are listed first, each with the change to make in your code.

## [Unreleased]

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
- `KernelBuilder::build()` refuses a global `ContentSecurityPolicyMiddleware` with an enforced policy registered before a `SecureHeadersMiddleware` whose `csp` is set. Leave the `SecureHeadersMiddleware` `csp` empty, or register the CSP middleware after it.
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
- In the `production` and `staging` environments, `debug: true` is forced off with an error log line. Call `withProductionDebugAcknowledged()` on the builder if debug must stay on there.
- The unused `Zephyrus\Core\Kernel` class is removed. Build the application with `ApplicationBuilder`.
- The exception handler receives a non-null `Request`; a handler may type its parameter as `Request`, and may return `null` to fall back to the built-in response.
- Refused values are quoted the same way in every configuration, security, mailer, routing and formatting message. Update tests that compare exact messages.
- APIs that existed only between releases are removed: `DatabaseException::enableVerboseMessages()` and `verboseMessagesEnabled()` (messages are always terse), the `ZEPHYRUS_FORMAT_METHODS` constant (use `Formatter::BUILT_IN_FORMATTERS`) and `IpRange::shownEntry()`.

### Security

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
- `Response::localRedirect()` and `Response::isLocalPath()` refuse protocol-relative and backslash targets, closing open redirects through user-supplied paths.
- `symfony/yaml` is raised above the releases with parser denial-of-service advisories.

### Added

- `security.headers` configuration section, `security.trustedHeaders`, `session.idle_timeout`, `localization.grouping_separator`, `database.columnCacheVersion`, and opt-in `database.sslMode` and `sslRootCert`.
- `EnvironmentContract`, to refuse an unrecognised environment value at boot.
- `MaxBodySizeMiddleware`, and the security wiring check with `withAcknowledgedSecurityKeys()` and `withoutSecurityWiringCheck()`.
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
- `KernelBuilder::withRenderEngine()`, `namedMiddlewares()`, `hasGlobalMiddleware()` and `globalMiddlewaresOf()`.
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

[Unreleased]: https://github.com/ophelios-studio/zephyrus-core/compare/v0.13.0...HEAD
[0.13.0]: https://github.com/ophelios-studio/zephyrus-core/releases/tag/v0.13.0
