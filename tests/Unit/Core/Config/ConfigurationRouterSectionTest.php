<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigSection;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Tests\Unit\Core\Cache\CacheDirectory;

final class ConfigurationRouterSectionTest extends TestCase
{
    public function testTheRouterSectionIsBuiltIn(): void
    {
        $configuration = Configuration::fromArray([
            'router' => ['controllers' => ['App\Controllers' => 'app/Controllers']],
        ]);

        self::assertSame(
            [
                'controllers' => ['App\Controllers' => 'app/Controllers'],
                'middleware_groups' => [],
                'cache' => 'var/zephyrus/routes.php',
            ],
            $configuration->toArray()['router'],
        );
    }

    public function testWithoutRouterSectionTheRouterIsNull(): void
    {
        self::assertNull(Configuration::fromArray([])->router);
    }

    public function testACustomRoutingSectionIsStillAccepted(): void
    {
        $configuration = Configuration::fromArray(
            ['routing' => ['prefix' => '/api']],
            ['routing' => RoutingSection::class],
        );

        self::assertSame('/api', $configuration->section('routing')->get('prefix'));
    }

    public function testARoutesSectionIsRefused(): void
    {
        try {
            Configuration::fromArray(['routes' => ['controllers' => []]]);
            self::fail('expected a refusal');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration key 'routes' is not a known section: did you mean \"router\"? A section of your own "
                . 'needs a factory registered under its name in the $sectionFactories argument of the Configuration '
                . 'factories.',
                $exception->getMessage(),
            );
        }
    }

    public function testAnEnvTagUnderTheRouterSectionIsRefusedBeforeItIsResolved(): void
    {
        $directory = new CacheDirectory();
        $file = $directory->file(
            'config.yml',
            "router:\n  controllers:\n    App\\Controllers: !env CONTROLLERS_DIRECTORY\n",
        );

        try {
            Configuration::fromYamlFile($file);
            self::fail('expected a refusal');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'router' field 'controllers' holds a value under the !env tag, but "
                . 'vendor/bin/zephyrus cache:warm reads this section without the environment: write the value itself.',
                $exception->getMessage(),
            );
        } finally {
            $directory->remove();
        }
    }
}

final class RoutingSection extends ConfigSection
{
    public static function fromArray(array $values): static
    {
        return new static($values);
    }
}
