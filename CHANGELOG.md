# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html) from 3.17.0: minor and patch releases stay compatible, and
a breaking change waits for the next major, deprecated first. Before 3.17.0, a minor version could break the API: those
changes are listed first, each with the change to make in your code.

## [Unreleased]

### Added

- `File::append()` takes an optional `$lockWaitSeconds` (default `10.0`): the longest total wait for its file lock and, when it creates the file, its directory lock. `0.0` makes a single attempt per lock; a negative, `NAN` or infinite value throws `InvalidArgumentException` before any file system access.

### Changed

- `File::append()` no longer waits without limit for its locks: a lock still held after the wait (10 seconds by default) makes it throw `FileSystemException`, so a process holding the file or its directory can make the call fail but no longer hold it back.

### Fixed

- `Request::fromGlobals()` and `HeaderBag::isJson()` take the media type of a `Content-Type` as the part before its first `;`: a value that starts with its parameters (`;application/json`) has an empty media type, and its body is no longer parsed as JSON.
- `CachedLocaleLoader::flush()` deletes only the catalogs of its own prefix: a loader with prefix `app` no longer deletes the catalogs cached under `app2` or `app:x`.
- `CachedLocaleLoader::load()` caches only non-empty catalogs of canonical locale tags: an unknown or non-canonical locale (`fr `, `FR`, `fr_CA`, `x:en`) is read from the inner loader each time instead of leaving a permanent APCu entry, and can no longer read another loader's catalog.

## [3.17.2] - 2026-11-20

### Fixed

- An incomplete body no longer reaches the controller with its fields cut short: `Request::fromGlobals()` reads a body shorter than its `Content-Length` (unless it carries a `Content-Encoding`), or a multipart body holding an upload PHP ended without its boundary (`UPLOAD_ERR_PARTIAL`), as empty and malformed, and `HttpKernel` refuses it with `400` before any middleware or controller, unless a `RequestEvent` listener answers it first. A chunked multipart whose file PHP cut at `post_max_size` is refused with this `400`, not `413`.
- `Request::fromGlobals()` reads a form POST that PHP parses itself (urlencoded or multipart) with `post_max_size` as its read limit when that is smaller than `maxBodySize`, so a form past it is refused with `413` before any controller instead of reaching it empty or cut. A JSON or other raw body, a `PUT`, and a POST that PHP leaves whole in `php://input` keep `maxBodySize`.
- Known limits of the incomplete body check: a chunked body cut short, a body with a `Content-Encoding` cut short, and a multipart POST cut in or between its text fields still reach the controller as complete or partial (CSRF does not catch the multipart cut when the token travels in a header), so validate required fields.
- `HttpKernel` reports a form POST cut at a `post_max_size` below `security.max_body_size` with its fix (raise `post_max_size`), through `ExceptionEvent` listeners, the debugger, or one error log line.

### Documentation

Pages of zephyrus.ophelios.com that this version makes stale, each in English and in French (`content/fr/`):

- `fundamentals/security.md`: Request body size, a form POST past `post_max_size` is refused with `413` and reported, and an incomplete body is refused with `400`, with its known limits.
- `fundamentals/http.md`: `isMalformed()` is also true for an incomplete body, which the kernel refuses with `400`.
- `fundamentals/application.md`: the request lifecycle refuses a truncated (`413`) or incomplete (`400`) body after `RequestEvent` and before routing, and these refusals skip the global middlewares.
- `digging-deeper/container-and-events.md`: a `RequestEvent` listener sees a truncated or incomplete body first and can answer it with its own page.
- `digging-deeper/uploads.md`: a POST whose files push it past `post_max_size` is refused with `413` before the controller, and a partial upload is refused with `400`.

## [3.17.1] - 2026-11-20

### Breaking changes

This patch release makes an exception to the compatibility promise above for a security fix: an application that
declares `security.max_body_size` above 2 MB without passing it to `Request::fromGlobals()`, or that takes bodies over
2 MB without declaring a limit, must act.

