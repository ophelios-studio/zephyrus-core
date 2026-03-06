# Localization

Zephyrus ships a lightweight translation stack focused on predictable fallback behavior and framework-level bootstrap integration.

## Building an application with JSON locale files

```php
use Zephyrus\Core\ApplicationBuilder;

$app = ApplicationBuilder::create()
    ->withJsonLocales(__DIR__ . '/../resources/locales', defaultLocale: 'en')
    ->withSupportedLocales(['en', 'fr'])
    ->build();
```

Locale files are loaded from `<path>/<locale>.json`.

Example `resources/locales/en.json`:

```json
{
  "messages": {
    "welcome": "Welcome {name}",
    "plain": "Hello"
  },
  "errors": {
    "required": "The {field} field is required"
  }
}
```

Nested JSON objects are flattened with dot notation (`messages.welcome`, `errors.required`).

## Translating values

```php
$message = $app->trans('messages.welcome', ['name' => 'Alice']);
// Welcome Alice
```

## Locale resolution from requests

Use `transFromRequest()` to combine explicit locale overrides and `Accept-Language` header negotiation:

```php
$message = $app->transFromRequest(
    'messages.plain',
    request: $request,
    requestedLocale: $routeLocale // optional explicit override
);
```

If you need locale negotiation without translating immediately, use:

```php
$locale = $app->resolveLocaleFromRequest(
    request: $request,
    requestedLocale: $routeLocale,
);
```

Resolution priority:
1. explicit `requestedLocale`
2. `Accept-Language` header (q-values respected)
3. app `defaultLocale`

When `supportedLocales` is configured, locale selection is constrained to that allowlist, with regional fallback (`fr-CA` → `fr`) when possible.

## Interpolation pipes

Translator placeholders support optional pipe transforms:

```text
{name|upper}
{count|number:0:,:.}
{label|truncate:16:...}
{qty|plural:item:items}
```

Supported pipes include `lower`, `upper`, `title`, `trim`, `ltrim`, `rtrim`, `number`, `truncate`, `plural`, and `default`.

## Error behavior

- Missing locale files return an empty catalog (safe no-op).
- Invalid JSON throws `RuntimeException` with context (`Invalid JSON in locale file ...`).
- JSON content must decode to an object/map.

## Layered fallback catalogs

Use `FallbackLocaleLoader` to compose multiple loaders (last wins):

```php
use Zephyrus\Localization\FallbackLocaleLoader;
use Zephyrus\Localization\JsonLocaleLoader;

$loader = new FallbackLocaleLoader([
    new JsonLocaleLoader('/vendor/package/locales'),
    new JsonLocaleLoader('/app/resources/locales'), // overrides vendor keys
]);
```

For a JSON-only bootstrap shortcut, use `ApplicationBuilder::withJsonLocaleLayers()`:

```php
$app = ApplicationBuilder::create()
    ->withJsonLocaleLayers([
        '/vendor/package/locales',
        '/app/resources/locales', // overrides vendor keys
    ], defaultLocale: 'en')
    ->build();
```

For config-driven bootstrap, use `LocalizationConfig`:

```php
use Zephyrus\Core\Config\LocalizationConfig;

$config = LocalizationConfig::fromArray([
    'default_locale' => 'en',
    'supported_locales' => ['en', 'fr'],
    'json_locale_paths' => ['/app/resources/locales'],
]);

$app = ApplicationBuilder::create()
    ->withLocalizationConfig($config)
    ->build();
```

Or apply the full typed root config tree:

```php
use Zephyrus\Core\Config\Configuration;

$configuration = Configuration::fromFile(__DIR__ . '/../config/app.php');

$app = ApplicationBuilder::create()
    ->withConfiguration($configuration)
    ->build();
```

For layered environments (base + local override), merge multiple files:

```php
$configuration = Configuration::fromFiles([
    __DIR__ . '/../config/app.php',
    __DIR__ . '/../config/app.local.php',
]);
```

Duplicate path entries are ignored after normalization.

Builder shortcuts for layered files are also available:

```php
$app = ApplicationBuilder::buildFromConfigurationFiles([
    __DIR__ . '/../config/app.php',
    __DIR__ . '/../config/app.local.php',
]);
```

If local override files are optional, use:

```php
$app = ApplicationBuilder::buildFromOptionalConfigurationFiles([
    __DIR__ . '/../config/app.php',
    __DIR__ . '/../config/app.local.php', // may be missing
]);
```

Or parse and apply a plain config array directly:

```php
$app = ApplicationBuilder::create()
    ->withConfigurationArray(require __DIR__ . '/../config/app.php')
    ->build();
```

Or load the config file in one step:

```php
$app = ApplicationBuilder::create()
    ->withConfigurationFile(__DIR__ . '/../config/app.php')
    ->build();
```

Static bootstrap shortcuts are also available:

```php
$app = ApplicationBuilder::fromConfigurationFile(__DIR__ . '/../config/app.php')
    ->build();
```

Or build directly from config in one call:

```php
$app = ApplicationBuilder::buildFromConfigurationFile(__DIR__ . '/../config/app.php');
```

For a minimal bootstrap entrypoint, use `ApplicationBootstrap`:

```php
use Zephyrus\Core\Bootstrap\ApplicationBootstrap;

$app = ApplicationBootstrap::fromConfigFiles(
    requiredConfigFiles: [__DIR__ . '/../config/app.php'],
    optionalConfigFiles: [__DIR__ . '/../config/app.local.php'],
);
```

Additional bootstrap shortcuts:

```php
$app = ApplicationBootstrap::fromConfigurationFile(__DIR__ . '/../config/app.php');
$app = ApplicationBootstrap::fromConfigurationArray(require __DIR__ . '/../config/app.php');
```

Or use a conventional config directory bootstrap:

```php
$app = ApplicationBootstrap::fromConfigDirectory(__DIR__ . '/../config');
// loads app.php + optional app.local.php + optional app.<APP_ENV>.php

$app = ApplicationBootstrap::fromConfigDirectory(
    __DIR__ . '/../config',
    environment: 'staging', // explicit override (or '' to disable env override)
);

$paths = ApplicationBootstrap::configPathsForDirectory(__DIR__ . '/../config');
// ['required' => '.../app.php', 'optional' => ['.../app.local.php', ...]]

$app = ApplicationBootstrap::fromResolvedPaths($paths);
```

If a config file is missing, returns a non-array payload, throws while loading, a path entry is invalid/empty, or config-directory inputs are invalid, bootstrap raises a `RuntimeException` with context.
