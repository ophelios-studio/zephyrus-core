# Upgrading from 0.13 to 3.17.1

This guide takes an application that runs on Zephyrus 0.13.0, 0.14, 0.15, 0.16.0 or a `dev` commit between them to
3.17.1. It lists every change that can make the application refuse to boot, throw or answer differently, grouped by
domain, each with the change to make and a way to check it. The [changelog](CHANGELOG.md) has the full list by version.

Each entry names the version that brought it. Skip the entries your current version already has; an application on a
`dev` commit between 0.13.0 and 0.14.0 may already carry part of 0.14.0, so use the **Check** line to tell.

## Contents

1. [From 3.17.0 to 3.17.1](#from-3170-to-3171)
2. [Upgrade procedure](#upgrade-procedure)
3. [Requirements](#requirements)
4. [Configuration and boot](#configuration-and-boot)
5. [Sessions](#sessions)
6. [CSRF](#csrf)
7. [Routing](#routing)
8. [HTTP](#http)
9. [Security: headers, proxies, hosts and the debugger](#security-headers-proxies-hosts-and-the-debugger)
10. [Views](#views)
11. [Data](#data)
12. [Validation](#validation)
13. [Formatting and localization](#formatting-and-localization)
14. [Files and uploads](#files-and-uploads)
15. [Mail](#mail)
16. [Container, exceptions and removed APIs](#container-exceptions-and-removed-apis)
17. [What to search for](#what-to-search-for)

## From 3.17.0 to 3.17.1

### Request body read limit (3.17.1)

**Breaks.** `Request::fromGlobals()` reads at most 2 MB of the body (2097152 bytes) when it is not given `maxBodySize`,
where it read the whole body into memory. A body over that read limit, or a declared `Content-Length` over it, never
reaches a controller; a multipart body sent without a `Content-Length` is measured on its fields and files.
`MaxBodySizeMiddleware` refuses it through its `onFailure` when it is mounted, and the kernel answers `413` otherwise.
An application that declares `security.max_body_size` above 2097152 and calls `fromGlobals()` without `maxBodySize:`
now refuses its forms and uploads between 2 MB and its limit, and reports each one to `ExceptionEvent` listeners, then
to the debugger or, without one, as this line in the error log:

```text
Zephyrus: Request::fromGlobals() read the body with a limit of 2097152 bytes, but security.maxBodySize allows 8388608: pass maxBodySize: $config->security->maxBodySize to Request::fromGlobals().
```

An application that declares no `max_body_size` but takes bodies over 2 MB (an upload limit set only by
`post_max_size`) refuses them the same way, with no report.

**Change.** Pass the configured limit, also when the body is injected with `rawBody:` (without a declared
`max_body_size`, pass the limit the application accepts; `maxBodySize: 0` reads any body whole into memory):

```php
$request = Request::fromGlobals(
    trustedProxies: $config->security->trustedProxies,
    maxBodySize: $config->security->maxBodySize,
);
```

Mount `MaxBodySizeMiddleware` before `CsrfMiddleware`: a truncated form reads as empty, so a CSRF check that runs
first answers `403` for its missing token, not `413`.

**Check.** `rg -n 'fromGlobals\('` lists the calls; each passes `maxBodySize:` when the application accepts bodies over
2097152 bytes (`max_body_size`, or `post_max_size` when it declares none). An upload just under that limit passes, and
the error log has no `Zephyrus: Request::fromGlobals() read the body` line.

## Upgrade procedure

1. Change the constraint in `composer.json`. Composer does not move from `^0.16` (or a `dev-dev#<commit>` pin) to
   3.17 on its own:

   ```json
   "zephyrus-framework/core": "^3.17"
   ```

2. Update the framework and the libraries it now requires at a higher version (Latte 3.1, `symfony/yaml` 7.4.23):

   ```bash
   composer update zephyrus-framework/core latte/latte --with-dependencies
   ```

3. Run the searches of [What to search for](#what-to-search-for) from the application root, and fix each hit with
   the entry it points to.
4. Start the application in development and request a page. Most refusals are raised when the application is built,
   so on the first request, and each one names its fix. Fix them one by one until the page renders, and read the PHP
   error log for lines starting with `Zephyrus:`.

   ```bash
   APP_ENV=development APP_DEBUG=true php -S 127.0.0.1:8000 -t public
   ```

5. Warm the caches. `vendor/bin/zephyrus cache:warm` must exit `0`. It checks the YAML and the `router:` section
   only, so it does not replace step 4.
6. Run the application's test suite. Tests that compare an exception message or a response body to an exact text
   are the most likely to fail: see [Exception messages](#exception-messages-0140-3170).
7. Check by hand: sign in and out, submit a form that carries the CSRF token, load a page that writes to the session
   twice in a row, upload a file if the application accepts uploads, and request an unknown path (it must answer 404).

## Requirements

### PHP extensions (0.14.0)

**Breaks.** `ext-fileinfo` is required, beside `mbstring`, `pdo`, `intl` and `sodium`: Composer refuses to install
without it.

**Change.** Enable `fileinfo` in every PHP image, the web server's and the command line's.

**Check.** `php -m` lists the five extensions, in each image:

```bash
php -m | rg -ix 'fileinfo|intl|mbstring|pdo|sodium'
```

### Latte 3.1 (3.17.0)

**Breaks.** `latte/latte` must be `^3.1`: Zephyrus no longer installs beside Latte 3.0.

**Change.** `composer update latte/latte`. Check a Latte loader or extension of your own against the Latte 3.1 release
notes.

**Check.** `composer show latte/latte` prints `versions : * v3.1.` followed by a patch number.

### PHP 8.6 (3.17.0)

**Breaks.** On PHP 8.6, `composer install` refuses a lock that holds `latte/latte` before 3.1.7, `tracy/tracy` before
2.12.2, `nette/utils` before 4.1.6 or `nette/schema` before 1.3.7: their `php` range stops at 8.5.

**Change.** Step 2 of the procedure moves them. A `tracy/tracy` required at the root of your `composer.json`, or a
`nette/*` package locked by another library, needs `composer update ... -W`.

**Check.** `composer install` succeeds on PHP 8.6.

### symfony/yaml 7.4.23 (0.14.0)

**Breaks.** `symfony/yaml` must be `^7.4.23` (earlier releases carry parser denial-of-service advisories).

**Change.** `composer update symfony/yaml`, or step 2 of the procedure.

**Check.** `composer show symfony/yaml` prints a version of 7.4.23 or later.

### Results of with*() on PHP 8.5 and later (3.17.0)

**Breaks.** The `with*()` methods that return a modified copy (`Request`, `Response`, `Route`, `Router`,
`RouteCollection`, `FormValidator`, `MiddlewarePipeline::without()`, `PaginationRequest`, `KernelBuilder`,
`ApplicationBuilder`, `ContentSecurityPolicy`) carry `#[\NoDiscard]`. On PHP 8.5 and later, a call whose result is
dropped raises a warning, and changed nothing anyway. PHP 8.4 ignores the attribute.

**Change.** Keep the result. In a test that expects the call to throw, assign it (`(void)` is PHP 8.5 syntax):

```php
$response = $response->withHeader('Cache-Control', 'no-store');
$ignored = $builder->withMiddleware($middleware);
```

**Check.** List the candidates, then confirm that each one on a Zephyrus object keeps its result (assigned, or prefixed
with `(void)`). The search also lists your own `with*()` methods, which need no change:

```bash
rg -n '^\s*\$\w+->with[A-Z]\w*\(' app public tests
```

Then run the test suite once on PHP 8.5 or later with warnings made failures: a dropped result raises an
`E_USER_WARNING`, and none is raised when the result is assigned or prefixed with `(void)`.

## Configuration and boot

### Unknown sections and keys are refused (0.14.0, 0.15.0, 3.17.0)

**Breaks.** `config.yml` is read strictly. A top-level key close to a built-in section name, an unknown key in a
built-in section (`application`, `session`, `security`, `localization`, `database`, `router`), in the `csrf`,
`encryption` and `headers` mappings of `security`, or in a `render:` or `mailer:` section, and a setting written in two
spellings, each throw `ConfigurationException` at boot. A dotted key written flat (`smtp.host:` under `mailer:`) is
refused too.

```text
Configuration key 'sesion' is not a known section: did you mean "session"? A section of your own needs a factory registered under its name in the $sectionFactories argument of the Configuration factories.
Configuration section 'session' field 'idle_timout' is an unknown key: did you mean "idle_timeout"?
Configuration section 'security' sets both 'maxBodySize' and 'max_body_size': keep one.
Configuration section 'mailer' field 'smtp.prot' is an unknown key: did you mean "smtp.port"?
Configuration section 'mailer' field 'smtp.host' is written flat: nest it under 'smtp', such as smtp: { host: ... }.
```

**Change.** Fix the spelling the message suggests, delete a key nothing reads, keep one spelling per setting, and nest
dotted keys:

```yaml
mailer:
  smtp:
    host: !env MAIL_HOST, localhost
```

An unknown key under `mailer:` or `render:` throws at boot only when `MailerConfig` or `RenderConfig` is declared in
`$sectionFactories`; without a factory for the section, nothing is thrown.

A section of your own whose name is close to a built-in one needs a factory in the `$sectionFactories` argument
(`Configuration::fromYamlFile($path, ['payments' => PaymentsConfig::class])`), or another name. A top-level key far
from every built-in name, with no factory, is still ignored.

**Check.** The application boots without `ConfigurationException`.

### The router section name is reserved (0.16.0)

**Breaks.** `router:` is the built-in routing section: a `router:` key in `config.yml` is read and validated, a section
factory registered under `router` throws, and a section named `routes` is refused as a typo of `router`.

```text
Section factory "router" collides with the built-in section "router"; read it with $configuration->router instead.
Configuration key 'routes' is not a known section: did you mean "router"? A section of your own needs a factory registered under its name in the $sectionFactories argument of the Configuration factories.
```

**Change.** Rename a section of your own named `router` or `routes` (`routing`, for instance), in `config.yml` and in
its factory. To use the built-in section, see [Routes cache and the router section](#routes-cache-and-the-router-section-0160).

**Check.** `rg -n '^route' config.yml` finds only the built-in `router:` section, if any.

### Boolean settings are strict (0.14.0)

**Breaks.** A boolean setting (`application.debug`, session `httpOnly` and `secure`, `security.forceHttps`,
`security.csrf.enabled`, `security.headers.hstsIncludeSubdomains`, `ConfigSection::getBool()`) accepts only a boolean,
`0`/`1`, `true`/`false`, `on`/`off` or `yes`/`no`, in any case. Anything else, an empty string and `null` throw; the
string `'false'` now reads as false. An unset `!env` without a default reads as `null`, so it throws too.

```text
Configuration section 'application' field 'debug' has invalid value "": is not a boolean; use true/false, 1/0, on/off or yes/no.
Configuration section 'application' field 'debug' has invalid value null: is not a boolean; use true/false, 1/0, on/off or yes/no; an unset !env without a default reads as null; add a default, such as !env NAME, false.
```

**Change.** Give every boolean `!env` a default, and delete a boolean key declared empty. Session `secure: null` still
means `auto`.

```yaml
application:
  debug: !env APP_DEBUG, false
```

**Check.** The application boots with the variable unset.

### Security settings: mappings and formats (0.14.0)

**Breaks.** Under `security:`, `csrf`, `encryption` and `headers` must be mappings, and `encryption.key` a string. The
response headers moved under `security.headers`: a header key written directly under `security:` is an unknown key.
`max_body_size` is a number of bytes. `allowed_hosts` and `trusted_proxies` accept a list or a comma-separated string,
but an `allowed_hosts` that names no host (`''`) or is declared `null` throws.

```text
Configuration section 'security' field 'csrf' must be a mapping, such as csrf: { enabled: false }.
Configuration section 'security' field 'xFrameOptions' is an unknown key: the accepted keys are forceHttps, csrf.enabled, csrf.exceptions, allowedHosts, maxBodySize, trustedProxies, trustedHeaders, encryption.key, headers.
Configuration section 'security' field 'max_body_size' has invalid value "2M": must be a number of bytes, for example 2097152 (no unit suffix).
Configuration section 'security' field 'allowedHosts' has invalid value null: an empty list is written [].
```

**Change.**

```yaml
security:
  max_body_size: 2097152
  allowed_hosts: []
  csrf:
    enabled: true
  encryption:
    key: !env ENCRYPTION_KEY
  headers:
    x_frame_options: DENY
    referrer_policy: strict-origin-when-cross-origin
```

The `headers` keys are `xFrameOptions`, `xContentTypeOptions`, `referrerPolicy`, `xssProtection`, `hstsMaxAge`,
`hstsIncludeSubdomains`, `csp` and `permissionsPolicy`, or their snake_case forms; `hstsMaxAge` is a whole number of
seconds. A value holding a control character is refused.

**Check.** The application boots, and `curl -sI http://127.0.0.1:8000/` shows the configured headers once
`SecureHeadersMiddleware` is mounted (see [The security wiring check](#the-security-wiring-check-0140)).

### Environment variables (0.14.0, 3.17.0)

**Breaks.** `env()`, the `!env` tag, `#[RequiresEnv]` and the bootstrap variables (`APP_ENV`, `APP_CONFIG_*`) read
only `$_ENV` and the process environment, never `$_SERVER`: a value set with Apache `SetEnv` or `fastcgi_param` is no
longer seen. A name a request or the web server can supply (`HTTP_*`, `REDIRECT_*`, `ORIG_*`, `SSL_*`, `H2_*`, CGI and
server names such as `REMOTE_ADDR`, `QUERY_STRING`, `HTTPS`, `SERVER_NAME`, `PHP_SELF`, `argv`, `argc`), in any case,
and a name holding `=` or a NUL byte are refused.

```text
!env tag at "application.environment": the variable name is refused: names starting with HTTP_ can be set per request by the client request headers, so they are never read as configuration; rename the variable.
!env tag at "application.environment": the variable name is refused: names must not contain a NUL byte or "=".
```

`#[RequiresEnv('NAME', '')]` now matches a variable set to an empty string, and a `false` placed in `$_ENV` reads as
`"0"` (3.17.0).

**Change.** Set the variables in the real process environment (container environment, php-fpm `env[NAME]`), and
rename a refused variable.

**Check.** `rg -n 'SetEnv|fastcgi_param' .` (in the web server configuration) finds no application setting, and the
application boots with the expected environment.

### Configuration API (0.14.0)

**Breaks.**
- `Configuration::section()` throws `InvalidArgumentException` for a built-in section name in any spelling, and
  `config()` for any spelling but the exact one (`config('Database')`):
  `Section "database" is a built-in typed property; use $configuration->database or config('database') instead.`
- A section factory registered under a blank name, a built-in name in any spelling, or a name another factory already
  uses (in any spelling) throws `InvalidArgumentException`.
- `ConfigSection::has()` returns `true` for a key explicitly set to `null`.
- `Configuration::toArray()` no longer exports `security.csrfAutoHtml`.

**Change.** Read a built-in section through its property (`$configuration->database`) or `config('database')`; test
`get('key') !== null` where `null` meant absent; stop reading `csrfAutoHtml`.

**Check.** `rg -n "section\('" app` and `rg -n "config\('[A-Z]" app` find no built-in section name.

### Configuration files (3.17.0)

**Breaks.**
- `Configuration::fromFile()`, `fromFiles()` and `fromOptionalFiles()` refuse a file whose extension is not `.yml`,
  `.yaml` or `.php`, where they ran it as PHP.
- A configuration file is read only from a local path: a stream wrapper URL (`phar://`, `file://`, `data:`) is
  refused, found or not, even as an optional file.
- A file whose content is not a mapping (a YAML scalar or list, a PHP file that returns a list) is refused, where the
  application started on the defaults. An empty or comment-only YAML file still reads as empty.
- A YAML syntax error no longer quotes the file: the message gives the line only, `getPrevious()` is `null`, and
  `ConfigurationException::parseFailed()` takes the line (`?int`) instead of the parser's exception.
- An unreadable file is reported as not readable, where it was reported as invalid YAML.

```text
Configuration file config.json: must be YAML (.yml, .yaml) or PHP (.php)
Configuration file must be a local path, not a stream wrapper URL (phar://)
Configuration file config.yml: must be a mapping of sections
Failed to parse configuration file [config.yml]: invalid YAML near line 4 (the parser's detail is withheld, since it can quote a secret from the file).
Configuration file is not readable: config.yml
```

**Change.** Rename a PHP configuration file to `.php`, pass plain paths, and write the configuration as `section:`
keys. Code that read the YAML line from `getPrevious()` reads it from the message.

**Check.** The application boots, and `vendor/bin/zephyrus cache:warm` exits `0`.

### Relative paths follow the configuration file (0.16.0, 3.17.0)

**Breaks.** `ApplicationBuilder::fromConfigurationFile()`, `buildFromConfigurationFile()`, `withConfigurationFile()`,
`withConfigurationFiles()`, `withOptionalConfigurationFiles()`, `fromConfigurationFiles()`,
`fromOptionalConfigurationFiles()` and the `buildFrom...Files()` entries take the real path of the directory of the
(first existing) file as the application root. A relative `localization.locale_path` resolves against that directory,
where it resolved against the current directory.

**Change.** Write `locale_path` relative to the configuration file, or absolute.

**Check.** A translated page renders its translations, not their keys.

### The security wiring check (0.14.0)

**Breaks.** `ApplicationBuilder::build()` throws `ConfigurationException` when a declared `security:` setting is not
enforced by a global middleware built from the same value: `forceHttps: true`, a non-empty `allowed_hosts`,
`max_body_size` above 0, a `headers` mapping, and `csrf` when any of its keys is declared and it is enabled. Only a
middleware passed to `withMiddleware()` on the builder that is built counts: not one registered under a route name,
nor one wrapped in another middleware, nor one whose `withMiddleware()` result was dropped.

```text
Configuration declares security settings that this application does not enforce as declared:
  - security.maxBodySize is not enforced as declared: mount new Zephyrus\Security\MaxBodySizeMiddleware(...) globally with withMiddleware(), built from the configuration's security->maxBodySize

Apply the fix given for each setting, or acknowledge the gap explicitly with ApplicationBuilder::withAcknowledgedSecurityKeys([...]) when the protection is provided elsewhere (a reverse proxy, a wrapping middleware, the web server).
```

**Change.** Mount each middleware from the configuration and keep every returned clone. Session before CSRF:

```php
$config = Configuration::fromYamlFile($root . '/config.yml');
$session = new SessionManager();

$builder = ApplicationBuilder::create()->withConfiguration($config, basePath: $root);
$builder = $builder->withMiddleware(new ForceHttpsMiddleware());
$builder = $builder->withMiddleware(new AllowedHostsMiddleware($config->security->allowedHosts));
$builder = $builder->withMiddleware(new MaxBodySizeMiddleware($config->security->maxBodySize));
$builder = $builder->withMiddleware(new SecureHeadersMiddleware($config->security->headers));
$builder = $builder->withMiddleware(new SessionMiddleware($config->session, $session));
$builder = $builder->withMiddleware(new CsrfMiddleware(
    new SessionCsrfTokenManager($session),
    CsrfConfig::fromSecurityConfig($config->security),
));
```

A protection enforced elsewhere is acknowledged by name (`forceHttps`, `allowedHosts`, `csrf`, `maxBodySize`,
`headers`, bare or prefixed with `security.`); any other name throws `InvalidArgumentException`:

```php
$builder = $builder->withAcknowledgedSecurityKeys(['forceHttps']);
```

**Check.** The application boots in development without this message.

### Removed Kernel class (0.14.0)

**Breaks.** The unused `Zephyrus\Core\Kernel` class is removed.

**Change.** Build the application with `ApplicationBuilder`.

**Check.** `rg -n 'Core\\Kernel\b' app public tests` finds nothing.

### Boot caches and cache:warm (0.16.0, 3.17.0)

**Breaks.** Nothing refuses to boot, but `Configuration::fromYamlFile()` now reads the parsed YAML from
`var/zephyrus/<file name>.php` beside the file when that cache matches the file, and `vendor/bin/zephyrus cache:warm`
writes it (and the routes cache, with a `router:` section). `cache:warm` exits `0` on success, `1` when it refuses
and `2` on a usage error; it reads no `.env` and resolves no `!env`. It refuses two caches at the same path
(`router.cache: var/zephyrus/config.yml.php`). Since 3.17.0, the cache of a `config.yml` that is a symbolic link is
written beside the link, not beside its target.

**Change.** Run the command in the image build, after `COPY` and `composer dump-autoload`, before any `USER`, on a
path no later `chown` touches. Keep `var/zephyrus/` out of the image context (`.dockerignore`); the command writes
`var/zephyrus/.gitignore`. After an upgrade from 0.16.0 with a linked `config.yml`, run `cache:warm` again.

```dockerfile
RUN php vendor/bin/zephyrus cache:warm
```

**Check.** `vendor/bin/zephyrus cache:warm; echo $?` prints `Wrote .../var/zephyrus/config.yml.php` and `0`.

## Sessions

### SessionManager throws SessionException (0.14.0)

**Breaks.** `SessionManager::start()`, `regenerate()`, `destroy()` and `setHandler()` throw `SessionException` when
PHP refuses the operation, when output was already sent, or when destroying a closed session that still has an id.
`set()`, `remove()` and `session([...])` throw when no session is active; `get()` still returns the default.
`SessionException` factories take PHP's warning as `?\ErrorException` instead of `?string`.

```text
Cannot write "a" to the session: no session is active. Run SessionMiddleware on this route, or call start(); in a test, pass an array to new SessionManager([]).
The session cannot start: output has already been sent, so the session cookie cannot be set. Start the session before any output.
Cannot regenerate the session id: no session is active. Run SessionMiddleware on this route, or call start(); in a test, pass an array to new SessionManager([]).
```

**Change.** Mount `SessionMiddleware` on every route that writes to the session, start before any output, and catch
`SessionException` where a fallback must still run (sign-out, error pages):

```php
try {
    $session->destroy();
} catch (SessionException $exception) {
    error_log('Sign-out: ' . $exception->getMessage());
}
```

A test that writes to a session without starting one passes an array: `new SessionManager([])`. Code that builds a
`SessionException` passes the `ErrorException`, or reads the reason through `getPrevious()`.

**Check.** Sign in and out; a page that writes to the session answers `200`.

### Strict mode and expiry are enforced (0.14.0)

**Breaks.** Strict mode is forced: a session id the server does not know is never adopted, so a test or a client that
sets the session cookie by hand gets a new session. Expiry is enforced, a destroyed session cannot be resurrected, and
error responses now run through the global middlewares, so the session starts on a 404 too. A payload that is not
valid UTF-8 or holds a NUL byte is stored base64-encoded.

**Change.** Obtain a session id from a real response (the `Set-Cookie` of a first request) instead of making one up.

**Check.** Two requests that share a cookie jar keep the session: a page that increments a counter stored in the
session (here `/count`) prints `1`, then `2`.

```bash
curl -s -c jar -b jar http://127.0.0.1:8000/count; curl -s -c jar -b jar http://127.0.0.1:8000/count
```

### DatabaseSessionHandler: locking and concurrent writes (0.14.0, 0.15.0)

**Breaks.** `DatabaseSessionHandler` takes its row lock under a savepoint inside the caller's transaction, waits at most
five seconds for it, and stores no row for an empty new session. It refuses to write a resumed session whose row
changed or was deleted since this request read it: the later of two concurrent requests loses its changes, with PHP's
`Failed to write session data` warning and a `Zephyrus:` line in the error log, instead of overwriting the first.
`write()` and `updateTimestamp()` return `false` with an `E_USER_WARNING` for an id this instance never read.

**Change.** Behind PgBouncer in transaction pooling, construct the handler with `lockSessions: false`. Call
`$session->regenerate()` when privileges change (sign-in, role or password change). A handler that wraps
`DatabaseSessionHandler` forwards `open()`, `read()` before writing, and `close()`.

**Check.** The sign-in flow works, and the error log holds no `Failed to write session data` line for single requests.

### DatabaseSessionHandler issues the session ids (3.17.0)

**Breaks.** `DatabaseSessionHandler` implements `SessionIdInterface` and generates new ids itself: 32 lowercase
hexadecimal characters, whatever `session.sid_length` and `session.sid_bits_per_character` say. The constructor checks
a custom `idPattern` against ids of that shape, and refuses one that is not a valid regular expression.

```text
DatabaseSessionHandler issues session ids of 32 lowercase hexadecimal characters, and the idPattern "/\\A[a-v0-9]{48}\\z/" refuses them, so no new session could be stored. Pass a pattern that also accepts them.
The idPattern "/abc" is not a valid regular expression: No ending delimiter '/' found.
```

**Change.** Make a custom pattern accept these ids too; stored sessions keep resuming while the pattern accepts their
id. A handler that wraps `DatabaseSessionHandler` delegates `create_sid()` and `validateId()` too, and marks `$id` and
`$data` `#[\SensitiveParameter]`.

```php
$handler = new DatabaseSessionHandler($database, idPattern: '/\A(?:[a-v0-9]{48}|[0-9a-f]{32})\z/');
```

**Check.** The handler is constructed without `SessionException`, and a new session is stored (a row appears after the
first request that writes to it).

## CSRF

### Automatic token injection is removed (0.14.0)

**Breaks.** Zephyrus no longer adds the token to forms. `security.csrf.auto_html: true` throws at boot, and so does
`new CsrfConfig(injectToken: true)`. The `CsrfConfig` `injectToken` and `SecurityConfig` `csrfAutoHtml` arguments
are still accepted, deprecated, and must stay `false`.

```text
Configuration section 'security' field 'csrf.auto_html' has invalid value true: remove this line. Automatic token injection is not supported because it cannot follow the browser's HTML parsing and could send the token to another site. Add a hidden "_csrf_token" field to your form templates, filled with the escaped value of CsrfTokenManagerInterface::getToken().
```

**Change.** Delete `auto_html` from `config.yml`, and render the hidden field in every form that changes state. Pass
the token to the view (`$tokens->getToken()` of the `SessionCsrfTokenManager` given to `CsrfMiddleware`):

```latte
<form method="post" action="/profile">
    <input type="hidden" name="_csrf_token" value="{$csrfToken}">
</form>
```

**Check.** `rg -ni 'auto_?html' config.yml` and `rg -n 'injectToken' app` find nothing, and the page source of each
form holds `name="_csrf_token"`; submitting it answers `200`, not `403`.

### Exemptions must be anchored regular expressions (0.14.0)

**Breaks.** Each entry of `security.csrf.exceptions` (and `CsrfConfig` `excludedPathPatterns`) is a delimited PCRE
that starts with `^` or `\A` and ends with `/`, `$`, `\z` or `\Z`. Anything else throws `InvalidArgumentException`
when the middleware is built.

```text
security.csrf.exceptions[0] is not a valid regex: /webhooks (No ending delimiter '/' found; expected a shape such as #^/webhooks/#).
CSRF excluded path pattern at index 0 must end with "/" (the route and everything under it) or "$" (that exact path), otherwise it also exempts every sibling route sharing the prefix: #^/api/public# (expected a shape such as #^/webhooks/#).
```

**Change.**

```yaml
security:
  csrf:
    exceptions:
      - '#^/webhooks/#'
      - '#^/logout$#D'
```

**Check.** The application boots, and a `POST` to an exempt path without a token is not answered `403`.

### The refusal response (0.14.0)

**Breaks.** A CSRF refusal answers `403` with a `text/plain` body to a browser (`Accept` containing `text/html`), JSON
otherwise. A `POST` to a path no route matches answers `404`, not `403`.

**Change.** To render a page of your own, pass `onFailure` to `CsrfMiddleware` instead of matching the body; returning
`null` keeps the default answer:

```php
new CsrfMiddleware($tokens, CsrfConfig::fromSecurityConfig($config->security),
    onFailure: fn (Request $request, CsrfFailure $failure): ?Response => Response::html($page, 403));
```

**Check.** A `POST` without a token to a route that accepts one (here `/form`) answers `HTTP/1.1 403 Forbidden` and
`{"error":"Invalid or missing CSRF token."}`:

```bash
curl -s -i -H 'Accept: application/json' -d 'a=1' http://127.0.0.1:8000/form
```

## Routing

### Route paths (0.14.0)

**Breaks.** A placeholder must fill a whole path segment, a path cannot hold `?` or `#`, and optional placeholders
(`{id?}`) are refused, each with `RouteSignatureException` when the route is registered.

```text
Invalid route path "/files/{name}.pdf": segment "{name}.pdf" mixes a placeholder with text; capture the whole segment with a constraint or give the placeholder its own segment
Invalid route path "/x?y=1": the request path ends at "?", so this route would only match "/x"; declare "/x" and read the query string from the request
Invalid route parameter name "id?" on route "/x/{id?}": optional placeholders are not supported; declare a second route without that segment
```

**Change.** Declare `/files/{name}` and handle the extension in the action; read the query string from the request;
declare a second route without the optional segment.

**Check.** The application boots in development (routes are discovered on every request there).

### Route matching (0.14.0)

**Breaks.** A static route wins over a parameterized one, whatever the declaration order (`/users/me` wins over
`/users/{id}` declared before it). A path `parse_url()` cannot parse (`/x:80/admin`) is matched as written instead of as `/`. A control
character in a decoded route parameter answers `404` (`RouteNotFoundException::refusalReason()` says why).

**Change.** Rename a parameterized route that relied on shadowing a static one; read values that may hold control
characters from the query string.

**Check.** A static route beside a parameterized one (`/users/me` beside `/users/{id}`) answers from its own action.

### Route names, methods and middleware names (0.14.0, 0.15.0, 3.17.0)

**Breaks.**
- `Router::name()` before any route throws `LogicException`: `name("home") must follow a route: add one before naming it.`
- Two routes sharing a name make `build()` throw `DuplicateRouteNameException` (`Duplicate route names detected:
  shared`), where a duplicate between two classes was accepted. `RouteCollection::assertNoDuplicateRouteNames()`
  throws it too, so `catch (RouteSignatureException)` around it no longer catches it.
- A route that names a middleware that is not registered makes `build()` throw `RouteMiddlewareException`, where the
  request failed with a 500.
- A route method that is not an RFC 9110 token is refused when registered.

```text
Route middleware is not registered: "auth" on GET /x; no middleware is registered. Register it with registerMiddleware() before build(), or, for a group, define it with Router::middlewareGroup() before the routes that name it.
Invalid route method "GE T" on route "/x": an HTTP method is a token of letters, digits and !#$%&'*+-.^_`|~ (RFC 9110), such as GET or PROPFIND
```

**Change.** Name a route after adding it, give every route a unique name, register each middleware name with
`registerMiddleware()` (or define the group) before `build()`, and correct the method.

**Check.** The application boots in development.

### Routes cache and the router section (0.16.0)

**Breaks.** `RouteCache` is `@internal` and its public API is gone (`new RouteCache($file)`, `has()`, `clear()`,
`save()`, `warm()`, `isFresh()`, `metadata()`, `warmIfStale()` and the others), with the `RouteCacheException`
factories but `noRoutes()`. An application that cached its routes itself moves to the `router:` section. One that
calls `discoverControllers()` keeps working; the section is what makes production read a routes cache.

**Change.** Declare the controllers in `config.yml`, pass `basePath`, and delete the `discoverControllers()` and
`withRouter()` lines. Values under `router:` cannot use `!env`.

```yaml
router:
  controllers:
    App\Controllers: app/Controllers
```

```php
$builder = ApplicationBuilder::create()->withConfiguration($config, basePath: $root);
```

Groups of `Router::middlewareGroup()` move under `router.middleware_groups`. When the router is needed before
`build()`, start it from `Router::fromConfiguration($config, basePath: $root)`. With a `router:` section, `build()`
refuses a configuration without `basePath`, and a `withRouter()` router that `Router::fromConfiguration()` did not build:

```text
Configuration declares router.controllers, but the builder received no basePath to resolve its paths against: build it with ApplicationBuilder::create()->withConfiguration($configuration, basePath: <application root>).
Configuration declares router.controllers, but withRouter() received a router that Router::fromConfiguration() did not build, so the routes cache would never be read. Remove the withRouter() call to let build() discover the controllers, or start the router passed to it from Router::fromConfiguration($configuration, basePath: <application root>).
```

Run `vendor/bin/zephyrus cache:warm` in the image build (see [Boot caches and cache:warm](#boot-caches-and-cachewarm-0160-3170)).

**Check.** `rg -n 'RouteCache' app public` finds nothing. In production, the error log holds no line such as
`Zephyrus: no routes cache at .../var/zephyrus/routes.php, so the controllers were discovered for this request. Run
vendor/bin/zephyrus cache:warm to write it.`

### Removed routing APIs (0.14.0, 3.17.0)

**Breaks.** `RouteDispatcher::dispatch()` is removed (0.14.0). `HandlerResolverException::invalidParameterValue()` is
removed (3.17.0): the framework never raised it.

**Change.** Call `match()`, then `dispatchMatch()`. Catch `RouteParameterException` for a parameter value that cannot
be resolved.

**Check.** `rg -n 'invalidParameterValue' app tests` finds nothing, and `rg -n 'RouteDispatcher' app tests` finds no
call to `dispatch(`.

## HTTP

### Response headers are stored lowercase (0.14.0)

**Breaks.** `new Response(headers: [...])` stores header names lowercased, so `$response->headers['Location']` is
`null`.

**Change.** Read headers with `getHeader('Location')`, `hasHeader()` or `hasNonBlankHeader()`.

**Check.** `rg -n -- '->headers\[' app tests` finds no mixed-case name.

### Control characters in headers and redirects (0.14.0)

**Breaks.** `withHeader()`, `withHeaders()` and `redirect()` throw `InvalidArgumentException` on a control character
other than a tab.

```text
Invalid value for HTTP header "X-A": it contains a control character other than HTAB.
Invalid redirect URL: it contains a control character; percent-encode it, or use localRedirect() for request input.
```

**Change.** Check an untrusted value with `Response::isValidHeaderValue()`. Redirect to a user-supplied target with
`Response::localRedirect()`, which sends `/` for a protocol-relative (`//host`) or backslash target.

**Check.** `rg -n 'redirect\(\$' app` lists the redirects to review; each user-supplied target goes through
`localRedirect()`.

### Request targets (0.14.0, 3.17.0)

**Breaks.**
- `new Request()` and `Request::fromArray()` read a target without a leading slash as rooted (`admin` is `/admin`) and
  a scheme other than http or https as a path.
- `Request::fromArray()` throws for a file entry that is not a `$_FILES`-shaped upload (no `tmp_name` or no `error`
  key): `File field "f" holds no readable upload.` It does not test that the temporary file exists.
- `Request::fromGlobals()` keeps only the path and query of an absolute-form target (`GET https://example.com/x
  HTTP/1.1`): the scheme, host and port come from `HTTPS`, `Host` and `SERVER_PORT`, or the trusted forwarding headers
  (3.17.0).

**Change.** Pass rooted paths or absolute http(s) URLs, and `$_FILES`-shaped entries in tests. A trusted proxy that
relays absolute targets sends `X-Forwarded-Proto` (or `Forwarded`).

**Check.** Behind the proxy, `isSecure()` is true on an HTTPS request (no redirect loop with `ForceHttpsMiddleware`).

### Request body size (0.14.0, 3.17.0)

**Breaks.** `MaxBodySizeMiddleware` (0.14.0) answers `413` above `max_body_size`. Since 3.17.0 it measures the
largest of a valid `Content-Length`, the raw body and the uploaded files, and counts the text fields of a body PHP
parsed itself (a multipart form): a form that declared a short length, or whose fields and files together exceed the
limit, is refused where it passed.

**Change.** Raise `max_body_size` (and `post_max_size`) where legitimate forms exceed it. Pass the same limit to
`Request::fromGlobals()`, so the body is never read past it:

```php
$builder->build()
    ->handle(Request::fromGlobals(
        trustedProxies: $config->security->trustedProxies,
        trustedHeaders: $config->security->trustedHeaders,
        maxBodySize: $config->security->maxBodySize,
    ))
    ->send();
```

**Check.** A body over the limit answers `HTTP/1.1 413 Content Too Large`; the largest real upload still passes.

## Security: headers, proxies, hosts and the debugger

### Allowed hosts (0.14.0, 0.14.1)

**Breaks.** An `allowed_hosts` entry is a host name, an IP literal or a wildcard over a host name, optionally with a
port. A scheme, a space, a comma inside one entry or a non-punycode international name throws at boot. An entry with
a port admits that port only; one without a port admits any.

```text
Allowed host "https://example.com": drop the scheme, list the host only, such as example.com.
Allowed host "a.example.com,b.example.com": use list items, not a comma inside one item.
Allowed host "bücher.example": use the punycode form of an internationalised name, such as xn--bcher-kva.example.
```

**Change.** Write `example.com` for `https://example.com`, one host per list item, and the punycode form of an
international name.

**Check.** The application boots, and `curl -s -o /dev/null -w '%{http_code}' -H 'Host: other.example.com'
http://127.0.0.1:8000/` does not answer `200`.

### Trusted proxies and forwarding headers (0.14.0, 0.14.1)

**Breaks.**
- A CIDR range with bits after its prefix, or an IPv4-mapped range shorter than `/96`, is invalid: refused at boot in
  `trusted_proxies`, by `IpAllowlistGuard`, and never matched when passed straight to `Request`.
- Behind a trusted proxy, only `X-Forwarded-For`, `X-Forwarded-Host`, `X-Forwarded-Proto` and `X-Forwarded-Port` are
  read by default. `Forwarded`, `X-Real-IP`, `CF-Connecting-IP` and `X-Client-IP` must be listed in
  `security.trusted_headers`.
- The last entry of `X-Forwarded-Host`, `X-Forwarded-Proto` and `X-Forwarded-Port` is read (the nearest proxy's), and
  of `Forwarded`, the element the outermost trusted proxy appended.

```text
Configuration section 'security' field 'trustedProxies[0]' has invalid value "10.0.0.1/8": 10.0.0.1/8 covers 10.0.0.0/8; write 10.0.0.1/32 or 10.0.0.0/8.
```

**Change.** Write the network address (`10.0.0.0/8`), list the header your proxy sets, and pass both lists to
`Request::fromGlobals()` (see [Request body size](#request-body-size-0140-3170)). The nearest proxy must write each
`X-Forwarded-*` header on every request.

```yaml
security:
  trusted_proxies: ['10.0.0.0/8']
  trusted_headers: [forwarded]
```

**Check.** Behind the proxy, the logged client address is the visitor's, not the proxy's.

### Response headers and CSP (0.14.0)

**Breaks.** A header set by a route or an inner response wins over `SecureHeadersMiddleware`, and a route's CSP
replaces the configured one whole. `ContentSecurityPolicy` and the header values throw on a control character.
`build()` refuses a global `ContentSecurityPolicyMiddleware` registered before a `SecureHeadersMiddleware` whose `csp`
is set.

```text
ContentSecurityPolicyMiddleware is registered before SecureHeadersMiddleware, whose csp is set, so the policy registered first is never sent. Register ContentSecurityPolicyMiddleware after SecureHeadersMiddleware, or leave SecureHeadersConfig::csp empty.
```

**Change.** Set each header in one place. Register `ContentSecurityPolicyMiddleware` after `SecureHeadersMiddleware`,
or leave `security.headers.csp` empty.

**Check.** `curl -sI http://127.0.0.1:8000/` shows one `content-security-policy` line, the one you expect.

### Cryptography keys (0.14.0)

**Breaks.** `Cryptography` refuses an all-zero encryption key, and a `hash()` key that is empty or outside 16 to 64
bytes.

```text
Invalid cryptographic key: The all-zero key is refused; it is what an unset or mis-decoded configuration value produces.
Invalid cryptographic key: Hash key must be between 16 and 64 bytes, got 0. Pass null for an unkeyed hash.
```

**Change.** Generate keys with `Cryptography::generateEncryptionKey()`, and pass `null` for an unkeyed hash.

**Check.** `rg -n 'Cryptography::hash\(' app` lists the calls to review.

### Who sees the debugger (0.14.0, 0.14.1, 0.15.0, 3.17.0)

**Breaks.**
- With `application.debug` off, or without a configuration, Tracy runs in production mode, and secrets are masked in
  debug views. An application scrubber assigned after boot replaces the framework's.
- In `production` and `staging`, `debug: true` is forced off with an error log line starting
  `Zephyrus: application.debug is true while application.environment is production-like.`
- With debug on, the debugger shows only to admitted clients. Without an allowlist: a direct loopback client under
  `php -S` only (no `X-Forwarded-For`, `Forwarded` or `X-Real-IP`). Under FPM, CGI or Apache, nobody.
- An allowlist entry is an exact address or `secret@address`. An empty secret or address, a range, and (3.17.0) a
  secret shorter than 16 characters throw `InvalidArgumentException`. A bare loopback entry no longer admits a request
  that carries a forwarding header.
- `ApplicationBuilder` turns `display_errors` off unless errors reach this boot's output. `KernelBuilder` used alone
  logs an unhandled exception and answers a generic 500 (0.14.1); `withUnhandledExceptionsRethrown()` lets it out.

```text
Debug client allowlist entry 1 has a secret shorter than 16 characters before "@": the tracy-debug cookie is all that guards the debugger at that address. Use a longer random secret.
Debug client allowlist entry 1 is a range: Tracy matches exact addresses only, so list each address.
```

**Change.** Name the clients that see the debugger, and send the secret as the `tracy-debug` cookie. Behind Docker the
browser arrives from the bridge gateway; behind a proxy on the same host, use `secret@127.0.0.1`. Generate a secret
with `bin2hex(random_bytes(16))`. Compose a scrubber of your own with `DebugIntegration::isSensitiveKey()`. Call
`withProductionDebugAcknowledged()` only if debug must stay on in production or staging.

```php
$builder = $builder->withDebugClientAllowlist(['127.0.0.1', env('DEBUG_SECRET') . '@172.17.0.1']);
```

**Check.** With debug on, an error page shows Tracy in your browser. When it does not, the error log says why:

```text
Zephyrus: unhandled exception, answered with a 500 (the debugger admits no client without an allowlist, except a direct loopback client under php -S; see withDebugClientAllowlist()): ...
```

## Views

No break is specific to views. Three entries reach the templates: Latte must be `^3.1` (see
[Latte 3.1](#latte-31-3170)), forms render the CSRF field themselves (see
[Automatic token injection is removed](#automatic-token-injection-is-removed-0140)), and a `format()` call or a
translation pipe needs a `Formatter` (see [Formatter required, names checked](#formatter-required-names-checked-0140)).

## Data

### Query parameters are bound by type (0.14.0)

**Breaks.** Booleans bind as `0`/`1`, `null` as SQL NULL, floats at full precision, `DateTimeInterface` as
`Y-m-d H:i:s.uP`, `BackedEnum` by value, `Stringable` as text, `Binary` and streams as binary data. Arrays, other
objects and strings holding a NUL byte throw `InvalidArgumentException`.

```text
Query parameter #1 cannot be bound: array has no SQL form. Bind a scalar, null, a DateTimeInterface, a BackedEnum, a Stringable, a Binary or a stream.
Query parameter #1 cannot be bound: text cannot hold a NUL byte. Validate request input before querying; bind real binary data as a Zephyrus\Data\Binary.
```

**Change.** Encode arrays yourself (`json_encode()`), and bind binary data as `new Binary($bytes)`.

**Check.** The test suite passes on the pages that write.

### Database errors and transactions (0.14.0)

**Breaks.** `DatabaseException` messages carry no SQL or driver text. `DatabaseException::fromPdoException()`,
`transactionFailed()`, `enableVerboseMessages()` and `verboseMessagesEnabled()` are removed. Nested `transaction()`
calls use savepoints; on PostgreSQL, committing a transaction in which a failure was caught throws (SQLSTATE `25P02`).

```text
Query failed [SQLSTATE HY000]: statement and driver detail withheld, read sql() and driverMessage() to inspect them
Transaction failed at commit [SQLSTATE 25P02]: a statement inside it failed and its exception was caught, so its writes were rolled back; let the exception propagate, or catch it around a nested transaction()
```

**Change.** Read `sqlState()`, `driverMessage()` and `sql()` instead of parsing the message. Build an exception with
`DatabaseException::queryExecutionFailed($sql, $pdoException)` or `queryFailed($sql, $reason)`. Let a failure inside a
transaction propagate, or catch it around a nested `transaction()`.

**Check.** `rg -n 'fromPdoException|transactionFailed|VerboseMessages' app` finds nothing.

### Insert ids need RETURNING (0.14.0)

**Breaks.** On PostgreSQL and SQLite, `insertGetId()` and `Broker::insertRowGetId()` require a `RETURNING` clause with
exactly one column, and `lastInsertId()` throws on PostgreSQL.

```text
insertGetId() needs INSERT ... RETURNING id on PostgreSQL and SQLite: add RETURNING id to the statement.
lastInsertId() is refused on PostgreSQL: use insertGetId() (insertRowGetId() in a Broker) with INSERT ... RETURNING id.
```

**Change.**

```php
$id = $database->insertGetId('INSERT INTO item (name) VALUES (?) RETURNING id', [$name]);
```

**Check.** `rg -n 'lastInsertId\(' app` finds nothing, and every `insertGetId(` statement ends with `RETURNING`.

### Decoded column values (0.14.0)

**Breaks.** `selectValue()` returns a boolean `false` column as `false`. The rows of `select()`, `selectOne()` and the
other row methods return a PostgreSQL array column as a PHP list of strings (`["a","b"]`, `["1","2"]`).
`selectValue()` still returns an array column as its text form (`{a,b}`).

**Change.** Drop the manual `'t'`/`'f'` and `{...}` parsing of row values; cast array elements when you need integers.

**Check.** `rg -n "=== 't'" app` finds nothing.

### Database configuration (0.14.0)

**Breaks.** The `emulate_prepares` key and the `DatabaseConfig` `emulatePrepares` argument are removed: the key is
refused even when `false`. `host` must hold no user name or password, `port` is 1 to 65535, `database` is a name and
not a URL, and `host`, `database` and `ssl_root_cert` refuse whitespace inside, `;`, `=`, quotes, backslashes and
control characters (values read from configuration are trimmed first). `column_cache_version`, `ssl_mode` and
`ssl_root_cert` refuse a float, boolean or array.

```text
Configuration section 'database' field 'emulate_prepares' has been REMOVED from Zephyrus and is no longer honoured.
Configuration section 'database' field 'database' has invalid value "postgres://***@db/app": must be a database name, not a connection URL.
Configuration section 'database' field 'column_cache_version' has invalid value 1.1: must be a string or an integer: quote a number such as '1.10' to keep its digits.
```

The first message goes on with the reasons, then `To fix: delete this line from the database section.`

**Change.** Delete `emulate_prepares`, put credentials in `username` and `password`, split a URL into the settings,
and quote a version (`'1.10'`).

**Check.** `rg -ni 'emulate_?prepares' .` finds nothing outside `vendor/`, and the application connects.

### Persistent connections (3.17.0)

**Breaks.** `new Database($pdo)` refuses a PDO opened with `PDO::ATTR_PERSISTENT`, and `Database::fromConfig()` refuses
a factory that returns a persistent connection it did not ask for.

```text
Database refuses a persistent PDO, whose session would carry over from an earlier request: remove PDO::ATTR_PERSISTENT from the PDO and set database.persistent: true in the configuration, so Database::fromConfig() resets the session each time it reuses the connection.
The PDO factory given to Database::fromConfig() returned a persistent connection it was not asked for, whose session would carry over from an earlier request: remove PDO::ATTR_PERSISTENT from the factory and set database.persistent: true in the configuration to reuse connections.
```

**Change.** Remove `PDO::ATTR_PERSISTENT`, set `persistent: true` in the `database` section, and open the connection
with `Database::fromConfig($config->database)`. On PHP 8.5, a factory used with `persistent: true` builds with
`PDO::connect()`.

Each `Database::fromConfig()` that reuses a pooled connection resets its session first (`ROLLBACK` when a transaction
is open, then `DISCARD ALL`), so `set_config(..., false)` values and advisory locks taken in one request do not reach
the next. Set session state after `fromConfig()`: a factory sets it before the reset, which erases it.

Add the setting to your complete `database:` section; alone, it is refused (`requires field 'database'`):

```yaml
database:
  persistent: true
```

**Check.** `rg -n 'ATTR_PERSISTENT' app` finds nothing.

### Entity hydration and JSON (0.15.0, 3.17.0)

**Breaks.**
- `Entity::build()` hydrates an ISO date string into a `DateTimeImmutable`, `DateTimeInterface` or `DateTime`
  property, and throws `InvalidArgumentException` for a value it cannot hydrate, where it skipped the property or
  failed with a `TypeError` or an `Error` (0.15.0).
- A union or intersection property is not hydrated: a `NULL` column into one that refuses null raises a `TypeError`,
  any other value throws (3.17.0).
- `Entity::jsonSerialize()` leaves static properties out (3.17.0).

```text
Invalid value for date DateTimeImmutable on property $createdAt
Cannot hydrate property $code of Item: type string|int is not supported
Cannot assign null to property Item::$code of type string|int
```

**Change.** Give each property a single type `Entity` hydrates (`?string` when the column can be `NULL`), or assign it
after `build()`. An API that relied on a static property in the JSON adds it as an instance property.

```php
final class Item extends Entity
{
    public int $id;
    public ?string $code = null;
}
```

**Check.** The pages and endpoints that list entities render, and their JSON holds the keys the clients read.

## Validation

### Stricter rules (0.14.0)

**Breaks.** `Rules::integer()` refuses booleans. `Rules::httpPath()` refuses protocol-relative paths (`//host`),
backslashes, spaces and control characters, and the other rules refuse control characters. Anchored patterns no
longer accept a trailing newline.

**Change.** Cast decoded JSON booleans before validating, and normalize or reject such input.

**Check.** `rg -n 'Rules::integer\(|Rules::httpPath\(' app` lists the fields to test with real input.

## Formatting and localization

### Formatter required, names checked (0.14.0)

**Breaks.** `format()` and the translation pipes accept only built-in names (any case) and registered formatters, and
need a `Formatter`: an unknown name throws `FormatterException` (a pipe, `LocalizationException`), where the raw value
was rendered.

```text
format('money') needs a Formatter: call App::setFormatter() first.
Unknown formatter: monney. Use one of: money, decimal, percent, ordinal, spellOut, date, time, datetime, timeago, duration, filesize, list, truncate, or register a custom formatter.
```

**Change.** Register a `Formatter` in command-line scripts and workers too, and fix or register each name:

```php
App::setFormatter(new Formatter('fr_CA', 'CAD'));
```

**Check.** A worker that renders a mail or a template runs without `FormatterException`.

### Formatter arguments (0.14.0)

**Breaks.** `Formatter` throws for `money()` without a currency on a locale with no country (`fr`), a currency that is
not three ASCII letters, a precision outside 0 to 20, an unsupported grouping separator, and a locale holding a NUL
byte.

```text
Currency required: locale "fr" has no native currency. Pass a currency to money(), or set localization.currency in the configuration (defaultCurrency when you build the Formatter yourself).
Invalid currency code: it must be three ASCII letters, for example CAD.
```

**Change.** Set `localization.currency`, or use a full locale such as `fr_CA`.

**Check.** A page that shows an amount renders it.

### The date pattern none (3.17.0)

**Breaks.** `Formatter` refuses the date pattern `none` (any case, with spaces) in `date()`, `time()`, `datetime()`
and as a constructor default, and an empty default. `localization.date_format`, `time_format` and `datetime_format`
refuse it at boot. An empty pattern given to `date()`, `time()` or `datetime()` now means the default. The presets
`short`, `medium`, `long` and `full` match in any case.

```text
Invalid date pattern "none": it formats nothing. Use short, medium, long, full or an ICU pattern.
Configuration section 'localization' field 'time_format' has invalid value "none": it formats nothing. Use short, medium, long, full or an ICU pattern.
```

**Change.** Choose `short`, `medium`, `long`, `full` or an ICU pattern such as `yyyy-MM-dd`.

**Check.** `rg -ni "_format: *none" config.yml` and `rg -ni "'none'" app` find no date pattern.

### Output that changed (0.14.0, 3.17.0)

**Breaks.**
- The `plural` pipe follows the plural rule of the locale: in French, 0 takes the singular (0.14.0).
- `timeago`, `duration`, `filesize` and `list` write French for a French locale (0.14.0).
- The `Translator` falls back through every shorter prefix of a locale (`zh-Hant-TW`, `zh-Hant`, `zh`), and a custom
  `LocaleLoaderInterface` receives the tag in BCP 47 case (`zh-Hant-TW`, `en-US-posix`), where it received everything
  after the first hyphen in upper case (3.17.0).

**Change.** Review catalog entries that render a count of zero, and a custom loader that compared the tag in upper
case.

**Check.** The pages that render counts and dates read correctly in each locale.

## Files and uploads

### Uploads move only genuine uploads (0.14.0)

**Breaks.** `Uploader` moves only a file PHP received over HTTP (`is_uploaded_file()`).

```text
Refusing to move "/tmp/in.png": it is not a genuine PHP upload. Inject a $fileMover when storing files that did not arrive over HTTP.
```

**Change.** In tests and command-line code, pass a `fileMover`:

```php
$uploader = new Uploader($root . '/var/uploads', fileMover: static fn (string $source, string $destination): bool => copy($source, $destination));
```

**Check.** An upload through the browser is stored; the tests that store files pass.

### Upload target names (0.14.1, 3.17.0)

**Breaks.** `Uploader` no longer maps SVG and XML by default. An explicit `$targetName` must end with an extension of
the detected type and carry no executable extension before it. A target name or sub-directory segment holding `:`, a
control, format or line separator character, a NUL byte, invalid UTF-8, a trailing dot or space, or a Windows device
name before the first dot (`con.pdf`, `nul.pdf`) is refused, legitimate names included.

```text
Upload target extension "jpg" does not match the detected MIME type "image/png". Expected extension: png.
Upload target name carries the executable extension "php" before its final extension.
Invalid upload target filename: "con.png".
Invalid upload sub-directory segment: "a\u0000b".
```

**Change.** Omit `$targetName` so the `Uploader` generates a name, or derive the extension from the detected type and
clean a user-chosen name. Map SVG or XML through `$mimeExtensionMap` only if you serve them safely.

**Check.** `rg -n -- '->store\(' app` lists the calls; each one passing a name from the user cleans it.

### File and Directory refusals (0.14.1, 3.17.0)

**Breaks.**
- `File::create()`, `write()`, `append()`, `copy()` and `move()`, `File::delete()`, `Directory::ensure()`, `create()`
  and `delete()` refuse a stream wrapper URL (`file://`, `phar://`, `data:`) and a NUL byte, and the writing methods a
  path that does not end with a file name.
- The writing methods refuse an existing path that is not a regular file, a write-only file, and a file another user
  owns in a sticky directory such as `/tmp`. `append()` refuses a symbolic link.
- A write that cannot complete throws `FileSystemException`, where an error handler turned PHP's notice into an
  `ErrorException`. `delete()` of a path `open_basedir` hides throws.
- Deleting a symbolic link removes only the link (0.14.1).

```text
Path is a stream wrapper URL, which is not written: "file:///tmp/x.txt"
Path holds a NUL byte, which no file name can: "/tmp/a\u0000b"
Path does not end with a file name: "/tmp/dir/"
```

**Change.** Pass plain filesystem paths, write to a wrapper with `fopen()` yourself, append to the real path of a
link, and catch `FileSystemException` instead of `ErrorException`.

**Check.** `rg -n 'file://|phar://' app` finds no path given to `File` or `Directory`.

## Mail

### MailerException (0.14.0)

**Breaks.** `MailerException` is built as `new MailerException($message, MailerFailure $failure, $previous)`.
`invalidAddress()` takes the method name, `sendFailed()` the transport message and no previous throwable, and partly
refused recipients raise `MailerFailure::RecipientsRefused`. Messages carry no address and no transport text.

**Change.** Branch on `$exception->failure`, and log `$exception->transportMessage()` where the server's reply is
needed:

```php
try {
    $mailer->send();
} catch (MailerException $exception) {
    if ($exception->failure === MailerFailure::RecipientsRefused) {
        // Retry the refused recipients later.
    }
    error_log((string) $exception->transportMessage());
}
```

**Check.** `rg -n 'new MailerException\(' app tests` finds only calls with a `MailerFailure`.

### Attachments (0.14.0)

**Breaks.** `attach()` and `attachContent()` refuse a display name (or file name) that is blank, `0`, `.` or `..`,
longer than 255 bytes, ends with a dot, has surrounding spaces, or holds `/`, `\`, `=?`, control, bidi or line separator
characters. A media type is limited to 127 bytes, and its parameters refuse `name`, `filename`, `boundary`, non-ASCII
names and `;` or `=` inside quoted values.

```text
Attachment rejected: display name "a/b.pdf" contains a path separator; pass a bare file name.
```

**Change.** Pass a plain name such as `report.pdf` and a plain type such as `application/pdf`.

**Check.** A mail with an attachment is sent from the staging environment.

## Container, exceptions and removed APIs

### Variadic constructor parameters (0.14.0)

**Breaks.** The container refuses a class-typed variadic constructor parameter, where it passed one instance.

```text
Cannot auto-wire parameter $plugins of [Host]: it is variadic and typed [Plugin]; the container cannot choose how many to pass: bind [Host] explicitly with a factory.
```

**Change.** Bind the class with a factory:

```php
$container->bind(Host::class, static fn (): Host => new Host(new SearchPlugin(), new AuditPlugin()));
```

**Check.** The application resolves the class without `ContainerException`.

### Exception handlers (0.14.0)

**Breaks.** An exception handler receives a non-null `Request`, and may return `null` to fall back to the built-in
response. Error responses run through the global middlewares.

**Change.** Type the parameter as `Request`, and drop a `null` check on it.

**Check.** An unknown path and a failing route render the application's error pages.

### Exception messages (0.14.0, 3.17.0)

**Breaks.** Refused values are quoted the same way in every configuration, security, mailer, routing and formatting
message, and control characters are escaped (`\n`, `\u0000`). `FileSystemException` messages quote paths
(`Path not found: "/var/app/x.txt"`) and end with PHP's reason when one is known.

**Change.** Update tests that compare exact messages; branch on the exception class or a typed accessor
(`ConfigurationException::section()`, `field()`, `reason()`, `DatabaseException::sqlState()`) rather than on the text.

**Check.** The test suite passes.

### Removed APIs (0.14.0, 3.17.0)

**Breaks.** Removed: `Zephyrus\Core\Kernel`, `DatabaseException::fromPdoException()`, `transactionFailed()`,
`enableVerboseMessages()` and `verboseMessagesEnabled()`, the `ZEPHYRUS_FORMAT_METHODS` constant, `IpRange::shownEntry()`,
`RouteDispatcher::dispatch()`, `HandlerResolverException::invalidParameterValue()`, the public `RouteCache` API and the
`RouteCacheException` factories but `noRoutes()`. Changed: `FormatterException::unknownFormatter()` takes the list of
built-in names as a second argument.

**Change.** Use `ApplicationBuilder`, `DatabaseException::queryExecutionFailed()` or `queryFailed()`,
`Formatter::BUILT_IN_FORMATTERS`, `match()` then `dispatchMatch()`, and the `router:` section, as the entries above
describe.

**Check.** The searches below find nothing.

## What to search for

Run each search from the application root (`app`, `public`, `tests`, `config.yml` and your deployment files). A hit
is a place to review, not always a defect.

| Search | Entry |
|---|---|
| `rg -n 'Core\\Kernel\b'` | [Removed Kernel class](#removed-kernel-class-0140) |
| `rg -n 'RouteCache'` | [Routes cache and the router section](#routes-cache-and-the-router-section-0160) |
| `rg -n 'discoverControllers\('` | [Routes cache and the router section](#routes-cache-and-the-router-section-0160) |
| `rg -n 'withRouter\('` | [Routes cache and the router section](#routes-cache-and-the-router-section-0160) |
| `rg -n 'RouteDispatcher'` | [Removed routing APIs](#removed-routing-apis-0140-3170) |
| `rg -n 'invalidParameterValue'` | [Removed routing APIs](#removed-routing-apis-0140-3170) |
| `rg -n 'catch \(RouteSignatureException'` | [Route names, methods and middleware names](#route-names-methods-and-middleware-names-0140-0150-3170) |
| `rg -n '\{\w+\?\}'` | [Route paths](#route-paths-0140) |
| `rg -n '\{\w+\}[\w.-]'` | [Route paths](#route-paths-0140) |
| `rg -n '^route' config.yml` | [The router section name is reserved](#the-router-section-name-is-reserved-0160) |
| `rg -n '!env +HTTP_'` | [Environment variables](#environment-variables-0140-3170) |
| `rg -n 'SetEnv'` | [Environment variables](#environment-variables-0140-3170) |
| `rg -n 'fastcgi_param'` | [Environment variables](#environment-variables-0140-3170) |
| `rg -n "section\('"` | [Configuration API](#configuration-api-0140) |
| `rg -n "config\('[A-Z]"` | [Configuration API](#configuration-api-0140) |
| `rg -n 'csrfAutoHtml'` | [Configuration API](#configuration-api-0140) |
| `rg -n 'parseFailed\('` | [Configuration files](#configuration-files-3170) |
| `rg -n 'Configuration::from\w*Files?\('` | [Configuration files](#configuration-files-3170) |
| `rg -n 'getPrevious\('` | [Configuration files](#configuration-files-3170) |
| `rg -ni 'frame_?options'` | [Security settings: mappings and formats](#security-settings-mappings-and-formats-0140) |
| `rg -ni 'body_?size'` | [Security settings: mappings and formats](#security-settings-mappings-and-formats-0140) |
| `rg -ni 'debug: *!env'` | [Boolean settings are strict](#boolean-settings-are-strict-0140) |
| `rg -n 'withMiddleware\('` | [The security wiring check](#the-security-wiring-check-0140) |
| `rg -n 'locale_path'` | [Relative paths follow the configuration file](#relative-paths-follow-the-configuration-file-0160-3170) |
| `rg -n 'SessionException::'` | [SessionManager throws SessionException](#sessionmanager-throws-sessionexception-0140) |
| `rg -n 'SessionHandlerInterface'` | [DatabaseSessionHandler: locking and concurrent writes](#databasesessionhandler-locking-and-concurrent-writes-0140-0150) |
| `rg -n 'idPattern'` | [DatabaseSessionHandler issues the session ids](#databasesessionhandler-issues-the-session-ids-3170) |
| `rg -ni 'auto_?html'` | [Automatic token injection is removed](#automatic-token-injection-is-removed-0140) |
| `rg -n 'injectToken'` | [Automatic token injection is removed](#automatic-token-injection-is-removed-0140) |
| `rg -ni 'excluded_?path_?patterns'` | [Exemptions must be anchored regular expressions](#exemptions-must-be-anchored-regular-expressions-0140) |
| `rg -n 'exceptions:' config.yml` | [Exemptions must be anchored regular expressions](#exemptions-must-be-anchored-regular-expressions-0140) |
| `rg -n -- '->headers\['` | [Response headers are stored lowercase](#response-headers-are-stored-lowercase-0140) |
| `rg -n 'redirect\(\$'` | [Control characters in headers and redirects](#control-characters-in-headers-and-redirects-0140) |
| `rg -n 'fromGlobals\('` | [Request body read limit](#request-body-read-limit-3171) and [Request body size](#request-body-size-0140-3170) |
| `rg -n 'MaxBodySizeMiddleware'` | [Request body size](#request-body-size-0140-3170) |
| `rg -ni 'allowed_?hosts'` | [Allowed hosts](#allowed-hosts-0140-0141) |
| `rg -ni 'trusted_?proxies'` | [Trusted proxies and forwarding headers](#trusted-proxies-and-forwarding-headers-0140-0141) |
| `rg -n 'X-Real-IP'` | [Trusted proxies and forwarding headers](#trusted-proxies-and-forwarding-headers-0140-0141) |
| `rg -n 'ContentSecurityPolicyMiddleware'` | [Response headers and CSP](#response-headers-and-csp-0140) |
| `rg -n 'Cryptography::hash\('` | [Cryptography keys](#cryptography-keys-0140) |
| `rg -n 'withDebugClientAllowlist'` | [Who sees the debugger](#who-sees-the-debugger-0140-0141-0150-3170) |
| `rg -n 'fromPdoException'` | [Database errors and transactions](#database-errors-and-transactions-0140) |
| `rg -n 'transactionFailed'` | [Database errors and transactions](#database-errors-and-transactions-0140) |
| `rg -n 'VerboseMessages'` | [Database errors and transactions](#database-errors-and-transactions-0140) |
| `rg -n 'lastInsertId\('` | [Insert ids need RETURNING](#insert-ids-need-returning-0140) |
| `rg -n 'insertGetId\('` | [Insert ids need RETURNING](#insert-ids-need-returning-0140) |
| `rg -n 'insertRowGetId\('` | [Insert ids need RETURNING](#insert-ids-need-returning-0140) |
| `rg -n "=== 't'"` | [Decoded column values](#decoded-column-values-0140) |
| `rg -ni 'emulate_?prepares'` | [Database configuration](#database-configuration-0140) |
| `rg -n 'ATTR_PERSISTENT'` | [Persistent connections](#persistent-connections-3170) |
| `rg -n 'extends Entity'` | [Entity hydration and JSON](#entity-hydration-and-json-0150-3170) |
| `rg -n 'Rules::integer\('` | [Stricter rules](#stricter-rules-0140) |
| `rg -n 'Rules::httpPath\('` | [Stricter rules](#stricter-rules-0140) |
| `rg -n 'ZEPHYRUS_FORMAT_METHODS'` | [Removed APIs](#removed-apis-0140-3170) |
| `rg -n 'unknownFormatter\('` | [Removed APIs](#removed-apis-0140-3170) |
| `rg -ni '_format: *none'` | [The date pattern none](#the-date-pattern-none-3170) |
| `rg -n 'LocaleLoaderInterface'` | [Output that changed](#output-that-changed-0140-3170) |
| `rg -n 'new Uploader\('` | [Uploads move only genuine uploads](#uploads-move-only-genuine-uploads-0140) |
| `rg -n -- '->store\('` | [Upload target names](#upload-target-names-0141-3170) |
| `rg -n 'file://'` | [File and Directory refusals](#file-and-directory-refusals-0141-3170) |
| `rg -n 'catch \(\\?ErrorException'` | [File and Directory refusals](#file-and-directory-refusals-0141-3170) |
| `rg -n 'new MailerException\('` | [MailerException](#mailerexception-0140) |
| `rg -n 'attachContent\('` | [Attachments](#attachments-0140) |
| `rg -n 'shownEntry'` | [Removed APIs](#removed-apis-0140-3170) |
| `rg -n '^\s*\$\w+->with[A-Z]\w*\('` | [Results of with*() on PHP 8.5 and later](#results-of-with-on-php-85-and-later-3170) |
| `rg -n 'expectExceptionMessage'` | [Exception messages](#exception-messages-0140-3170) |