- An application whose `security.max_body_size` is above 2 MB and that does not pass it to `Request::fromGlobals()` now refuses bodies between 2 MB and its limit, forms and uploads included, where it accepted them. Pass `maxBodySize: $config->security->maxBodySize` to `fromGlobals()`, also when the body is injected with `rawBody:` (see [UPGRADE.md](UPGRADE.md#from-3170-to-3171)). Each such request is refused and reported with a `ConfigurationException` that names the fix (`Request::fromGlobals() read the body with a limit of 2097152 bytes, but security.maxBodySize allows 8388608: pass maxBodySize: $config->security->maxBodySize to Request::fromGlobals().`): to `ExceptionEvent` listeners (source `ExceptionEvent::SOURCE_REQUEST`), then thrown to the debugger when unhandled exceptions are rethrown, or written to the error log as `Zephyrus: ` and that message on one line. Requests within 2 MB are unaffected. An application that declares no `max_body_size` but takes bodies over 2 MB (an upload limit set only by `post_max_size`) refuses them the same way, with no report: pass the limit it accepts (`maxBodySize: 0` reads any body whole into memory).

### Security

- `Request::fromGlobals()` reads at most 2 MB of the request body (2097152 bytes, `Request::MAX_BODY_SIZE_DEFAULT`, also the default of `security.max_body_size`) when it is not given `maxBodySize`, where it read the whole body into memory before any middleware could refuse it: a 300 MB `PUT` took 300 MB of PHP memory. A declared `Content-Length` over the limit is never read, a body without one is read one byte past it, and a multipart body PHP parsed itself without one (chunked) is measured on its fields and files as `MaxBodySizeMiddleware` measures it. Such a body is truncated (`RequestBody::isTruncated()`, and `isMalformed()` is true) and never reaches a controller: a global `MaxBodySizeMiddleware` refuses it whatever its own limit, through its `onFailure` (whose `BodyTooLarge` carries the limit that cut the body), and without one `HttpKernel` answers the middleware's default `413` (plain text when `Accept` lists `text/html`, JSON otherwise) before the global middlewares run. `maxBodySize: 0` still reads the whole body. Register `MaxBodySizeMiddleware` before `CsrfMiddleware`: a truncated form reads as empty, so a CSRF check that runs first answers `403`, not `413`.

### Added

- `Request::MAX_BODY_SIZE_DEFAULT` (2097152), the read limit of `fromGlobals()` and the default of `security.maxBodySize`.
- `RequestBody::isTruncated()` and `RequestBody::readLimit()` (the limit `fromGlobals()` read the body with, `0` for none, `null` for a body it did not build), with the matching `RequestBody` constructor arguments.
- `ExceptionEvent::SOURCE_REQUEST`, the source of the report of a body read below the configured limit.
- `MiddlewarePipeline::hasMiddleware()`, whether a middleware of the chain is an instance of a class.
- `KernelBuilder::withConfiguredMaxBodySize()` and the `HttpKernel` constructor argument `configuredMaxBodySize`, which `ApplicationBuilder::build()` sets from a declared `security.maxBodySize`.

### Documentation

Pages of zephyrus.ophelios.com that this version makes stale, each in English and in French (`content/fr/`):

- `fundamentals/security.md`: `fromGlobals()` reads 2 MB by default, not the whole body, a chunked multipart body is measured on its fields and files, a truncated body is refused by the kernel when no `MaxBodySizeMiddleware` is mounted, and `MaxBodySizeMiddleware` goes before `CsrfMiddleware` (Request body size, the defaults table and the checklist).
- `fundamentals/application.md` and `fundamentals/http.md`: `maxBodySize` is required above 2 MB; `RequestBody::isTruncated()` and `readLimit()`.
- `digging-deeper/uploads.md`: an upload over 2 MB needs `maxBodySize` passed to `fromGlobals()`.

## [3.17.0] - 2026-11-19

### Breaking changes

#### Requirements

- `latte/latte` must be `^3.1`, whose loader interface the template namespaces implement: Zephyrus no longer installs beside Latte 3.0. Run `composer update latte/latte`, and check a loader or extension of your own against the Latte 3.1 release notes.

#### Core

- A debug client allowlist entry `secret@address` is refused when the secret is shorter than 16 characters: `withDebugClientAllowlist()` and the debug configuration throw `InvalidArgumentException` (`Debug client allowlist entry 1 has a secret shorter than 16 characters before "@": the tracy-debug cookie is all that guards the debugger at that address. Use a longer random secret.`), where any non-empty secret was accepted. To migrate: generate a random secret of 16 characters or more (`bin2hex(random_bytes(16))`), use it in the entry and as the `tracy-debug` cookie.
- `Configuration::fromFile()`, `fromFiles()` and `fromOptionalFiles()` refuse a file whose extension is not `.yml`, `.yaml` or `.php` (`Configuration file <name>: must be YAML (.yml, .yaml) or PHP (.php)`), where they ran it as PHP: a `.json`, `.env` or extensionless file is no longer executed. A configuration file, YAML or PHP, is read only from a local path: a stream wrapper URL such as `phar://` or `file://` is refused by `Configuration`, `ConfigurationFile` and `ApplicationBootstrap::fromConfigFiles()`, found or not and even as an optional file (`Configuration file must be a local path, not a stream wrapper URL (phar://)`, `Configuration file must be a local path, not a stream wrapper URL (data:)`), where a YAML file given that way was reported not found or, as an optional file, ignored. Rename a PHP configuration file to `.php`, and pass its plain path.
- A configuration file whose content is not a mapping (a YAML scalar such as `hello world`, a YAML list or top-level tag, a PHP file that returns a list) is refused with `ConfigurationException` (`Configuration file <name>: must be a mapping of sections`), where it was ignored and the application started on the defaults; `cache:warm` refuses such a YAML file the same way. An empty or comment-only YAML file, and a PHP file that returns `[]`, still read as empty. Write the configuration as `section:` keys.
- `HandlerResolverException::invalidParameterValue()` is removed: the framework never raised it; an unresolvable parameter value raises `RouteParameterException`.

#### Routing

- A route whose method is not an RFC 9110 token (letters, digits and ``!#$%&'*+-.^_`|~``) is refused at registration by `Route::define()`, `new Route()`, `Router::add()` and the route attributes, with `RouteSignatureException` (``Invalid route method "GE T" on route "/x": an HTTP method is a token of letters, digits and !#$%&'*+-.^_`|~ (RFC 9110), such as GET or PROPFIND``). Such a route answered no request, and its method reached the `Allow` header of a 405 and the message of `MethodNotAllowedException` unchanged. Correct the method of such a route or remove it.

#### Data

- `Database` refuses a persistent PDO, and `Database::fromConfig()` refuses a factory that returns a persistent connection it did not ask for, because the session of a persistent connection (role, advisory locks, prepared statements, a transaction left open) used to reach the next request unchanged. `new Database($pdo)` with `PDO::ATTR_PERSISTENT` throws `DatabaseException` (`Database refuses a persistent PDO, whose session would carry over from an earlier request: remove PDO::ATTR_PERSISTENT from the PDO and set database.persistent: true in the configuration, so Database::fromConfig() resets the session each time it reuses the connection.`), and a factory that returns one while `database.persistent` is `false`, or while a persistent handle for the same DSN and user is still held, throws `DatabaseException` (`The PDO factory given to Database::fromConfig() returned a persistent connection it was not asked for, whose session would carry over from an earlier request: remove PDO::ATTR_PERSISTENT from the factory and set database.persistent: true in the configuration to reuse connections.`). To migrate: remove `PDO::ATTR_PERSISTENT` from the factory or the PDO, set `persistent: true` in the `database` section, and open the connection with `Database::fromConfig()`.

- `Entity::jsonSerialize()` leaves static properties out of the JSON, where it wrote each public static property as a key of every object (`public static int $counter` became `"counter": 0`), so class state could reach an API response. `#[JsonIgnore]` is no longer needed on a static property. An API that relied on such a key must add it itself, for example as a public instance property filled from the static one.
- `Entity::build()` raises a `TypeError` for a `NULL` column into a union or intersection property that does not accept null (`Cannot assign null to property ...::$union of type string|int`), where it used to skip the column and leave the default or an uninitialized property. A named type that does not accept null already threw this error. `build()` hydrates no other value into a union or intersection property (`Cannot hydrate property $name of <Entity>: type string|int is not supported`), so map such a column to a property with a single type (`?string` when the column can be `NULL`), or leave the column out of the query.

#### Formatting

- `Formatter` refuses the date pattern `none`, in any case and with surrounding spaces (`NONE`, ` none`), with `FormatterException` (`Invalid date pattern "none": it formats nothing. Use short, medium, long, full or an ICU pattern.`, quoting the pattern as received), in `date()`, `time()` and `datetime()`, and as the `defaultDatePattern`, `defaultTimePattern` or `defaultDatetimePattern` given to the constructor, where it was accepted as an undocumented preset: with both ICU styles at `NONE`, ICU fell back to its default pattern and printed a date and a time, and `NONE` or ` none` printed an empty or blank string. The preset names `short`, `medium`, `long` and `full` are now matched without case and without surrounding spaces (`Short`, ` LONG `), where any other spelling was handed to ICU as a pattern; an ICU pattern that is not a preset is still passed untouched. An empty or blank pattern given to `date()`, `time()` or `datetime()` now means the constructor default, like `null`, where `date($d, '')` printed the full date and time; given to the constructor as a default it throws `FormatterException` (`Invalid date pattern "": it is empty. Use short, medium, long, full or an ICU pattern.`). In the configuration, `localization.date_format`, `localization.time_format` and `localization.datetime_format` refuse these values at startup with `ConfigurationException` (`Configuration section 'localization' field 'time_format' has invalid value "none": it formats nothing. Use short, medium, long, full or an ICU pattern.`), where `time_format: none` raised a `FormatterException` that did not name the key; an empty value still falls back to the default. To migrate, remove `none` and choose one of the presets `short`, `medium`, `long` or `full`, or an ICU pattern such as `yyyy-MM-dd`.

#### FileSystem

- `FileSystemException` messages show a path as a quoted, escaped string (`Path not found: "/var/app/x.txt"`, `Failed to copy "<source>" to "<destination>"`), where they copied it raw: a line break or another control character in a path no longer forges a log line. A path over 512 bytes keeps its last 512 bytes, followed by its length; the reason PHP gave is quoted too, and one over 256 bytes keeps its first 128 and its last 128 bytes, so the function and the error PHP names both stay. Update any code or test that matches a message's exact text.
- `Directory::ensure()` and `create()` refuse a path holding a NUL byte or a stream wrapper URL with `FileSystemException` (`Path holds a NUL byte, which no file name can: "<path>"`, `Path is a stream wrapper URL, which is not written: "<path>"`), as `File` does, before calling `mkdir()`; a NUL byte threw PHP's `ValueError`. Pass the plain filesystem path.
- `File::create()`, `write()`, `append()` and `copy()` (its destination) refuse a path that is a stream wrapper URL (`scheme://` with a scheme of two characters or more, `file://`, `phar://` and `data:` included) with `FileSystemException` (`Path is a stream wrapper URL, which is not written: "<path>"`), before creating any directory or file. Pass the plain filesystem path (without `file://`), and write to a wrapper with `fopen()` or `file_put_contents()` yourself.
- `File::move()` refuses a stream wrapper URL or a path that does not end with a file name as its destination, as `copy()` does, before creating any directory.
- `Directory::delete()`, `File::delete()` and `File::move()` (its source) refuse a path holding a NUL byte or a stream wrapper URL with `FileSystemException`, as the methods that write do, where `delete()` removed what a `file://` URL named.
- `File::create()`, `write()`, `append()`, `copy()` and `move()` refuse a path holding a NUL byte with `FileSystemException` (`Path holds a NUL byte, which no file name can: "<path>"`), where PHP raised a `ValueError`.
- `File::create()`, `write()`, `append()` and `copy()` refuse an existing path that is not a regular file, such as a FIFO or a device (`Path is not a regular file: "<path>"`), and an existing file the process may write but not read, mode `0200` (`Path is not readable, which a safe write requires: "<path>"`), because an existing file is now opened for reading and writing and checked before anything is written. Write a regular file, and grant read permission to a write-only one.
- `File::append()` refuses a symbolic link at the path with `FileSystemException` (`Path is a symbolic link, which is not followed: "<path>"`), where it appended to the link target or created it. Append to the real path of the file (`realpath()` of the link), or remove the link.
- `File::create()`, `write()`, `append()` and `copy()` refuse an existing file in a sticky directory such as `/tmp` that belongs to neither the directory owner nor the effective user (`Path belongs to another user in a shared directory, so it is not written: "<path>"`), on every system and whatever `fs.protected_regular` is set to. Write such a file as its owner, or outside a shared directory.
- `File::delete()` and `Directory::delete()` of a path that `open_basedir` hides throw `FileSystemException` (`Failed to delete: "<path>" ("is_link(): open_basedir restriction in effect. File(<path>) is not within the allowed path(s): (<paths>)")`), where they returned as if the path were absent. A path that is simply absent is still a silent no-op.

#### Session

- `DatabaseSessionHandler` generates new session ids itself (`create_sid()`): 32 lowercase hexadecimal characters, whatever `session.sid_length` and `session.sid_bits_per_character` (deprecated since PHP 8.4) say, where PHP generated them from those settings. A custom `idPattern` written for the ids those settings produced may refuse the new ones (a pattern written for PHP's defaults, `/\A[0-9a-f]{32}\z/`, accepts them), so the constructor tries it on sample ids of that shape and throws `SessionException` (`DatabaseSessionHandler issues session ids of 32 lowercase hexadecimal characters, and the idPattern "<pattern>" refuses them, so no new session could be stored. Pass a pattern that also accepts them.`) rather than letting every new session fail to store with only PHP's `Failed to write session data` warning. The default pattern accepts them, and a stored session keeps resuming while the pattern accepts its id. To migrate: make a custom `idPattern` accept 32 lowercase hexadecimal characters too, such as `/\A(?:[a-v0-9]{48}|[0-9a-f]{32})\z/`. An `idPattern` that is not a valid regular expression is refused at construction too (`The idPattern "<pattern>" is not a valid regular expression: <PHP's reason>.`, such as `No ending delimiter '/' found`), where every callback raised PHP's `preg_match()` warning and refused every id: correct the pattern.

#### Upload

- `Uploader::store()` refuses a target name and a sub-directory segment that it used to accept: a control or format character, U+2028 or U+2029, a null byte (it was stripped), invalid UTF-8, a colon (sub-directory) and a sub-directory segment that ends with a dot (`a.`, `...`), and a Windows device name before the first dot, spaces around it ignored (`CON`, `PRN`, `AUX`, `NUL`, `COM1` to `COM9`, `LPT1` to `LPT9`, `CONIN$`, `CONOUT$`, any case). Legitimate names are refused too: a Persian name with a zero width non-joiner, an emoji joined with a zero width joiner, `nul.pdf`, `con.pdf`, `aux.pdf`. The refusal of a sub-directory segment is `UploadException::invalidSubDirectorySegment()` (`Invalid upload sub-directory segment: "a\u0000b".`). An application that passes a name chosen by the user: omit `$targetName` so the `Uploader` generates one, or clean the name (replace the refused characters, prefix a device name) before the call.

### Security

- A YAML syntax error in `config.yml` no longer quotes the file. `v0.16.0` appended the parser's message to `ConfigurationException` and chained the parser's exception, and both can quote a value of the file (a malformed `password` line shows it in the message and in the excerpt), which then reached traces, error pages, logs and `cache:warm`. The message is now `Failed to parse configuration file [config.yml]: invalid YAML near line 3 (the parser's detail is withheld, since it can quote a secret from the file).` (without `near line N` when the parser gives no line), `getPrevious()` returns null, and the parser's exception is no longer an argument of any call made after the failure, so a trace that records arguments (Tracy does) holds neither it nor the file's text: code that read the line from the cause finds it in the message. `ConfigurationException::parseFailed()` takes the line (`?int`) instead of the exception.
- A refused `!env` name is no longer quoted. `!env tag at "a": ...` names the tag's key and the reason (`the variable name is refused: names must not contain a NUL byte or "=".`), and the `InvalidArgumentException` of `EnvironmentVariable::read()` is no longer chained, since a value pasted where a name belongs (a base64 secret ends with `=`) would otherwise reach the message and `previous`. The loading methods that hold the file's text or an `!env` value (the whole parsed configuration, the tag, its default) are marked `#[\SensitiveParameter]`, so a trace that records arguments (Tracy does) shows `SensitiveParameterValue` for them; this also hides a neighbouring secret that Tracy's key masking does not recognise.
- A value a section getter refuses (`ConfigSection::getString()`, `getInt()`, ...) is hidden from the trace's argument list.
- `Uploader::store()` refuses, with `UploadException`, an explicit target name that holds a control, format or line separator character (U+0085, U+2028 and the right-to-left override included), that is not valid UTF-8, or whose part before the first dot is a reserved Windows device name (`CON`, `PRN`, `AUX`, `NUL`, `COM1` to `COM9`, `LPT1` to `LPT9`, `CONIN$`, `CONOUT$`, superscript digits included, any case). A null byte in the target name or in the sub-directory is refused, where it used to be stripped silently, and a sub-directory segment gets the same checks.
- `Request::fromGlobals()` keeps only the path and query of an absolute-form request target (`GET https://example.com/x HTTP/1.1`), from any peer, a trusted proxy included. The scheme, host and port come from `HTTPS`, `Host` (or `SERVER_NAME`) and `SERVER_PORT`, or from the trusted `Forwarded` and `X-Forwarded-*` headers, as for `/x`. Before, any client could send one over plain HTTP and be read as `https` (`isSecure()` true, no `ForceHttpsMiddleware` redirect) with the host of the target instead of `Host`. A trusted proxy that relayed an absolute target and sent no `X-Forwarded-Proto` now reads as `http`: have it send `X-Forwarded-Proto` (or `Forwarded`).
- `File::create()`, `write()` and `copy()` never open a new file by its final path: they write it as a temporary file in the same directory, then `rename()` it over the path, which replaces a symbolic link there (one swapped in during the call included) instead of following it.
- `File::create()`, `write()` and `copy()` write an existing regular file in place through a handle, and only if the path still names it once opened (same device and inode): nothing is written or truncated before that check, and the file keeps its inode, hard links, owner and mode. `copy()` streams its source into that file instead of calling PHP's `copy()`. Nothing to change in your code.
- `File::copy()` refuses a source that is not a regular file, a directory included (`Path is not a regular file: "<path>"`), before creating any directory, and a source it cannot read (`Path is not readable: "<path>"`). A copy onto the source itself, or onto a hard link to it, is refused without emptying the file (`Cannot copy a file onto itself: "<source>" to "<destination>"`).
- A file these methods create gets its mode on the temporary file before it is put in place, and only when the mode differs. PHP sets a mode only by path: a process that can write to the directory and swaps the temporary path for a link between the check and the `chmod` can still redirect that `chmod`. Nothing to change in your code.
- `File::append()` never follows a symbolic link at the path. A new file is written as a temporary file, then hard linked to the path, which fails on a link planted there; it is renamed into place instead on a thread-safe PHP build (whose `link()` follows a link at its new path) and on a filesystem without hard links, under an exclusive `flock()` of its directory, so two calls that create the file at the same instant take turns and the second appends to the file of the first (where the directory cannot be opened or locked, the rename goes ahead without that lock, and one of the calls may lose its content or fail with `FileSystemException`). Nothing to change in your code.
- `File::append()` to an existing file goes through the same checks as `write()`, under an exclusive `flock()`, so concurrent `append()` calls never overwrite each other; they can overwrite and lose lines of a writer that does not take that lock (`file_put_contents()` with `FILE_APPEND`, `error_log()`, a default Monolog `StreamHandler`). Both locks are awaited without limit, so a process that can open the file, or its directory while the file is missing, can hold `append()` back. Nothing to change in your code.
- An existing file in a sticky directory such as `/tmp` that another user owns is no longer written in place: opening it that way escaped the Linux `fs.protected_regular` rule, which only guards an open that may create the file.
- A PHP configuration file is loaded from its resolved path, so a directory on the `include_path` no longer replaces a relative path checked from the current directory.
- `Response::withCookie()` hides the cookie value from the argument list of a stack trace (`#[\SensitiveParameter]`): a value is often a remember-me or session token.
- The value under validation is hidden from the argument list of a stack trace (`#[\SensitiveParameter]`): `Rule::test()`, `FormValidator::validate()` and `validateOrFail()`, `Controller::validate()`, and every closure and helper of `Rules`, where an exception raised during a validation kept a password or token in `getTrace()` and `getTraceAsString()` (with `zend.exception_ignore_args` off). A closure given to `Rule::of()` is yours to mark.

### Added

- `LatteEngine::addNamespace(string $namespace, string $directory)` (a `LatteEngine` method, not part of `RenderEngine`) makes the templates of a Composer package reachable as `@namespace/page`: `render('@acme/mail/welcome')`, `exists()`, and `{include '@acme/x.latte'}`, `{layout}`, `{embed}` and `{import}` from an application view or from a package template. A file under `{views}/vendor/{namespace}/` overrides the package template of the same name, with no configuration. A relative name inside a template resolves against that file's own directory, so an override does not see its package siblings (`Missing template file`): in a package template, include siblings as `@namespace/name.latte`. Declare the views sub-directory of a package, not its root: the namespace opens every `.latte` file under it to a page name taken from user input. Only a name of the form `@namespace/...` is namespaced: `{layout '@layout.latte'}` and `render('@layout')` stay plain files. A name is lowercase letters, digits, `_` and `-` (`Template namespace "Acme" is invalid: use lowercase letters, digits, "_" and "-", starting with a letter or a digit.`), the directory must be absolute and exist (`Template namespace "acme" needs an existing absolute directory, got "<path>".`), and a namespace declared twice is refused (`Template namespace "acme" is already declared.`), all with `InvalidArgumentException`. An undeclared namespace throws `RenderException` (`Template namespace "nope" is not declared (call LatteEngine::addNamespace() first). Declared namespaces: "acme".`). Lookups keep the `..`, NUL byte and symbolic link containment of plain pages, and a page without namespace resolves exactly as before.
- `App::isDebug()` returns the effective debug flag that `build()` resolved (false in a production-like environment unless `withProductionDebugAcknowledged()` was called), with `App::setDebug()` beside `App::setConfiguration()`. `App::reset()` clears it. An application that calls `App::setConfiguration()` without `build()` (a tool, a CLI script, a test) gets no session or CSP debug warnings until it calls `App::setDebug(true)`.
- `database.persistent` (default `false`) reuses the PostgreSQL connection across requests of one process. When a connection is taken from the pool, `fromConfig()` sends `ROLLBACK` if a transaction is open, then `DISCARD ALL`, then drops the notifications the client had already received. A connection whose reset fails is terminated and replaced once; a second failure throws `DatabaseException`.
- The pool is per process (`zephyrus:<pid>`), and a persistent handle still held in the process is never handed out again: for the same DSN and user, a second `fromConfig()` opens a non-persistent connection instead.
- `DebugIntegration::installSecretMasking(?string $sessionName = null)` installs the masking of secrets in `dump()`, the debug bar and the Bluescreen without enabling Tracy or installing its handlers, as a test or a controlled `dump()` needs; `initialize()` calls it.
- Zephyrus is tested on PHP 8.6: the CI matrix runs PHP 8.4, 8.5 and 8.6.
- The public `with*()` methods that return a modified copy (`Request`, `Response`, `Route`, `Router`, `RouteCollection`, `FormValidator`, `MiddlewarePipeline::without()`, `PaginationRequest`, `KernelBuilder`, `ApplicationBuilder` and `ContentSecurityPolicy`) carry `#[\NoDiscard]`: on PHP 8.5 and later, calling one as a statement and dropping the result raises a warning (`$response->withHeader(...);` changes nothing). Keep the result: `$response = $response->withHeader(...)`. To drop it on purpose, such as in a test that expects the call to throw, assign it (`$ignored = ...`): `(void)` is PHP 8.5 syntax. PHP 8.4 ignores the attribute.
- `Request::fromGlobals()` takes a last parameter `int $maxBodySize = 0` (0 reads the whole body, as before; a negative value throws `InvalidArgumentException` with `Maximum body size must be 0 (unlimited) or a positive byte count.`). Above 0, `php://input` is never read past that many bytes: a valid `Content-Length` over the limit leaves the raw body empty, and a body that runs over it is cut to the limit plus one byte, with an empty parsed body and `isMalformed()` true, so `MaxBodySizeMiddleware` given the same limit refuses it with a 413, where the whole body was in memory first. Pass `maxBodySize: $config->security->maxBodySize` to `fromGlobals()`.
- `DatabaseActor` publishes the actor that PostgreSQL row level security policies read, for one transaction only (`set_config(..., true)`), so it never reaches the next request, nor another client behind a pooler in transaction mode. `new DatabaseActor($db, 'app', ['actor_id'])` declares the setting `app.actor_id`; `actAs(['actor_id' => (string) $userId], $work)` opens a transaction, publishes, runs `$work` and commits; `publish()` publishes in a transaction already open, until the outermost one ends. A policy reads `nullif(current_setting('app.actor_id', true), '')`. It throws `DatabaseActorException`, whose messages never hold a value, for a connection other than PostgreSQL, a prefix or key that is not 1 to 63 lowercase letters, digits and underscores, no key or a key declared twice, no value, a value that is not a string (`The actor value for "actor_id" must be a string, int given: cast it first, such as (string) $userId.`), an empty value or a key that was not declared, `actAs()` inside an open transaction, `publish()` outside one, and a second actor on the connection, a value set for the whole session included (`An actor is already published on this connection ("app.actor_id" is set): an actor changes only by ending its transaction, and a value set for the whole session is refused.`). Publishing protects nothing by itself: a superuser, a `BYPASSRLS` role and a table owner without `FORCE ROW LEVEL SECURITY` see every row. The refusal of a second actor covers the keys that one instance declares: two instances with different keys on the same `Database` stack, so declare every key your policies read in one instance.

### Changed

- The debug warning about a policy that is replaced now comes from `SecureHeadersMiddleware`, when its `csp` is about to overwrite the policy of an outer `ContentSecurityPolicyMiddleware`, static or with nonces (a per-route `SecureHeadersMiddleware` registered inside it). `ContentSecurityPolicyMiddleware` no longer warns when the response already carries the header, so a route that sets its own `Content-Security-Policy` gets no false warning.
- The default connection factory of `Database::fromConfig()` builds with `PDO::connect()`, so the handle is a `Pdo\Pgsql`. A factory you pass keeps working; on PHP 8.5 one used with `persistent: true` should build with `PDO::connect()` too, or each acquisition emits a deprecation.
- With debug on, the error log line of an unhandled 500 says why the debugger did not admit the client: `Zephyrus: unhandled exception, answered with a 500 (the debugger did not admit <addr>; add <secret>@<addr> to withDebugClientAllowlist() and send <secret> as the tracy-debug cookie, or add <addr> alone if it is your own machine): ...`, or, when the request carries `X-Forwarded-For`, `Forwarded` or `X-Real-IP`, `(the debugger did not admit <addr>, which forwarded the request; add <secret>@<addr> to withDebugClientAllowlist() and send <secret> as the tracy-debug cookie): ...`, or, when `REMOTE_ADDR` is missing or not an IP address, `(the debugger did not admit this client: REMOTE_ADDR is missing or not an IP address): ...`, or, with no allowlist, that none admits a client except a direct loopback client under `php -S`. `<addr>` is the client address and `<secret>` is written literally, never an entry to paste as is. With debug off, or for an admitted client, the line is unchanged.
- A dotted key written flat under a section whose first part is a mapping of that section (`smtp.host: h` under `mailer:`) is refused with `... is written flat: nest it under 'smtp', such as smtp: { host: ... }.`, where it listed the accepted keys or suggested a spelling. The example shows the nested key written (or the closest one when it is misspelled) and is left out when that key is not a plain field name.
- `DatabaseSessionHandler` implements `SessionIdInterface`, so PHP 8.6 declares and registers it without a warning or a deprecation. Its `create_sid()` returns 32 lowercase hexadecimal characters from `random_bytes()`. The `$id` and `$data` parameters of its callbacks are marked `#[\SensitiveParameter]`, and the closures and handler state that hold them are wrapped, so neither a stack trace nor a debug page with the framework's secret masking shows them. A handler that wraps `DatabaseSessionHandler` delegates `create_sid()` and `validateId()` too, and marks `$id` and `$data` `#[\SensitiveParameter]`.
- A `FileSystemException` for a write, a copy or a move that `File` could not complete ends with the function and the reason PHP gave, without the arguments of the call, when it gave one, such as `Failed to write to: "<path>" ("fwrite(): Write of 20 bytes failed with errno=27 File too large")`, where it ended at the path. `FileSystemException::operationFailed()` and `transferFailed()` take it as the optional `$reason`; without one the message ends at the last path it names.
- `MaxBodySizeMiddleware` measures the largest of a valid `Content-Length`, the raw body and the total size of the uploaded files, where a valid declared length alone was trusted: a request that declared a short length and sent a larger body, or a multipart upload whose files were larger than its declared length, passed the limit. A body of exactly the limit, or a declared length equal to it, is still accepted.
- `MaxBodySizeMiddleware` also counts the keys and values of the text fields (nested arrays included) when the raw body is empty, which is what a body PHP parsed itself, such as a multipart form, looks like, whatever its `Content-Type`; a urlencoded or JSON body is still measured by its raw body. A form sent without a valid `Content-Length` (chunked, or declaring fewer bytes than it sends) whose text fields and files together exceed `max_body_size` is now refused with a 413 where it passed; with an honest `Content-Length` it was already refused. Raise `max_body_size` (and `post_max_size`) or shorten the fields. `post_max_size` still bounds what PHP reads into memory. A total past `PHP_INT_MAX` counts as `PHP_INT_MAX`.

### Fixed

- `SafePath::within()` accepts `/` as its root: it used to resolve the empty string left by trimming `/` against the current directory, so `within('/', 'tmp/x')` returned `null` unless the current directory was `/`. An empty root is refused instead of being resolved against the current directory, and `within('/', '.')` returns `null`, as any root itself does.
- The `Translator` falls back through every shorter prefix of a locale, as RFC 4647 lookup does: `zh-Hant-TW` now tries `zh-Hant` before `zh`, and `fr-CA-x-foo` tries `fr-CA`. A tag of a language and at most one other subtag (`fr-CA`, `fr`) keeps the same chain.
- `Translator` and `JsonLocaleLoader` case a locale tag as BCP 47 does (language lowercase, script title case, region uppercase), so a catalog named `zh-Hant-TW.json` or `sr-Latn/` is found for `zh-hant-tw` or `zh_HANT_TW`; the `Translator` used to look for `zh-HANT-TW`. A custom `LocaleLoaderInterface` receives the tag in that casing too (`zh-Hant-TW`, `en-US-posix`), where it used to receive everything after the first hyphen in upper case. Catalogs named `fr-CA`, `fr_CA`, `fr-ca` or `fr_ca` are still found, and `fr_ca` now also finds a `fr-CA/` directory.
- `Rules::byteRange()` and `Rules::contentRange()` compare their bounds as decimal strings, so a bound past `PHP_INT_MAX` no longer saturates: `bytes=99999999999999999999-9223372036854775807` is refused and `bytes 0-9223372036854775807/99999999999999999999` accepted.
- A malformed regular expression given to `CsrfConfig` (`excludedPathPatterns`) or as a route constraint is refused with the framework's own exception (`InvalidArgumentException`, `RouteSignatureException`, `RouteUrlGenerationException`) even under an application error handler that throws on every warning (`@` ignored), where PHP's `ErrorException` escaped. The three refusals now end with PHP's reason for the failure (`... is not a valid regex: <pattern> (<reason>; expected a shape such as ...).`, `Invalid route constraint pattern for parameter "id" on route "/users/{id}": <pattern> (<reason>)`, `... invalid constraint pattern "<pattern>" (<reason>)`).
- Refusals escape control characters (`\r`, `\n`, `\uXXXX`) in the values they quote, so a refusal cannot break a log line:
  - CSRF (`CsrfConfig`): the pattern and the PCRE reason, and the body field.
  - Routing, route constraints (`RouteCollection`, `RouteUrlGenerator`): the pattern, the PCRE reason and the route path.
  - Routing, `Route`: the path, parameter name and segment of every path refusal.
  - Routing, `RouteUrlGenerator::generate()`: the unknown route name, quoted and capped.
  - Routing, route names: the duplicate route names, the route names read from attributes and the name passed to `name()` before any route.
  - Routing, middlewares: the route middleware and handler names and the previous exception message their refusals quote, the circular middleware group, the route, group and resource named by a skip refusal, the classes an excluded middleware group refusal lists, and the handler of an undeclared middleware group.
  - Routing, parameter resolution: the class, method, parameter, expected type and actual type named by a parameter refusal.
  - Routing, `#[RequiresEnv]`: the class and previous message of an unreadable attribute.
  - Routing, routes cache: the cache path, now quoted, its last 512 bytes kept.
  - Configuration: `Router::fromConfiguration()` quotes the application root, and the `router:` section quotes the controllers directory (its last 512 bytes kept) and names the namespace, the tag of a value or section written under an environment tag, the application root of a cache path that leaves it, and the file name of a section declared outside `config.yml`, all escaped.
- A route whose handler is a static method runs without PHP 8.6's deprecation (`ReflectionMethod::invoke()` for a static method does not need an object parameter): the resolver passes no object for a static method.
- `EnvironmentVariable::read()` reads a `false` placed in `$_ENV` as `"0"`, where it read `""`, which a boolean setting refused; `true` still reads `"1"`. `env()`, a `!env` value and `#[RequiresEnv]` read it the same way, so `#[RequiresEnv('X', '0')]` now matches it and `#[RequiresEnv('X', '')]` no longer does.
- The debug warnings of `SessionManager` (idle timeout, strict mode) and of `ContentSecurityPolicyMiddleware` read the raw `application.debug` instead of the effective flag, so a production boot that refused `debug: true` still wrote them to the logs. They now read `App::isDebug()`. `ContentSecurityPolicyMiddleware::isDebug()` (internal) is removed.
- The strict-mode debug warning of `SessionManager::start()` is raised only for a save handler with no `validateId()` method, which PHP calls whether or not the handler implements `SessionUpdateTimestampHandlerInterface`; a handler with a plain `validateId()` got a false warning. It now reads `Session save handler <class> has no validateId() method, so PHP skips its session.use_strict_mode check and will ADOPT a client-supplied session id. Implement validateId() to reject an id that does not already exist.`
- A static `ContentSecurityPolicyMiddleware` policy replaced by a `SecureHeadersMiddleware` registered inside it now triggers the same debug warning as a nonce policy; it was overwritten silently. The warning says "the policy of this request" instead of "the nonce policy of this request".
- An unreadable `config.yml` is reported as `Configuration file is not readable: config.yml`, where it was reported as invalid YAML.
- `MailerConfig::toArray()` exports `smtp.port` as `587` when the key is written as null (an empty `!env`), as `smtpPort` holds, where it exported null.
- `Entity::build()` refuses a readonly property its constructor already set (`Cannot hydrate property $id of A: it is readonly and its constructor already set it. Leave it unset in the constructor, or drop readonly.`) and a `private(set)` property (`Cannot hydrate property $id of D: its private(set) visibility keeps Entity from writing it. Use protected(set) instead.`), where PHP raised a bare `Error`; an uninitialized readonly and a `protected(set)` property are still hydrated.
- A virtual property with no set hook is refused the same way (`Cannot hydrate property $id of G: it is virtual with no set hook. Alias the column in the query, or give the property a set hook.`), where PHP raised a bare `Error`; `Entity::jsonSerialize()` omits a virtual property with no get hook, where it raised one.
- A type refusal under `mailer:` or `render:` names the section by its key (`'mailer'`, `'render'`), as an unknown key does, where it named the class. `ConfigSection::sectionName()` (protected, defaults to the class name) supplies it: a section of your own may override it.
- `Directory::delete()` removes a directory written with a trailing `/.` or `/./`, recursive or not, where `rmdir()` refused it. Nothing to change in your code.
- `File::create()`, `write()`, `append()` and `copy()` (its destination) refuse a path whose last segment is empty, `.` or `..` with `FileSystemException` (`Path does not end with a file name: "<path>"`), before creating any directory and without a PHP warning.
- A write that `File::create()`, `write()`, `append()` or `copy()` cannot complete (a full disk, a file size limit) throws `FileSystemException` without a PHP notice, which an error handler that throws turned into an `ErrorException`. A caller that caught `ErrorException` for it now catches `FileSystemException`.
- `cache:warm` refuses two caches at the same path, such as `router.cache: var/zephyrus/config.yml.php`, before it writes anything (`Two caches would be written to <path>: give router.cache another path.`, exit 1). The routes cache used to overwrite the configuration cache, which the application then ignored as stale. It also refuses a cache under or above another one, or under the `.gitignore` it writes beside the configuration cache, such as `router.cache: var/zephyrus/config.yml.php/routes.php` (`The cache <inner> would be written under the cache <outer>: give router.cache another path.`), and a `--config` given twice for the same path, in any spelling, is processed once.
- `cache:warm` runs the directory guards (mode, owner, link) and the regular-file check of every cache before it writes the first one, and `cache:clear` checks every file for the warm header before it deletes the first: a refusal in a later `--config` no longer leaves the caches of an earlier one written or removed.
- `router.cache` refuses a NUL byte (`Configuration section 'router' field 'cache' must not contain a NUL byte.`), where `cache:warm` died with an uncaught `ValueError`.
- `cache:warm`, `cache:clear` and the application read a `config.yml` that is a symbolic link under the directory of the link, as `ApplicationBuilder::fromConfigurationFile()` does for the application root, where they resolved the cache beside the link target: the cache of a linked `config.yml` is now `var/zephyrus/config.yml.php` beside the link. A cache written beside the target by an earlier `cache:warm` is no longer read: run `cache:warm` again.
- `cache:warm` of a `router:` section that is read from a `config.yml` outside the project root ends its refusal with `Note that router: is cached only from config.yml at the application root, and its paths were resolved against <directory>.`
- `ApplicationBuilder::withConfigurationFile()`, `withConfigurationFiles()`, `withOptionalConfigurationFiles()`, `fromConfigurationFiles()`, `fromOptionalConfigurationFiles()` and the `buildFrom...Files()` entries take the real path of the directory of the file (of the first file that exists for several files, each path trimmed as the loader does) as the application root, like `fromConfigurationFile()`: a `router:` section now builds, and a relative `localization.locale_path` is resolved against that directory instead of the current directory. When no file exists, the root stays unset.
- `Response::withCookie()` replaces an earlier `Set-Cookie` line of the same name, domain and path whatever the case and spacing of its attributes (`path=/`, `PATH=/`, `;Path=/`), as browsers read them, where only the exact `; Path=` and `; Domain=` spelling was recognised and the response sent both lines. A name spaced from its `=` and an empty `Domain=` are read as browsers read them (RFC 6265, 5.2), and a line marked `Partitioned` is kept, since browsers store a partitioned cookie apart from the one `withCookie()` sets.
- Dumping an `ArrayIterator` with the debugger no longer calls `ArrayIterator::getFlags()` and `setFlags()`, which PHP 8.6 deprecates; the dump reads the same on PHP 8.4 and 8.6.
- On PHP 8.6, a NUL byte in a configuration file path, a controller directory, or the path given to `Cryptography::hashFile()`, `Router::discoverControllers()`, `File` or `Directory` no longer throws a `ValueError`: the path is not a file, not a directory, does not exist or is not readable, as on PHP 8.4. A `Request` built from a URL that `parse_url()` cannot parse cuts its query string at a NUL byte, as PHP 8.4 did, where PHP 8.6 threw a `ValueError`.
- `DatabaseSessionHandler` checks `idPattern` on 17 sample ids, one per hexadecimal digit repeated 32 times and one mixing digits and letters, where three fixed ids let a pattern that refused one digit at one position pass. It reads PHP's reason for an invalid expression through a handler set for that call alone, so an application error handler that returns `true` no longer turns the reason into `Internal error`, and one that throws no longer lets its `ErrorException` out in place of `SessionException`. A pattern that compiles but fails when matched (such as `Backtrack limit exhausted`) is refused with `The idPattern "<pattern>" could not be matched against a session id: <reason>. Simplify the pattern (avoid nested quantifiers), or raise the PCRE limit it exhausted (pcre.backtrack_limit or pcre.recursion_limit).`, where it was reported as `is not a valid regular expression`.
- A path outside `open_basedir` no longer makes `File`, `Directory` or `SafePath` print PHP's `open_basedir restriction in effect` warning, which names the allowed paths. As before, `exists()`, `isReadable()`, `isWritable()`, `SafePath::isFile()`, `isDirectory()` and `isReadable()` return `false`, `SafePath::within()` returns `null`, the methods that need the path to exist (`read()`, `size()`, `lines()`, `permissions()`, `lastModified()`, `Directory::files()` and the others) throw `FileSystemException` (`Path not found: "<path>"`), `File::create()`, `copy()` and `move()` and `Directory::ensure()` and `create()` throw `FileSystemException` (`Failed to create directory for: "<path>"`, `Failed to create directory: "<path>"`, each with its reason), `Directory::files()` skips a link to a file outside it, and `File::delete()` and `Directory::delete()` throw `FileSystemException` there (see Breaking changes). An error handler that respects the `@` operator, which used to turn that warning into an exception, no longer sees it, so these calls now return or throw `FileSystemException` in that application too. Nothing to change in your code.
- `Directory::delete(true)` of a directory holding a link to a target outside `open_basedir` throws `FileSystemException` (`Failed to delete: "<path>"`, the PHP exception as its previous), without a PHP warning, where it threw `RuntimeException` or `UnexpectedValueException` naming the allowed paths.
- `File::create()`, `copy()`, `move()` and `Directory::ensure()`, `create()` end their `Failed to create directory...` message with the reason PHP gave, such as `("mkdir(): open_basedir restriction in effect. File(<path>) is not within the allowed path(s): (<paths>)")`, where the cause was left out.
- `SafePath::within()` returns `null` for a root holding a NUL byte, where `realpath()` threw `ValueError`. Nothing to change in your code.

### Documentation

Pages of zephyrus.ophelios.com that this version makes stale, each in English and in French (`content/fr/`):

- `getting-started/introduction.md`: Zephyrus is tested on PHP 8.4, 8.5 and 8.6.
- `fundamentals/routing.md`: on PHP 8.5 and later, dropping the copy a `with*()` method returns raises a warning (`Router::withTrailingSlashTolerance()` and `withoutMiddleware()` included).
- `fundamentals/sessions.md`: `DatabaseSessionHandler` generates the session ids itself and checks `idPattern` against them.
- `fundamentals/data.md`: `DatabaseActor` publishes the actor of row level security policies for one transaction (`actAs()`, `publish()`).
- `digging-deeper/filesystem.md`: the quoted paths in `FileSystemException` messages, the new refusals of `File` and `Directory`, and a path outside `open_basedir`, the `delete()` that throws there, and the reason that ends a `Failed to create directory` message.
- `fundamentals/validation.md`: the closure given to `Rule::of()` should mark its parameter `#[\SensitiveParameter]` when it receives a secret, since core's rules hide the value from a stack trace.
- `fundamentals/views.md`: `LatteEngine::addNamespace()`, `@namespace/page` templates for Composer packages, and the `{views}/vendor/{namespace}/` override.
- `fundamentals/security.md`, `fundamentals/http.md`, `fundamentals/application.md`, `fundamentals/authentication.md` and `getting-started/first-application.md`: the `Request::fromGlobals()` bootstrap passes `maxBodySize: $config->security->maxBodySize`, and `MaxBodySizeMiddleware` is given the same limit so the body is never read past it.
- `fundamentals/localization.md`: the lookup order takes every shorter prefix of the requested locale, then of the default locale (`zh-Hant-TW`, `zh-Hant`, `zh`).

## [0.16.0] - 2026-11-13

### Breaking changes

#### Routing

- `RouteCache` is `@internal` and its public API is gone: `new RouteCache($cacheFile)`, `filePath()`, `has()`, `clear()`, `save()`, `warm()`, `isFresh()`, `metadata()`, `generatedAt()`, `routeCount()`, `routesHash()`, `metadataVersion()`, `age()`, `expiresAt()`, `isExpired()`, `isFreshWithin()`, `ensureFreshWithin()`, `canUseWithin()`, `inspect()` and `warmIfStale()` no longer exist (`load()` is internal and returns `?RouteCollection`), nor does `RouteCacheException::refused()`. A cache of a build artifact is judged by what it was read from, not by its age. Replace the calls with the `router:` section and `vendor/bin/zephyrus cache:warm` (see Added), and delete any code that warms or inspects a `RouteCache` itself.

#### Core

- `ApplicationBuilder::fromConfigurationFile()` and `buildFromConfigurationFile()` pass the real path of the file's directory as `basePath`, so a relative `localization.locale_path` resolves against that directory, where it used to resolve against the current directory. Write the path relative to the configuration file, or absolute.

#### Configuration

- `router` is a reserved section name: a section factory registered under it throws `InvalidArgumentException` (`Section factory "router" collides with the built-in section "router"; read it with $configuration->router instead.`), and a `router:` key in `config.yml` is now read and validated, where it used to be ignored unless registered. An application that has its own `router:` section: rename it (`routing`, for instance). A section named `routes` is now refused as a typo of `router`: rename it too.

### Added

- A built-in `router:` section in `config.yml` declares the controllers (`controllers`, a PSR-4 namespace to directory map, required), the `middleware_groups` (as `Router::middlewareGroup()`) and the routes `cache` file (a `.php` file under the application root, default `var/zephyrus/routes.php`). Relative paths resolve against `basePath`, no value may carry a YAML tag (`!env` included), and a controller directory that is missing or holds no class of its namespace is refused, where `discoverControllers()` registers nothing silently. The cache holds every route path, name and constraint in clear: never build a route from a secret.
- `Router::fromConfiguration(Configuration $configuration, string $basePath)` builds the router of that section. In production and staging it reads the routes cache, and falls back to discovery with one `error_log` line when the cache is absent, stale or refused, or when `basePath` holds no `config.yml` (the routes cache is read only from the `config.yml` of the application root); in development and testing it always discovers. The cache is stale once `config.yml`, a file under a controller directory, or the file of a parent class, trait or interface of a controller changes (to the second). A constant of another class read by an attribute is not tracked: run `cache:warm` on every build. `#[RequiresEnv]` is evaluated on every request, so one cache serves every `MODE` and `APP_ENV`. Two routes of one name that can be registered together are refused, by the warm and by the discovery in development.
- `ApplicationBuilder::build()` builds that router when the configuration declares `router:` and there is no `withRouter()`; it refuses a `withConfiguration()` without `basePath` and a `withRouter()` whose router `Router::fromConfiguration()` did not build (or derive), so that a half-done migration cannot write a cache nobody reads. To upgrade: add `router: { controllers: { App\Controllers: app/Controllers } }` to `config.yml`, call `withConfiguration($config, basePath: $root)` and delete the `discoverControllers()` and `withRouter()` lines (or replace the router with `Router::fromConfiguration($config, basePath: $root)` when it is needed before `build()`). Without a `router:` section nothing changes. Declare the groups of `middlewareGroup()` under `router.middleware_groups`. `ApplicationBuilder::fromConfigurationFile()` and `buildFromConfigurationFile()` take the directory of the file as `basePath`.
- `vendor/bin/zephyrus cache:warm` and `cache:clear` (`--config=PATH`, repeatable, default the `config.yml` of the project root) write and delete the caches: `var/zephyrus/config.yml.php` and, with a `router:` section, `var/zephyrus/routes.php`. Exit codes: 0, 1 refused, 2 usage error. The command reads no `.env` and resolves no `!env`, and before it writes anything it checks that each configuration is YAML in the form of a mapping and its `router:` section, the process owner, and that a file already at a cache path carries the warm header. The directory guards (mode, owner, link) and the regular-file check run when each file is written, so a refused directory can leave the caches written before it (valid, `.gitignore` included); `cache:clear` checks the header only when it deletes, so it can remove the caches of a first configuration before refusing a foreign file in the next. The files are protected by their owner and file modes, and are not included otherwise; a file at a cache path that `cache:warm` did not write is never replaced or deleted. Warming writes `var/zephyrus/.gitignore` (`*`) unless one is there. To upgrade: run `RUN php vendor/bin/zephyrus cache:warm` in the image build, after `COPY` and `composer dump-autoload`, before any `USER`, on a path no `chown` touches afterwards; add `var/zephyrus/` to `.dockerignore`.
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
- `symfony/yaml` must be `^7.4.23` (earlier releases carry parser denial-of-service advisories). Run `composer update symfony/yaml`.

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
- `selectValue()` returns a boolean `false` column as `false`, and `select()` and `selectOne()` return PostgreSQL arrays as PHP arrays, with string elements (`{1,2}` gives `['1','2']`), where `selectValue()` returns the raw text (`"{a,b}"`). Drop any manual `'t'`/`'f'` or `{...}` parsing in rows.
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

- `format()` and translation pipes accept only built-in names (any case) and registered custom formatters; an unknown name throws `FormatterException`. Register the formatter, or fix its name. `FormatterException::unknownFormatter()` takes a second argument, `$builtIns`.
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

- The `CsrfConfig` `injectToken` argument and the `SecurityConfig` `csrfAutoHtml` argument, since 0.14, to be removed in 4.0. Leave `injectToken` unset (`true` throws) and pass `csrfAutoHtml: false` (nothing reads the value).

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

[Unreleased]: https://github.com/ophelios-studio/zephyrus-core/compare/v3.17.2...HEAD
[3.17.2]: https://github.com/ophelios-studio/zephyrus-core/compare/v3.17.1...v3.17.2
[3.17.1]: https://github.com/ophelios-studio/zephyrus-core/compare/v3.17.0...v3.17.1
[3.17.0]: https://github.com/ophelios-studio/zephyrus-core/compare/v0.16.0...v3.17.0
[0.16.0]: https://github.com/ophelios-studio/zephyrus-core/compare/v0.15.0...v0.16.0
[0.15.0]: https://github.com/ophelios-studio/zephyrus-core/compare/v0.14.1...v0.15.0
[0.14.1]: https://github.com/ophelios-studio/zephyrus-core/compare/v0.14.0...v0.14.1
[0.14.0]: https://github.com/ophelios-studio/zephyrus-core/compare/v0.13.0...v0.14.0
[0.13.0]: https://github.com/ophelios-studio/zephyrus-core/releases/tag/v0.13.0
