<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/ophelios-studio/zephyrus-core/dev/.github/assets/zephyrus-logo-dark.png">
    <img src="https://raw.githubusercontent.com/ophelios-studio/zephyrus-core/dev/.github/assets/zephyrus-logo-light.png" alt="Zephyrus" width="325" height="55">
  </picture>
</p>

<div align="center">

[![CI](https://github.com/ophelios-studio/zephyrus-core/actions/workflows/ci.yml/badge.svg?branch=dev)](https://github.com/ophelios-studio/zephyrus-core/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/ophelios-studio/zephyrus-core/graph/badge.svg)](https://codecov.io/gh/ophelios-studio/zephyrus-core)
[![Packagist](https://img.shields.io/packagist/v/zephyrus-framework/core)](https://packagist.org/packages/zephyrus-framework/core)
[![PHP](https://img.shields.io/packagist/dependency-v/zephyrus-framework/core/php)](https://packagist.org/packages/zephyrus-framework/core)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208-brightgreen)](phpstan.neon.dist)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

</div>

A cohesive PHP 8.4+ framework core for server-rendered applications and JSON APIs. Attribute-based routing, immutable HTTP objects, typed configuration, and a full security middleware stack that refuses to boot when a declared protection is not mounted. It is a library, not a skeleton generator: your application is your own classes, a `config.yml` and a front controller.

<p align="center"><strong>
  <a href="https://zephyrus.ophelios.com/getting-started/introduction/">Documentation</a> &nbsp;·&nbsp; <a href="https://zephyrus.ophelios.com/fr/getting-started/introduction/">Documentation en français</a> &nbsp;·&nbsp; <a href="https://github.com/ophelios-studio/zephyrus-core/blob/dev/CHANGELOG.md">Changelog</a>
</strong></p>

## Highlights

- **Routes live next to their controller.** `#[Get]`, `#[Post]` and the other verb attributes sit on the method that answers, `#[Root]` prefixes a class, controllers are discovered from a directory, and placeholders are cast to the declared parameter type. [Routing](https://zephyrus.ophelios.com/fundamentals/routing/)
- **Security is wired, and checked.** Each `security:` setting (HTTPS, allowed hosts, CSRF, body size, headers) must be enforced by a mounted middleware built from it, or acknowledged as enforced elsewhere: otherwise `build()` refuses to boot and names the middleware to mount. [Security](https://zephyrus.ophelios.com/fundamentals/security/)
- **Configuration refuses mistakes.** `config.yml` is read into typed sections, a misspelled key stops the boot with a suggestion, and `!env` reads values from the environment. [Configuration](https://zephyrus.ophelios.com/fundamentals/configuration/)
- **Requests and responses are immutable.** `Request` and `Response` are readonly values: `withHeader()` returns a new response instead of changing the one you hold. [HTTP](https://zephyrus.ophelios.com/fundamentals/http/)
- **Sessions and CSRF protection.** Session middleware, flash messages, a CSRF middleware that requires a synchronizer token on every state-changing request, and an optional PostgreSQL session store. [Sessions](https://zephyrus.ophelios.com/fundamentals/sessions/)
- **A PostgreSQL layer on explicit SQL.** Parameterised queries, transactions, brokers and entities, and paginated, sorted and filtered listings from request input. It is not an ORM. [Data access](https://zephyrus.ophelios.com/fundamentals/data/)

Also in the package: [Latte views](https://zephyrus.ophelios.com/fundamentals/views/), [validation](https://zephyrus.ophelios.com/fundamentals/validation/), [localization](https://zephyrus.ophelios.com/fundamentals/localization/), [mail](https://zephyrus.ophelios.com/digging-deeper/mail/), [uploads](https://zephyrus.ophelios.com/digging-deeper/uploads/) and [cryptography](https://zephyrus.ophelios.com/digging-deeper/cryptography/).

## Installation

```bash
composer require zephyrus-framework/core
```

Before 1.0 a minor version may break the API: read the [changelog](https://github.com/ophelios-studio/zephyrus-core/blob/dev/CHANGELOG.md) before moving to the next one.

| Requirement | Version |
|---|---|
| PHP | `^8.4` |
| Extensions | `mbstring`, `pdo`, `fileinfo`, `intl`, `sodium` |
| Database | `pdo_pgsql`, for a `database:` section (PostgreSQL) |
| Optional | `apcu`, to cache locale catalogs with `CachedLocaleLoader` |

Runtime dependencies: `symfony/yaml`, `vlucas/phpdotenv`, `latte/latte`, `tracy/tracy`, `phpmailer/phpmailer`.

## A first application

Three files boot an application, once `composer.json` maps the `App\` namespace to `app/` (`"autoload": { "psr-4": { "App\\": "app/" } }`, then `composer dump-autoload`).

`config.yml`:

```yaml
application:
  environment: !env APP_ENV, production
  debug: !env APP_DEBUG, false
```

`app/Controllers/HelloController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use Zephyrus\Controller\Controller;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;

final class HelloController extends Controller
{
    #[Get('/hello')]
    public function hello(): Response
    {
        return $this->json(['message' => 'Hello, world.']);
    }
}
```

`public/index.php`, the front controller:

```php
<?php

declare(strict_types=1);

use Zephyrus\Core\ApplicationBuilder;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Http\Request;
use Zephyrus\Routing\Router;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$config = Configuration::fromYamlFile($root . '/config.yml');
$router = (new Router())->discoverControllers('App\\Controllers', $root . '/app/Controllers');

ApplicationBuilder::create()
    ->withConfiguration($config, basePath: $root)
    ->withRouter($router)
    ->build()
    ->handle(Request::fromGlobals())
    ->send();
```

PHP's built-in server is enough for development. The configuration defaults to `production`, so set `APP_ENV=dev` on your machine:

```bash
APP_ENV=dev php -S 127.0.0.1:8000 -t public
```

```bash
curl -i http://127.0.0.1:8000/hello
```

```text
HTTP/1.1 200 OK
content-type: application/json; charset=utf-8

{"message":"Hello, world."}
```

The output is abridged: the real response carries more headers (`Host`, `Date`, `X-Powered-By` and so on).

An unknown path answers `404 Not Found`, and `POST /hello` answers `405 Method Not Allowed` with `allow: GET, HEAD`.

The built-in server is for development only. The [first application](https://zephyrus.ophelios.com/getting-started/first-application/) tutorial goes on with sessions, CSRF protection, validation, Latte views and a test.

## Development

```bash
git clone https://github.com/ophelios-studio/zephyrus-core zephyrus-core
cd zephyrus-core
composer install
composer test      # PHPUnit
composer analyse   # PHPStan level 8 on src/
```

The PostgreSQL integration tests are skipped unless `ZEPHYRUS_TEST_PGSQL_DSN` holds a PDO DSN with its credentials; CI always runs them. To run them against a throwaway container:

```bash
docker run --rm -d --name zephyrus-pgsql -e POSTGRES_PASSWORD=postgres -p 55432:5432 postgres:16
until docker exec zephyrus-pgsql pg_isready -h 127.0.0.1 -U postgres; do sleep 1; done
ZEPHYRUS_TEST_PGSQL_DSN='pgsql:host=127.0.0.1;port=55432;dbname=postgres;user=postgres;password=postgres' composer test
docker stop zephyrus-pgsql
```

Run with coverage (requires Xdebug): `XDEBUG_MODE=coverage php vendor/bin/phpunit --coverage-text`.

A pull request to `dev` must pass the CI checks (commit messages, and the PHP matrix running the dependency audit, the static analysis and the tests) and `codecov/patch` before it can merge. Codecov requires 90% coverage on each patch, and the project may not drop more than 0.5%. Every change should come with tests.

Commits are one semantic line (`type(scope): description`), with no body and no co-author trailer. The pull request title is checked the same way, because GitHub squash-merges with it. Check both before pushing:

```bash
.github/scripts/check-commit-messages.sh origin/dev..HEAD
.github/scripts/check-commit-messages.sh --title "feat(scope): description"
```

## License

MIT, see [LICENSE](LICENSE).
