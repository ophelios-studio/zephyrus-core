<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Core\Config\RouterConfig;
use Zephyrus\Tests\Unit\Core\Cache\CacheDirectory;

final class RouterConfigTest extends TestCase
{
    public function testOnlyTheControllersAreRequired(): void
    {
        $config = RouterConfig::fromArray(['controllers' => ['App\Controllers\\' => 'app/Controllers']]);

        self::assertSame(
            [
                'controllers' => ['App\Controllers' => 'app/Controllers'],
                'middleware_groups' => [],
                'cache' => 'var/zephyrus/routes.php',
            ],
            $config->toArray(),
        );
    }

    public function testControllerDirectoriesResolveAgainstTheApplicationRootInTheirDeclaredOrder(): void
    {
        $root = new CacheDirectory();
        $absolute = $root->directory('shared/Controllers');
        $root->directory('app/Controllers');
        $config = RouterConfig::fromArray(['controllers' => [
            'App\Controllers' => 'app/Controllers',
            'Shared\Controllers' => $absolute,
        ]]);

        $directories = $config->controllerDirectories($root->path);
        $root->remove();

        self::assertSame(
            ['App\Controllers' => $root->path . '/app/Controllers', 'Shared\Controllers' => $absolute],
            $directories,
        );
    }

    public function testEveryMiddlewareGroupIsKeptInItsDeclaredOrder(): void
    {
        $config = RouterConfig::fromArray([
            'controllers' => ['App\Controllers' => 'app/Controllers'],
            'middleware_groups' => ['web' => ['session', 'csrf'], 'api' => ['auth']],
        ]);

        self::assertSame(['web' => ['session', 'csrf'], 'api' => ['auth']], $config->middlewareGroups);
    }

