<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Zephyrus\Routing\Exception\RouteSignatureException;
use Zephyrus\Routing\Exception\RouteUrlGenerationException;
use Zephyrus\Routing\Route;
use Zephyrus\Routing\RouteCollection;
use Zephyrus\Routing\RouteUrlGenerator;

final class RouteUrlGeneratorTest extends TestCase
{
    public function testGenerateBuildsPathFromNamedRouteAndParameters(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/users/{id}', 'UserController@show', name: 'users.show'));

        $generator = new RouteUrlGenerator($routes);

        $path = $generator->generate('users.show', ['id' => 42]);

        self::assertSame('/users/42', $path);
    }

    public function testGenerateEncodesParameterValues(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/tags/{name}', 'TagController@show', name: 'tags.show'));

        $generator = new RouteUrlGenerator($routes);

        $path = $generator->generate('tags.show', ['name' => 'hello world']);

        self::assertSame('/tags/hello%20world', $path);
    }

    public function testGenerateThrowsWhenRouteNameUnknown(): void
    {
        $generator = new RouteUrlGenerator(new RouteCollection());

        $this->expectException(RouteUrlGenerationException::class);
        $this->expectExceptionMessage('Unknown route name: users.show');

        $generator->generate('users.show');
    }

    public function testGenerateThrowsWhenParameterMissing(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/users/{id}', 'UserController@show', name: 'users.show'));

        $generator = new RouteUrlGenerator($routes);

        $this->expectException(RouteUrlGenerationException::class);
        $this->expectExceptionMessage('Missing route parameter "id" for route "users.show"');

        $generator->generate('users.show');
    }

    public function testGenerateAppendsSortedQueryStringWhenProvided(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/users/{id}', 'UserController@show', name: 'users.show'));

        $generator = new RouteUrlGenerator($routes);

        $path = $generator->generate('users.show', ['id' => 42], ['expand' => 'roles', 'page' => 2]);

        self::assertSame('/users/42?expand=roles&page=2', $path);
    }

    public function testGenerateEncodesArrayAndSpecialCharactersInQuery(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/search', 'SearchController@index', name: 'search.index'));

        $generator = new RouteUrlGenerator($routes);

        $path = $generator->generate('search.index', query: [
            'q' => 'hello world',
            'tags' => ['php', 'zephyrus 2'],
        ]);

        self::assertSame('/search?q=hello%20world&tags%5B0%5D=php&tags%5B1%5D=zephyrus%202', $path);
    }

    public function testGenerateSortsNestedAssociativeQueryKeysDeterministically(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/search', 'SearchController@index', name: 'search.index'));

        $generator = new RouteUrlGenerator($routes);

        $path = $generator->generate('search.index', query: [
            'filters' => [
                'sort' => 'desc',
                'term' => 'zephyrus',
            ],
            'page' => 2,
        ]);

        self::assertSame('/search?filters%5Bsort%5D=desc&filters%5Bterm%5D=zephyrus&page=2', $path);
    }

    public function testGenerateKeepsNestedListOrderWhileSortingAssociativeKeys(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/search', 'SearchController@index', name: 'search.index'));

        $generator = new RouteUrlGenerator($routes);

        $path = $generator->generate('search.index', query: [
            'filters' => [
                'tags' => ['zeta', 'alpha'],
                'sort' => 'desc',
            ],
        ]);

        self::assertSame('/search?filters%5Bsort%5D=desc&filters%5Btags%5D%5B0%5D=zeta&filters%5Btags%5D%5B1%5D=alpha', $path);
    }

    public function testGeneratePrependsBaseUrlWhenConfigured(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/users/{id}', 'UserController@show', name: 'users.show'));

        $generator = new RouteUrlGenerator($routes, 'https://example.com');

        $url = $generator->generate('users.show', ['id' => 42], ['expand' => 'roles']);

        self::assertSame('https://example.com/users/42?expand=roles', $url);
    }

    public function testGenerateNormalizesTrailingSlashFromBaseUrl(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/health', 'HealthController@show', name: 'health.show'));

        $generator = new RouteUrlGenerator($routes, 'https://example.com/');

        $url = $generator->generate('health.show');

        self::assertSame('https://example.com/health', $url);
    }

