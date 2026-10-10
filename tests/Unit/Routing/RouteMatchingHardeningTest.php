<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Exception\RouteNotFoundException;
use Zephyrus\Routing\Exception\RouteParameterException;
use Zephyrus\Routing\Exception\RouteSignatureException;
use Zephyrus\Routing\HandlerResolver;
use Zephyrus\Routing\Route;
use Zephyrus\Routing\RouteCache;
use Zephyrus\Routing\RouteCollection;
use Zephyrus\Routing\RouteMatch;

/**
 * Guards of the route matcher: constraints, malformed segments, placeholder
 * names, integer overflow and the cache file mode.
 */
final class RouteMatchingHardeningTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function trailingNewlineProvider(): array
    {
        return [
            'digits' => ['\d+', '/s/123%0A'],
            'word' => ['[a-z]+', '/s/abc%0A'],
            'alternation' => ['draft|live', '/s/live%0A'],
            'anchored by the author' => ['^v[0-9]$', '/s/v1%0A'],
        ];
    }

    /**
     * Without the D modifier, "$" also matches before a final newline.
     */
    #[DataProvider('trailingNewlineProvider')]
    public function testAConstraintDoesNotAcceptATrailingNewline(string $pattern, string $path): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/s/{value}', 'C@show', ['value' => $pattern]));

        $this->expectException(RouteNotFoundException::class);
        $collection->match('GET', $path);
    }

    public function testTheSameConstraintStillMatchesTheCleanValue(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/s/{value}', 'C@show', ['value' => '\d+']));

        self::assertSame('123', $collection->match('GET', '/s/123')->parameter('value'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedSegmentProvider(): array
    {
        return [
            'lone continuation byte' => ['/a/%FF'],
            'truncated sequence' => ['/a/%C3%28'],
            'utf-16 surrogate' => ['/a/%ED%A0%80'],
            'nul byte' => ['/a/%00'],
            'nul inside a value' => ['/a/ab%00cd'],
        ];
    }

    /**
     * Invalid UTF-8 and NUL must never reach a handler argument: binding invalid
     * UTF-8 through pdo_pgsql with emulated prepares crashes the worker.
     */
    #[DataProvider('malformedSegmentProvider')]
    public function testAMalformedSegmentNeverReachesAHandlerArgument(string $path): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/a/{value}', 'C@show'));

        $this->expectException(RouteNotFoundException::class);
        $collection->match('GET', $path);
    }

    #[DataProvider('malformedSegmentProvider')]
    public function testAMalformedSegmentIsNotReportedAsAMethodMismatchEither(string $path): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('POST', '/a/{value}', 'C@store'));

        self::assertSame([], $collection->allowedMethodsForPath($path));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rawMalformedTargetProvider(): array
    {
        return [
            'raw lone continuation byte' => ["/a/ab\xFFcd"],
            'raw truncated sequence' => ["/a/\xC3\x28"],
        ];
    }

    /**
     * Raw (not percent-encoded) malformed bytes are refused before any route is consulted.
     */
    #[DataProvider('rawMalformedTargetProvider')]
    public function testARawMalformedTargetIsRefusedBeforeMatching(string $path): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/a/{value}', 'C@show'));

        $this->expectException(RouteNotFoundException::class);
        $collection->match('GET', $path);
    }

    public function testARawNulByteCannotReachAHandlerArgument(): void
    {
        // parse_url() replaces a raw NUL with "_" before the guard runs; only the outcome is asserted.
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/a/{value}', 'C@show'));

        $value = $collection->match('GET', "/a/ab\0cd")->parameter('value');

        self::assertIsString($value);
        self::assertStringNotContainsString("\0", $value);
    }

    public function testValidMultibyteSegmentsStillMatch(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/a/{value}', 'C@show'));

        self::assertSame('café', $collection->match('GET', '/a/caf%C3%A9')->parameter('value'));
        self::assertSame('café', $collection->match('GET', '/a/café')->parameter('value'));
    }

    public function testSafeSlugConstraintIsAvailableAndNarrow(): void
    {
        $collection = new RouteCollection();
        $collection->add(Route::define('GET', '/docs/{slug}', 'C@show', ['slug' => Route::SAFE_SLUG]));

        self::assertSame('a-b_9', $collection->match('GET', '/docs/a-b_9')->parameter('slug'));

        $this->expectException(RouteNotFoundException::class);
        $collection->match('GET', '/docs/a%2Fb');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidPlaceholderProvider(): array
    {
        return [
            'space' => ['/x/{a b}'],
            'numeric' => ['/x/{0}'],
            'dotted framework key' => ['/x/{_zephyrus.unmatched_route}'],
            'framework prefix' => ['/x/{_zephyrus_role}'],
            'reserved client ip' => ['/x/{client_ip}'],
            'nested braces' => ['/x/{a}{b}'],
            'duplicate' => ['/x/{id}/y/{id}'],
        ];
    }

    /**
     * Refused at registration. A numeric name such as "{0}" would be renumbered by array_merge() and lost.
     */
    #[DataProvider('invalidPlaceholderProvider')]
    public function testAnInvalidPlaceholderNameIsRefusedAtRegistration(string $path): void
    {
        $this->expectException(RouteSignatureException::class);
        Route::define('GET', $path, 'C@show');
    }

    public function testOrdinaryPlaceholderNamesStillRegister(): void
    {
        $route = Route::define('GET', '/users/{id}/posts/{postId}', 'C@show');

        self::assertSame('/users/{id}/posts/{postId}', $route->path);
        self::assertSame('/x/{_draft}', Route::define('GET', '/x/{_draft}', 'C@show')->path);
        // A brace inside a larger segment is a literal.
        self::assertSame('/x/a{b}c', Route::define('GET', '/x/a{b}c', 'C@show')->path);
    }

    public function testAPoisonedRouteCacheFailsAsARouteCacheError(): void
    {
        $file = sys_get_temp_dir() . '/zephyrus-poisoned-cache-' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($file, json_encode([
            'routes' => [
                ['method' => 'GET', 'path' => '/x/{client_ip}', 'handler' => 'C@show'],
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $this->expectException(\Zephyrus\Routing\Exception\RouteCacheException::class);
            (new RouteCache($file))->load();
        } finally {
            @unlink($file);
        }
    }

    public function testTheCacheDirectoryAndFileAreNotWorldWritable(): void
    {
        // The cache drives Class@method dispatch, so it must not be writable by others.
        // The umask is set to 0 so that the modes are proven, not inherited.
        $previousUmask = umask(0);
        $directory = sys_get_temp_dir() . '/zephyrus-cache-perm-' . bin2hex(random_bytes(8));
        $file = $directory . '/routes.json';

        try {
            $routes = new RouteCollection();
            $routes->add(Route::define('GET', '/users', 'UserController@index'));

            (new RouteCache($file))->save($routes);

            self::assertSame('0755', substr(sprintf('%o', fileperms($directory)), -4));
            self::assertSame('0644', substr(sprintf('%o', fileperms($file)), -4));
        } finally {
            @unlink($file);
            @rmdir($directory);
            umask($previousUmask);
        }
    }

    public function testNoTemporaryFileSurvivesASuccessfulSave(): void
    {
        $directory = sys_get_temp_dir() . '/zephyrus-cache-tmp-' . bin2hex(random_bytes(8));
        $file = $directory . '/routes.json';

        try {
            $routes = new RouteCollection();
            $routes->add(Route::define('GET', '/users', 'UserController@index'));

            $cache = new RouteCache($file);
            $cache->save($routes);
            $cache->save($routes);

            self::assertSame([], glob($directory . '/*.tmp'));
            self::assertCount(1, $cache->load()->all());
        } finally {
            foreach (glob($directory . '/*') ?: [] as $leftover) {
                @unlink($leftover);
            }
            @rmdir($directory);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unrepresentableIntegerProvider(): array
    {
        return [
            'far past the max' => ['9999999999999999999999'],
            'one past the max' => ['9223372036854775808'],
            'one past the min' => ['-9223372036854775809'],
        ];
    }

    /**
     * Saturating would map distinct URLs onto one argument, so unrepresentable integers throw.
     */
    #[DataProvider('unrepresentableIntegerProvider')]
    public function testAnUnrepresentableIntegerIsRefusedInsteadOfSaturating(string $value): void
    {
        $this->expectException(RouteParameterException::class);
        $this->resolveInt($value);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function representableIntegerProvider(): array
    {
        return [
            'plain' => ['42', 42],
            'zero' => ['0', 0],
            'negative' => ['-7', -7],
            'leading zeros' => ['007', 7],
            'negative zero' => ['-0', 0],
            'the max' => ['9223372036854775807', PHP_INT_MAX],
            'the min' => ['-9223372036854775808', PHP_INT_MIN],
        ];
    }

    #[DataProvider('representableIntegerProvider')]
    public function testRepresentableIntegersAreUnchanged(string $value, int $expected): void
    {
        self::assertSame($expected, $this->resolveInt($value));
    }

    private function resolveInt(string $value): int
    {
        $route = Route::define('GET', '/n/{id}', IntEchoController::class . '@show');
        $match = new RouteMatch($route, ['id' => $value]);
        $request = Request::fromArray('GET', '/n/' . $value, attributes: ['id' => $value]);

        $response = (new HandlerResolver())->resolve($match, $request);

        return (int) $response->body;
    }
}

final class IntEchoController
{
    public function show(int $id): Response
    {
        return Response::text((string) $id);
    }
}
