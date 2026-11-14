<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Routing\Route;
use Zephyrus\Routing\RouteCache;
use Zephyrus\Routing\Router;
use Zephyrus\Tests\Support\CapturedErrorLog;
use Zephyrus\Tests\Unit\Core\Cache\CacheDirectory;

final class RouterFromConfigurationTest extends TestCase
{
    use CapturedErrorLog;

    private ControllerTree $tree;

    protected function setUp(): void
    {
        $this->tree = new ControllerTree();
        $this->tree->configure();
        $this->tree->controller('HomeController', <<<'PHP'
            final class HomeController
            {
                #[Get('/', name: 'home')]
                public function index(): void
                {
                }

                #[Get('/about')]
                public function about(): void
                {
                }
            }
            PHP);
        $this->captureErrorLog();
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
        $this->releaseErrorLog();
    }

    public function testProductionServesTheCachedRoutes(): void
    {
        $cache = $this->cache();
        $cache->warm();
        file_put_contents(
            $cache->path(),
            str_replace("'/about'", "'/from-cache'", (string) file_get_contents($cache->path())),
        );

        $paths = $this->paths('production');

        self::assertSame(['/', '/from-cache'], $paths);
        self::assertSame('', $this->capturedErrorLog());
    }

    public function testProductionReadsTheCacheThroughAnApplicationRootThatIsASymbolicLink(): void
    {
        $cache = $this->cache();
        $cache->warm();
        file_put_contents(
            $cache->path(),
            str_replace("'/about'", "'/from-cache'", (string) file_get_contents($cache->path())),
        );
        $deployment = new CacheDirectory();
        symlink($this->tree->root->path, $deployment->path . '/current');

        try {
            $router = Router::fromConfiguration($this->configuration('production'), $deployment->path . '/current');
        } finally {
            $deployment->remove();
        }

        $paths = array_map(static fn (Route $route): string => $route->path, array_values($router->routes()->all()));
        self::assertSame(['/', '/from-cache'], $paths);
        self::assertSame('', $this->capturedErrorLog());
    }

    #[DataProvider('environmentsWithoutCache')]
    public function testDevelopmentAndTestingNeverOpenTheCache(string $environment): void
    {
        $cache = $this->cache();
        $cache->warm();
        $marker = $this->tree->root->path . '/included';
        file_put_contents($cache->path(), sprintf("<?php touch(%s); return [];\n", var_export($marker, true)));

        $paths = $this->paths($environment);

        self::assertSame(['/', '/about'], $paths);
        self::assertFileDoesNotExist($marker);
        self::assertSame('', $this->capturedErrorLog());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function environmentsWithoutCache(): iterable
    {
        yield 'development' => ['development'];
        yield 'testing' => ['testing'];
    }

    public function testProductionWithoutACacheDiscoversTheRoutesAndLogsOneLine(): void
    {
        $paths = $this->paths('production');

        $log = $this->capturedErrorLog();
        self::assertSame(['/', '/about'], $paths);
        self::assertSame(1, substr_count($log, "\n"), $log);
        self::assertStringEndsWith(
            sprintf(
                "Zephyrus: no routes cache at %s, so the controllers were discovered for this request. Run "
                . "vendor/bin/zephyrus cache:warm to write it.\n",
                $this->cache()->path(),
            ),
            $log,
        );
    }

    public function testProductionWithoutAConfigYmlAtTheRootDiscoversTheRoutesAndSaysWhyNoCacheIsRead(): void
    {
        $this->cache()->warm();
        unlink($this->tree->root->path . '/config.yml');

        $paths = $this->paths('production');

        $log = $this->capturedErrorLog();
        self::assertSame(['/', '/about'], $paths);
        self::assertSame(1, substr_count($log, "\n"), $log);
        self::assertStringEndsWith(
            sprintf(
                'Zephyrus: the controllers were discovered for this request: router: is cached only from config.yml '
                . "at the application root, and %s/config.yml does not exist.\n",
                $this->tree->root->path,
            ),
            $log,
        );
    }

    public function testAConfigurationWithoutRouterSectionIsRefused(): void
    {
        try {
            Router::fromConfiguration(Configuration::fromArray([]), $this->tree->root->path);
            self::fail('expected a refusal');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'router' requires field 'controllers' but none was provided.",
                $exception->getMessage(),
            );
        }
    }

    public function testARelativeApplicationRootIsRefused(): void
    {
        try {
            Router::fromConfiguration($this->configuration('development'), 'app');
            self::fail('expected a refusal');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(
                'Router::fromConfiguration() needs the absolute path of the application root as basePath, not "app".',
                $exception->getMessage(),
            );
        }
    }

    private function configuration(string $environment): Configuration
    {
        return Configuration::fromArray([
            'application' => ['environment' => $environment],
            'router' => ['controllers' => [$this->tree->namespace => 'app/Controllers']],
        ]);
    }

    private function cache(): RouteCache
    {
        return new RouteCache(
            $this->configuration('production')->router ?? throw new \LogicException('router: expected'),
            $this->tree->root->path,
            $this->tree->root->path . '/config.yml',
        );
    }

    /**
     * @return list<string>
     */
    private function paths(string $environment): array
    {
        $router = Router::fromConfiguration($this->configuration($environment), $this->tree->root->path);

        return array_map(static fn (Route $route): string => $route->path, array_values($router->routes()->all()));
    }
}
