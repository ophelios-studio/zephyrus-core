<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Routing\Exception\RouteMiddlewareException;
use Zephyrus\Routing\Exception\RouteSignatureException;
use Zephyrus\Routing\Route;
use Zephyrus\Security\AllowedHostsMiddleware;
use Zephyrus\Security\AuthGuardMiddleware;
use Zephyrus\Security\ContentSecurityPolicyMiddleware;
use Zephyrus\Security\CsrfMiddleware;
use Zephyrus\Security\ForceHttpsMiddleware;
use Zephyrus\Security\MaxBodySizeMiddleware;
use Zephyrus\Security\SecureHeadersMiddleware;
use Zephyrus\Session\SessionMiddleware;

final class RouteTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function nonMiddlewareClasses(): iterable
    {
        yield 'unknown class' => ['App\\Missing\\Middleware'];
        yield 'empty string' => [''];
        yield 'NUL byte' => ["Zephyrus\\Session\0SessionMiddleware"];
        yield 'class that is not a middleware' => [\stdClass::class];
        yield 'route value object' => [Route::class];
        yield 'middleware name instead of class' => ['session'];
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function securityMiddlewares(): iterable
    {
        yield 'force https' => [ForceHttpsMiddleware::class];
        yield 'allowed hosts' => [AllowedHostsMiddleware::class];
        yield 'csrf' => [CsrfMiddleware::class];
        yield 'max body size' => [MaxBodySizeMiddleware::class];
        yield 'secure headers' => [SecureHeadersMiddleware::class];
        yield 'content security policy' => [ContentSecurityPolicyMiddleware::class];
        yield 'middleware interface itself' => [MiddlewareInterface::class];
    }

    public function testDefineStoresExcludedMiddlewaresWithoutDuplicates(): void
    {
        $route = Route::define('GET', '/', 'HomeController@index', excludedMiddlewares: [
            SessionMiddleware::class,
            AuthGuardMiddleware::class,
            SessionMiddleware::class,
        ]);

        self::assertSame([SessionMiddleware::class, AuthGuardMiddleware::class], $route->excludedMiddlewares);
    }

    public function testDefineStoresTheDeclaredClassNameWhateverTheSpelling(): void
    {
        $route = Route::define('GET', '/', 'HomeController@index', excludedMiddlewares: [
            'zephyrus\\session\\sessionmiddleware',
            '\\Zephyrus\\Session\\SessionMiddleware',
            SessionMiddleware::class,
        ]);

        self::assertSame([SessionMiddleware::class], $route->excludedMiddlewares);
    }

    public function testDefineRecognisesASecurityMiddlewareWhateverTheSpelling(): void
    {
        try {
            Route::define('GET', '/', 'HomeController@index', excludedMiddlewares: [
                '\\zephyrus\\security\\secureheadersmiddleware',
            ]);
            self::fail('Excluding SecureHeadersMiddleware must be refused.');
        } catch (RouteMiddlewareException $exception) {
            self::assertStringContainsString('is a framework security middleware', $exception->getMessage());
        }
    }

    public function testNotAMiddlewareRefusalSaysWhatToPass(): void
    {
        try {
            Route::define('GET', '/', 'HomeController@index', excludedMiddlewares: ['session']);
            self::fail('A name that is not a class must be refused.');
        } catch (RouteMiddlewareException $exception) {
            self::assertStringContainsString('SessionMiddleware::class', $exception->getMessage());
        }
    }

    public function testRouteExcludesNoMiddlewareByDefault(): void
    {
        self::assertSame([], Route::define('GET', '/', 'HomeController@index')->excludedMiddlewares);
    }

    #[DataProvider('nonMiddlewareClasses')]
    public function testDefineRefusesExcludingSomethingThatIsNotAMiddleware(string $class): void
    {
        $this->expectException(RouteMiddlewareException::class);
        $this->expectExceptionMessage('does not implement ' . MiddlewareInterface::class);

        Route::define('GET', '/', 'HomeController@index', excludedMiddlewares: [$class]);
    }

    #[DataProvider('securityMiddlewares')]
    public function testDefineRefusesExcludingAFrameworkSecurityMiddleware(string $class): void
    {
        $this->expectException(RouteMiddlewareException::class);
        $this->expectExceptionMessage('framework security middleware');

        Route::define('GET', '/', 'HomeController@index', excludedMiddlewares: [$class]);
    }

    public function testConstructorRefusesExcludingAFrameworkSecurityMiddleware(): void
    {
        $this->expectException(RouteMiddlewareException::class);

        new Route('GET', '/', 'HomeController@index', excludedMiddlewares: [SecureHeadersMiddleware::class]);
    }

    public function testCsrfRefusalPointsAtTheCsrfExceptionsSetting(): void
    {
        try {
            Route::define('POST', '/webhook', 'HookController@receive', excludedMiddlewares: [CsrfMiddleware::class]);
            self::fail('Excluding CsrfMiddleware must be refused.');
        } catch (RouteMiddlewareException $exception) {
            self::assertStringContainsString('POST /webhook', $exception->getMessage());
            self::assertStringContainsString('security.csrf.exceptions', $exception->getMessage());
        }
    }

    public function testWithNameKeepsTheExcludedMiddlewares(): void
    {
        $route = Route::define('GET', '/', 'HomeController@index', excludedMiddlewares: [SessionMiddleware::class]);

        self::assertSame([SessionMiddleware::class], $route->withName('home')->excludedMiddlewares);
    }

    public function testWithExcludedMiddlewaresKeepsTheOtherFields(): void
    {
        $route = Route::define('GET', '/users/{id}', 'UserController@show', ['id' => '\\d+'], ['auth'], 'users.show');
        $excluding = $route->withExcludedMiddlewares([SessionMiddleware::class]);

        self::assertSame([], $route->excludedMiddlewares);
        self::assertSame([SessionMiddleware::class], $excluding->excludedMiddlewares);
        self::assertSame(['id' => '\\d+'], $excluding->constraints);
        self::assertSame(['auth'], $excluding->middlewares);
        self::assertSame('users.show', $excluding->name);
    }

    public function testWithExcludedMiddlewaresRefusesASecurityMiddleware(): void
    {
        $this->expectException(RouteMiddlewareException::class);

        Route::define('GET', '/', 'HomeController@index')->withExcludedMiddlewares([CsrfMiddleware::class]);
    }

    public function testDefineNormalizesMethodAndPath(): void
    {
        $route = Route::define('get', 'users', 'UserController@index');

        self::assertSame('GET', $route->method);
        self::assertSame('/users', $route->path);
        self::assertSame('UserController@index', $route->handler);
    }

    public function testDefineKeepsRootPathAsSlash(): void
    {
        $route = Route::define('post', '/', 'HealthController@ping');

        self::assertSame('/', $route->path);
    }

    public function testMatchesMethodIsCaseInsensitive(): void
    {
        $route = Route::define('delete', '/users/{id}', 'UserController@delete');

        self::assertTrue($route->matchesMethod('DELETE'));
        self::assertTrue($route->matchesMethod('delete'));
        self::assertFalse($route->matchesMethod('PATCH'));
    }

    public function testDefineAcceptsRouteMiddlewareNames(): void
    {
        $route = Route::define('GET', '/users', 'UserController@index', [], ['auth', 'audit']);

        self::assertSame(['auth', 'audit'], $route->middlewares);
    }

    public function testWithNameReturnsNamedClone(): void
    {
        $route = Route::define('GET', '/users', 'UserController@index');
        $named = $route->withName('users.index');

        self::assertNull($route->name);
        self::assertSame('users.index', $named->name);
    }

    public function testIsSkippableRecognisesASecurityMiddlewareWhateverTheSpelling(): void
    {
        self::assertFalse(Route::isSkippable('\\zephyrus\\security\\secureheadersmiddleware'));
        self::assertFalse(Route::isSkippable('zephyrus\\security\\csrfmiddleware'));
        self::assertTrue(Route::isSkippable('\\' . SessionMiddleware::class));
        self::assertFalse(Route::isSkippable('Missing\\Middleware'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pathsHoldingAQueryOrFragmentMark(): iterable
    {
        yield 'query after a literal' => ['/a?b', 'Invalid route path "/a?b": the request path ends at "?", '
            . 'so this route would only match "/a"; declare "/a" and read the query string from the request'];
        yield 'fragment after a literal' => ['/a#b', 'Invalid route path "/a#b": a browser never sends the "#" '
            . 'fragment, so this route would only match "/a"; declare "/a"'];
        yield 'bare query mark at the root' => ['/?', 'Invalid route path "/?": the request path ends at "?", '
            . 'so this route would only match "/"; declare "/" and read the query string from the request'];
        yield 'query after a placeholder' => ['/users/{id}?x', 'Invalid route path "/users/{id}?x": the request '
            . 'path ends at "?", so this route would only match "/users/{id}"; declare "/users/{id}" and read the '
            . 'query string from the request'];
        yield 'query after a trailing slash' => ['/a/?x', 'Invalid route path "/a/?x": the request path ends at "?", '
            . 'so this route would only match "/a/"; declare "/a/" and read the query string from the request'];
        yield 'fragment after a trailing slash' => ['/a/#b', 'Invalid route path "/a/#b": a browser never sends the "#" '
            . 'fragment, so this route would only match "/a/"; declare "/a/"'];
        yield 'query after a search segment' => ['/search?q', 'Invalid route path "/search?q": the request path ends '
            . 'at "?", so this route would only match "/search"; declare "/search" and read the query string from the '
            . 'request'];
        yield 'query before a fragment' => ['/a?b#c', 'Invalid route path "/a?b#c": the request path ends at "?", '
            . 'so this route would only match "/a"; declare "/a" and read the query string from the request'];
        yield 'fragment before a query' => ['/a#b?c', 'Invalid route path "/a#b?c": a browser never sends the "#" '
            . 'fragment, so this route would only match "/a"; declare "/a"'];
    }

    #[DataProvider('pathsHoldingAQueryOrFragmentMark')]
    public function testConstructorRefusesAPathHoldingAQueryOrFragmentMark(string $path, string $message): void
    {
        try {
            new Route('GET', $path, 'HomeController@index');
            self::fail('A path holding "?" or "#" must be refused.');
        } catch (RouteSignatureException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    public function testDefineRefusesAPathHoldingAQueryMarkWithTheNormalizedPath(): void
    {
        try {
            Route::define('GET', 'a?b/', 'HomeController@index');
            self::fail('A path holding "?" must be refused.');
        } catch (RouteSignatureException $exception) {
            self::assertSame(
                'Invalid route path "/a?b": the request path ends at "?", so this route would only match "/a"; '
                . 'declare "/a" and read the query string from the request',
                $exception->getMessage(),
            );
        }
    }

    public function testConstructorNamesAnOptionalPlaceholderAsUnsupported(): void
    {
        try {
            new Route('GET', '/users/{id?}', 'HomeController@index');
            self::fail('An optional placeholder must be refused.');
        } catch (RouteSignatureException $exception) {
            self::assertSame(
                'Invalid route parameter name "id?" on route "/users/{id?}": optional placeholders are not supported; '
                . 'declare a second route without that segment',
                $exception->getMessage(),
            );
        }
    }

    public function testConstructorNamesAPlaceholderWithoutANameAsInvalid(): void
    {
        try {
            new Route('GET', '/{?}', 'HomeController@index');
            self::fail('A placeholder without a name must be refused.');
        } catch (RouteSignatureException $exception) {
            self::assertSame(
                'Invalid route parameter name "?" on route "/{?}": a placeholder must match '
                . Route::PARAMETER_NAME_PATTERN,
                $exception->getMessage(),
            );
        }
    }

    public function testConstructorNamesAnOptionalPlaceholderWithAnInvalidNameAsInvalid(): void
    {
        try {
            new Route('GET', '/{1?}', 'HomeController@index');
            self::fail('A placeholder whose name is not valid must be refused.');
        } catch (RouteSignatureException $exception) {
            self::assertSame(
                'Invalid route parameter name "1?" on route "/{1?}": a placeholder must match '
                . Route::PARAMETER_NAME_PATTERN,
                $exception->getMessage(),
            );
        }
    }

    public function testConstructorAcceptsPathsWithoutQueryOrFragmentMarks(): void
    {
        self::assertSame('/a', (new Route('GET', '/a', 'HomeController@index'))->path);
        self::assertSame('/users/{id}', (new Route('GET', '/users/{id}', 'HomeController@index'))->path);
    }
}
