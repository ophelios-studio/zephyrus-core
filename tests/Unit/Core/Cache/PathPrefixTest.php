<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Cache;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Cache\PathPrefix;

final class PathPrefixTest extends TestCase
{
    #[DataProvider('candidates')]
    public function testOnlyAPathStrictlyBelowTheBaseIsUnderIt(string $path, string $base, string $separator, bool $under): void
    {
        self::assertSame($under, PathPrefix::isUnder($path, $base, $separator));
    }

    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function candidates(): iterable
    {
        yield 'a file below the base' => ['/srv/app/var/routes.php', '/srv/app', '/', true];
        yield 'the base itself' => ['/srv/app', '/srv/app', '/', false];
        yield 'the base followed by its separator' => ['/srv/app/', '/srv/app', '/', false];
        yield 'a sibling whose name extends the base' => ['/srv/application/routes.php', '/srv/app', '/', false];
        yield 'a parent of the base' => ['/srv', '/srv/app', '/', false];
        yield 'a base written with a trailing separator' => ['/srv/app/var', '/srv/app/', '/', true];
        yield 'the root as the base' => ['/var', '/', '/', true];
        yield 'a namespace below the base' => ['App\Controllers\HomeController@index', 'App\Controllers', '\\', true];
        yield 'a sibling namespace whose name extends the base' => ['App\ControllersX\Home@index', 'App\Controllers', '\\', false];
    }

    #[DataProvider('paths')]
    public function testDotSegmentsAreResolvedWithoutReadingTheFilesystem(string $path, string $resolved): void
    {
        self::assertSame($resolved, PathPrefix::withoutDotSegments($path));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function paths(): iterable
    {
        yield 'dot and dot-dot segments' => ['/srv/app/var/./build/../routes.php', '/srv/app/var/routes.php'];
        yield 'empty segments and a trailing slash' => ['/srv//app/', '/srv/app'];
        yield 'more dot-dot segments than parents' => ['/srv/../../etc/passwd', '/etc/passwd'];
        yield 'the root' => ['/', '/'];
    }
}
