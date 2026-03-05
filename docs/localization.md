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