    public function testAnEmptySectionAsksForTheControllers(): void
    {
        try {
            RouterConfig::fromArray(null);
            self::fail('expected a refusal');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'router' requires field 'controllers' but none was provided.",
                $exception->getMessage(),
            );
        }
    }

    public function testTheCachePathResolvesAgainstTheApplicationRoot(): void
    {
        $config = RouterConfig::fromArray([
            'controllers' => ['App\Controllers' => 'app/Controllers'],
            'cache' => 'var/./build/../routes.php',
        ]);

        self::assertSame('/srv/app/var/routes.php', $config->cachePath('/srv/app'));
    }

    #[DataProvider('refusedSections')]
    public function testAnInvalidSectionIsRefused(mixed $values, string $message): void
    {
        try {
            RouterConfig::fromArray($values);
            self::fail('expected a refusal');
        } catch (ConfigurationException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function refusedSections(): iterable
    {
        $controllers = ['App\Controllers' => 'app/Controllers'];
        $mapping = "Configuration section 'router' field 'controllers' must map each PSR-4 namespace to its directory, "
            . 'such as App\Controllers: app/Controllers.';
        $middlewareNames = "Configuration section 'router' field 'middleware_groups.web' must be a list of middleware "
            . 'names.';
        $groupNames = "Configuration section 'router' field 'middleware_groups' must map each group name to a list of "
            . 'middleware names.';
        $cachePath = "Configuration section 'router' field 'cache' must be the path of the routes cache file.";
        $nestedTag = 'holds a value under the !env tag, but vendor/bin/zephyrus cache:warm reads this section without '
            . 'the environment: write the value itself.';

        yield 'a section that is not a map' => [
            'app/Controllers',
            "Configuration section 'router' must map controllers, middleware_groups and cache to their values.",
        ];
        yield 'a section written under a tag' => [
            new TaggedValue('env', 'ROUTER_SECTION'),
            "Configuration section 'router' is written under the !env tag, but vendor/bin/zephyrus cache:warm reads "
            . 'this section without the environment: write its values in the file.',
        ];

        yield 'no controllers' => [
            [],
            "Configuration section 'router' requires field 'controllers' but none was provided.",
        ];
        yield 'empty controllers' => [['controllers' => []], $mapping];
        yield 'controllers written as a list' => [['controllers' => ['app/Controllers']], $mapping];
        yield 'a key that is not a namespace' => [
            ['controllers' => ['App Controllers' => 'app/Controllers']],
            "Configuration section 'router' field 'controllers' has invalid value \"App Controllers\": each key must "
            . 'be a PSR-4 namespace, such as App\Controllers.',
        ];
        yield 'a directory that is not a string' => [
            ['controllers' => ['App\Controllers' => ['app/Controllers']]],
            "Configuration section 'router' field 'controllers' must map App\Controllers to the directory of that "
            . 'namespace.',
        ];
        yield 'a directory of blanks' => [
            ['controllers' => ['App\Controllers' => '  ']],
            "Configuration section 'router' field 'controllers' must map App\Controllers to the directory of that "
            . 'namespace.',
        ];
        yield 'middleware groups that are not a map' => [
            ['controllers' => $controllers, 'middleware_groups' => 'session'],
            $groupNames,
        ];
        yield 'middleware groups written as a list' => [
            ['controllers' => $controllers, 'middleware_groups' => [['session']]],
            $groupNames,
        ];
        yield 'a middleware group that is not a list' => [
            ['controllers' => $controllers, 'middleware_groups' => ['web' => 'session']],
            $middlewareNames,
        ];
        yield 'a middleware name that is not a string' => [
            ['controllers' => $controllers, 'middleware_groups' => ['web' => [1]]],
            $middlewareNames,
        ];
        yield 'an empty middleware name' => [
            ['controllers' => $controllers, 'middleware_groups' => ['web' => ['session', '']]],
            $middlewareNames,
        ];
        yield 'an empty cache path' => [['controllers' => $controllers, 'cache' => ''], $cachePath];
        yield 'a cache path of blanks' => [['controllers' => $controllers, 'cache' => '  '], $cachePath];
        yield 'a cache path holding a NUL byte' => [
            ['controllers' => $controllers, 'cache' => "var/zephyrus\0/routes.php"],
            "Configuration section 'router' field 'cache' must not contain a NUL byte.",
        ];
        yield 'a cache that is not a PHP file' => [
            ['controllers' => $controllers, 'cache' => 'config.yml'],
            "Configuration section 'router' field 'cache' has invalid value \"config.yml\": the routes cache is PHP, so "
            . 'its file name must end in .php.',
        ];
        yield 'an unknown key' => [
            ['controllers' => $controllers, 'routes' => []],
            "Configuration section 'router' field 'routes' is an unknown key: the accepted keys are controllers, "
            . 'middleware_groups, cache.',
        ];
        yield 'a tagged value' => [
            ['controllers' => ['App\Controllers' => new TaggedValue('env', 'CONTROLLERS_DIRECTORY')]],
            "Configuration section 'router' field 'controllers' " . $nestedTag,
        ];
        yield 'a tagged value deep in a list' => [
            ['controllers' => $controllers, 'middleware_groups' => ['web' => ['session', new TaggedValue('env', 'MFA')]]],
            "Configuration section 'router' field 'middleware_groups' " . $nestedTag,
        ];
        yield 'a tagged value under a numeric key' => [
            ['controllers' => $controllers, 7 => new TaggedValue('env', 'EXTRA')],
            "Configuration section 'router' field '7' " . $nestedTag,
        ];
    }

    #[DataProvider('cachePathsOutsideTheRoot')]
    public function testACachePathLeavingTheApplicationRootIsRefused(string $cache, string $shown): void
    {
        $config = RouterConfig::fromArray(['controllers' => ['App\Controllers' => 'app/Controllers'], 'cache' => $cache]);

        try {
            $config->cachePath('/srv/app');
            self::fail('expected a refusal');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'router' field 'cache' has invalid value " . $shown . ': it resolves outside the '
                . 'application root /srv/app.',
                $exception->getMessage(),
            );
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cachePathsOutsideTheRoot(): iterable
    {
        yield 'dot-dot segments' => ['var/../../routes.php', '"var/../../routes.php"'];
        yield 'a sibling whose name extends the root' => ['/srv/application/routes.php', '"/srv/application/routes.php"'];
    }

    public function testAMissingControllerDirectoryIsRefused(): void
    {
        $config = RouterConfig::fromArray(['controllers' => ['App\Controllers' => 'app/Controllers']]);

        try {
            $config->controllerDirectories('/srv/missing-application');
            self::fail('expected a refusal');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'router' field 'controllers' has invalid value \"app/Controllers\": "
                . '/srv/missing-application/app/Controllers, the directory of App\Controllers, is not a directory.',
                $exception->getMessage(),
            );
        }
    }
}