    public function testGenerateAppendsEncodedFragmentWhenProvided(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/docs/{slug}', 'DocsController@show', name: 'docs.show'));

        $generator = new RouteUrlGenerator($routes, 'https://example.com');

        $url = $generator->generate('docs.show', ['slug' => 'routing'], fragment: 'section 1');

        self::assertSame('https://example.com/docs/routing#section%201', $url);
    }

    public function testGenerateNormalizesFragmentPrefixHash(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/docs/{slug}', 'DocsController@show', name: 'docs.show'));

        $generator = new RouteUrlGenerator($routes);

        $url = $generator->generate('docs.show', ['slug' => 'routing'], fragment: '#intro');

        self::assertSame('/docs/routing#intro', $url);
    }

    public function testGenerateSignedUsesInjectedSignature(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/users/{id}', 'UserController@show', name: 'users.show'));

        $signature = new \Zephyrus\Routing\RouteSignature('secret-key');
        $generator = new RouteUrlGenerator($routes, 'https://example.com', $signature);

        $signedUrl = $generator->generateSigned('users.show', ['id' => 42], ['expand' => 'roles']);

        self::assertTrue($signature->verify($signedUrl));
    }

    public function testGenerateSignedThrowsWhenSignerIsMissing(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/users/{id}', 'UserController@show', name: 'users.show'));

        $generator = new RouteUrlGenerator($routes);

        $this->expectException(RouteUrlGenerationException::class);
        $this->expectExceptionMessage('Cannot generate signed URL without a RouteSignature instance');

        $generator->generateSigned('users.show', ['id' => 42]);
    }

    public function testGenerateSignedPreservesFragmentInSignedUrl(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/users/{id}', 'UserController@show', name: 'users.show'));

        $signature = new \Zephyrus\Routing\RouteSignature('secret-key');
        $generator = new RouteUrlGenerator($routes, 'https://example.com', $signature);

        $signedUrl = $generator->generateSigned('users.show', ['id' => 42], fragment: 'details');

        self::assertStringContainsString('#details', $signedUrl);
        self::assertTrue($signature->verify($signedUrl));
    }

    public function testGenerateThrowsWhenUnexpectedRouteParameterProvided(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/users/{id}', 'UserController@show', name: 'users.show'));

        $generator = new RouteUrlGenerator($routes);

        $this->expectException(RouteUrlGenerationException::class);
        $this->expectExceptionMessage('Unexpected route parameter "slug" for route "users.show"');

        $generator->generate('users.show', ['id' => 42, 'slug' => 'alice']);
    }

    public function testGenerateThrowsWhenParameterDoesNotSatisfyConstraint(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/users/{id}', 'UserController@show', ['id' => '\\d+'], name: 'users.show'));

        $generator = new RouteUrlGenerator($routes);

        $this->expectException(RouteUrlGenerationException::class);
        $this->expectExceptionMessage('Route parameter "id" value "abc" does not satisfy constraint "\\d+" for route "users.show"');

        $generator->generate('users.show', ['id' => 'abc']);
    }

