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

    public function testTheCachePathResolvesAgainstTheApplicationRoot(): void
    {
        $config = RouterConfig::fromArray([
            'controllers' => ['App\Controllers' => 'app/Controllers'],
            'cache' => 'var/./build/../routes.php',
        ]);

        self::assertSame('/srv/app/var/routes.php', $config->cachePath('/srv/app'));
    }

    /**
     * @param array<string, mixed> $values
     */
    #[DataProvider('refusedSections')]
    public function testAnInvalidSectionIsRefused(array $values, string $message): void
    {
        try {
            RouterConfig::fromArray($values);
            self::fail('expected a refusal');
        } catch (ConfigurationException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedSections(): iterable
    {
        $controllers = ['App\Controllers' => 'app/Controllers'];

        yield 'no controllers' => [
            [],
            "Configuration section 'router' requires field 'controllers' but none was provided.",
        ];
        yield 'empty controllers' => [
            ['controllers' => []],
            "Configuration section 'router' field 'controllers' must map each PSR-4 namespace to its directory, such "
            . 'as App\Controllers: app/Controllers.',
        ];
        yield 'a key that is not a namespace' => [
            ['controllers' => ['App Controllers' => 'app/Controllers']],
            "Configuration section 'router' field 'controllers' has invalid value \"App Controllers\": each key must "
            . 'be a PSR-4 namespace, such as App\Controllers.',
        ];
        yield 'a directory that is not a string' => [
            ['controllers' => ['App\Controllers' => ['app/Controllers']]],
            "Configuration section 'router' field \"controllers.App\\\\Controllers\" must be the directory of that "
            . 'namespace.',
        ];
        yield 'a middleware group that is not a list' => [
            ['controllers' => $controllers, 'middleware_groups' => ['web' => 'session']],
            "Configuration section 'router' field 'middleware_groups.web' must be a list of middleware names.",
        ];
        yield 'an empty cache path' => [
            ['controllers' => $controllers, 'cache' => ''],
            "Configuration section 'router' field 'cache' must be the path of the routes cache file.",
        ];
        yield 'an unknown key' => [
            ['controllers' => $controllers, 'routes' => []],
            "Configuration section 'router' field 'routes' is an unknown key: the accepted keys are controllers, "
            . 'middleware_groups, cache.',
        ];
        yield 'a tagged value' => [
            ['controllers' => ['App\Controllers' => new TaggedValue('env', 'CONTROLLERS_DIRECTORY')]],
            "Configuration section 'router' field \"controllers.App\\\\Controllers\" is written under the !env tag, but "
            . 'vendor/bin/zephyrus cache:warm reads this section without the environment: write the value itself.',
        ];
    }

    public function testACachePathLeavingTheApplicationRootIsRefused(): void
    {
        $config = RouterConfig::fromArray([
            'controllers' => ['App\Controllers' => 'app/Controllers'],
            'cache' => 'var/../../routes.php',
        ]);

        try {
            $config->cachePath('/srv/app');
            self::fail('expected a refusal');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'router' field 'cache' has invalid value \"var/../../routes.php\": it resolves "
                . 'outside the application root /srv/app.',
                $exception->getMessage(),
            );
        }
    }

    public function testAMissingControllerDirectoryIsRefused(): void
    {
        $config = RouterConfig::fromArray(['controllers' => ['App\Controllers' => 'app/Controllers']]);

        try {
            $config->controllerDirectories('/srv/missing-application');
            self::fail('expected a refusal');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'router' field \"controllers.App\\\\Controllers\" has invalid value "
                . '"app/Controllers": /srv/missing-application/app/Controllers is not a directory.',
                $exception->getMessage(),
            );
        }
    }
}