    public function testGenerateThrowsWhenConstraintPatternIsInvalid(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/users/{id}', 'UserController@show', ['id' => '[0-9+'], name: 'users.show'));

        $generator = new RouteUrlGenerator($routes);

        $this->expectException(RouteUrlGenerationException::class);
        $this->expectExceptionMessage('Invalid constraint pattern "[0-9+" for parameter "id" on route "users.show"');

        $generator->generate('users.show', ['id' => '42']);
    }

    public function testGenerateTemporarySignedBuildsVerifiableExpiringUrl(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/downloads/{id}', 'DownloadController@show', ['id' => '\\d+'], name: 'downloads.show'));

        $signature = new \Zephyrus\Routing\RouteSignature('secret-key');
        $generator = new RouteUrlGenerator($routes, 'https://example.com', $signature);

        $signedUrl = $generator->generateTemporarySigned(
            routeName: 'downloads.show',
            ttlSeconds: 120,
            parameters: ['id' => 42],
            query: ['disposition' => 'inline'],
            now: 1_700_000_000,
        );

        self::assertStringContainsString('_exp=1700000120', $signedUrl);
        self::assertTrue($signature->verifyAt($signedUrl, now: 1_700_000_060));
        self::assertFalse($signature->verifyAt($signedUrl, now: 1_700_000_121));
    }

    public function testGenerateTemporarySignedThrowsWhenSignerIsMissing(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/downloads/{id}', 'DownloadController@show', name: 'downloads.show'));

        $generator = new RouteUrlGenerator($routes);

        $this->expectException(RouteUrlGenerationException::class);
        $this->expectExceptionMessage('Cannot generate signed URL without a RouteSignature instance');

        $generator->generateTemporarySigned('downloads.show', 60, ['id' => 42]);
    }

    public function testGenerateTemporarySignedThrowsWhenTtlIsNotPositive(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/downloads/{id}', 'DownloadController@show', name: 'downloads.show'));

        $signature = new \Zephyrus\Routing\RouteSignature('secret-key');
        $generator = new RouteUrlGenerator($routes, signature: $signature);

        $this->expectException(RouteUrlGenerationException::class);
        $this->expectExceptionMessage('Temporary signed URL TTL must be greater than zero seconds');

        $generator->generateTemporarySigned('downloads.show', 0, ['id' => 42]);
    }

    public function testGenerateTemporarySignedPreservesFragment(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/downloads/{id}', 'DownloadController@show', name: 'downloads.show'));

        $signature = new \Zephyrus\Routing\RouteSignature('secret-key');
        $generator = new RouteUrlGenerator($routes, 'https://example.com', $signature);

        $signedUrl = $generator->generateTemporarySigned(
            routeName: 'downloads.show',
            ttlSeconds: 60,
            parameters: ['id' => 42],
            fragment: 'modal',
            now: 1_700_000_000,
        );

        self::assertStringContainsString('#modal', $signedUrl);
        self::assertTrue($signature->verifyAt($signedUrl, now: 1_700_000_020));
    }

    public function testGenerateTemporarySignedUntilBuildsVerifiableUrlAtAbsoluteExpiry(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/downloads/{id}', 'DownloadController@show', name: 'downloads.show'));

        $signature = new \Zephyrus\Routing\RouteSignature('secret-key');
        $generator = new RouteUrlGenerator($routes, 'https://example.com', $signature);

        $signedUrl = $generator->generateTemporarySignedUntil(
            routeName: 'downloads.show',
            expiresAt: 1_700_000_120,
            parameters: ['id' => 42],
            query: ['disposition' => 'inline'],
            fragment: 'details',
            now: 1_700_000_000,
        );

        self::assertStringContainsString('_exp=1700000120', $signedUrl);
        self::assertStringContainsString('#details', $signedUrl);
        self::assertTrue($signature->verifyAt($signedUrl, now: 1_700_000_119));
    }

    public function testGenerateTemporarySignedUntilThrowsWhenExpiryIsNotInFuture(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/downloads/{id}', 'DownloadController@show', name: 'downloads.show'));

        $signature = new \Zephyrus\Routing\RouteSignature('secret-key');
        $generator = new RouteUrlGenerator($routes, 'https://example.com', $signature);

        $this->expectException(RouteSignatureException::class);
        $this->expectExceptionMessage('Temporary signature expiry instant must be in the future');

        $generator->generateTemporarySignedUntil(
            routeName: 'downloads.show',
            expiresAt: 1_700_000_000,
            parameters: ['id' => 42],
            now: 1_700_000_000,
        );
    }

    public function testGenerateTemporarySignedUntilThrowsWhenSignerIsMissing(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/downloads/{id}', 'DownloadController@show', name: 'downloads.show'));

        $generator = new RouteUrlGenerator($routes);

        $this->expectException(RouteUrlGenerationException::class);
        $this->expectExceptionMessage('Cannot generate signed URL without a RouteSignature instance');

        $generator->generateTemporarySignedUntil(
            routeName: 'downloads.show',
            expiresAt: 1_700_000_120,
            parameters: ['id' => 42],
        );
    }

    public function testGenerateIgnoresEmptyFragmentAfterHashNormalization(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/docs/{slug}', 'DocsController@show', name: 'docs.show'));

        $generator = new RouteUrlGenerator($routes);

        $url = $generator->generate('docs.show', ['slug' => 'routing'], fragment: '#');

        self::assertSame('/docs/routing', $url);
    }
}
