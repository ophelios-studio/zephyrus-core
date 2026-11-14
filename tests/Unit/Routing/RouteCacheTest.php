<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Routing\Exception\RouteAttributeException;
use Zephyrus\Routing\Exception\RouteCacheException;
use Zephyrus\Routing\Exception\RouteMiddlewareException;
use Zephyrus\Routing\Route;
use Zephyrus\Routing\RouteCache;
use Zephyrus\Routing\RouteCollection;
use Zephyrus\Routing\Router;
use Zephyrus\Routing\RouterConfig;
use Zephyrus\Tests\Support\CapturedErrorLog;
use Zephyrus\Tests\Unit\Core\Cache\CacheDirectory;

final class RouteCacheTest extends TestCase
{
    use CapturedErrorLog;

    private const string NAMESPACE = 'Zephyrus\Tests\Fixtures\ConditionalControllers';
    private const array MIDDLEWARE_GROUPS = ['web' => ['session', 'csrf']];
    private const array VARIABLES = ['MODE', 'FLAG', 'APP_ENV', 'TENANT_APP'];

    private CacheDirectory $root;
    private ?CacheDirectory $outside = null;
    private ?ControllerTree $tree = null;

    /** @var array<string, array{bool, mixed, string|false}> */
    private array $savedEnvironment = [];

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $name) {
            $this->savedEnvironment[$name] = [array_key_exists($name, $_ENV), $_ENV[$name] ?? null, getenv($name, true)];
            unset($_ENV[$name]);
            putenv($name);
        }

        $this->root = new CacheDirectory();
        $this->root->file('config.yml', sprintf(
            "router:\n  controllers:\n    %s: %s\n  middleware_groups:\n    web: [session, csrf]\n",
            self::NAMESPACE,
            self::directory(),
        ));
        $this->captureErrorLog();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnvironment as $name => [$inEnv, $value, $inProcess]) {
            unset($_ENV[$name]);
            if ($inEnv) {
                $_ENV[$name] = $value;
            }

            putenv($inProcess === false ? $name : $name . '=' . $inProcess);
        }

        $this->root->remove();
        $this->outside?->remove();
        $this->tree?->remove();
        $this->releaseErrorLog();
    }

    /**
     * @param array<string, string> $everywhere
     * @param array<string, string> $onlyInEnv
     * @param array<string, string> $onlyInProcess
     */
    #[DataProvider('environments')]
    public function testTheCacheServesWhatDiscoveryRegistersInEveryEnvironment(
        array $everywhere,
        array $onlyInEnv = [],
        array $onlyInProcess = [],
    ): void {
        $this->routeCache()->warm();
        foreach ($everywhere + $onlyInEnv as $name => $value) {
            $_ENV[$name] = $value;
        }

        foreach ($everywhere + $onlyInProcess as $name => $value) {
            putenv($name . '=' . $value);
        }

        $discovered = self::described($this->discoveryRouter()->routes());

        self::assertSame($discovered, self::described($this->router('production')->routes()));
        self::assertSame($discovered, self::described($this->router('development')->routes()));
        self::assertSame('', $this->capturedErrorLog());
    }

    /**
     * @return iterable<string, array{0: array<string, string>, 1?: array<string, string>, 2?: array<string, string>}>
     */
    public static function environments(): iterable
    {
        yield 'no variable' => [[]];
        yield 'MODE web' => [['MODE' => 'web']];
        yield 'MODE api' => [['MODE' => 'api']];
        yield 'MODE WEB, which is not web' => [['MODE' => 'WEB']];
        yield 'APP_ENV testing' => [['APP_ENV' => 'testing']];
        yield 'APP_ENV production' => [['APP_ENV' => 'production']];
        yield 'TENANT_APP 1 in MODE web' => [['TENANT_APP' => '1', 'MODE' => 'web']];
        yield 'FLAG set to an empty string' => [['FLAG' => '']];
        yield 'MODE web and FLAG 1 only in $_ENV' => [[], ['MODE' => 'web', 'FLAG' => '1']];
        yield 'MODE web and FLAG 1 only in the process environment' => [[], [], ['MODE' => 'web', 'FLAG' => '1']];
    }

    public function testTheCacheHoldsNoValueOfTheEnvironmentItWasWarmedIn(): void
    {
        $_ENV['TENANT_APP'] = 'canary-7f3a9c';
        putenv('MODE=canary-41be02');

        $line = $this->routeCache()->warm();

        $written = (string) file_get_contents($this->routeCache()->path());
        self::assertStringNotContainsString('canary', $written . $line);
    }

    #[DataProvider('unsafeCacheFiles')]
    public function testAnUnsafeCacheIsNeverIncludedAndDiscoveryServesTheRoutes(string $unsafe): void
    {
        $cache = $this->routeCache();
        $cache->warm();
        $marker = $this->root->path . '/included';
        $payload = sprintf("<?php touch(%s); return [];\n", var_export($marker, true));
        $reason = match ($unsafe) {
            'group-writable file' => $this->groupWritableFile($cache->path(), $payload),
            'directory writable by others' => $this->directoryWritableByOthers($cache->path(), $payload),
            'link to a file outside the root' => $this->linkOutside($cache->path(), $payload),
        };

        $routes = self::described($this->router('production')->routes());

        self::assertSame(self::described($this->discoveryRouter()->routes()), $routes);
        self::assertFileDoesNotExist($marker);
        $this->assertLoggedOnce($cache->path(), $reason);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeCacheFiles(): iterable
    {
        yield 'group-writable file' => ['group-writable file'];
        yield 'directory writable by others' => ['directory writable by others'];
        yield 'link to a file outside the root' => ['link to a file outside the root'];
    }

    #[DataProvider('foreignOwners')]
    public function testACacheOwnedByAnotherUserIsNeverIncluded(string $foreign): void
    {
        $this->routeCache()->warm();
        $marker = $this->root->path . '/included';
        $path = $this->routeCache()->path();
        file_put_contents($path, sprintf("<?php touch(%s); return [];\n", var_export($marker, true)));
        $foreignPath = $foreign === 'the cache file' ? $path : dirname($path, 2);
        $ownerOf = static fn (string $file): int|false => $file === $foreignPath ? 4242 : fileowner($file);

        $routes = (new RouteCache(
            $this->routerConfig(),
            $this->root->path,
            $this->root->path . '/config.yml',
            $ownerOf,
        ))->load();

        self::assertNull($routes);
        self::assertFileDoesNotExist($marker);
        $this->assertLoggedOnce($path, sprintf(
            '%s is owned by neither root nor the owner of %s',
            $foreignPath,
            $this->root->path . '/config.yml',
        ));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignOwners(): iterable
    {
        yield 'the cache file' => ['the cache file'];
        yield 'a parent directory' => ['a parent directory'];
    }

    #[DataProvider('unreadableCacheFiles')]
    public function testAnUnreadableCacheFallsBackToDiscoveryWithoutFailingTheRequest(string $damage, string $reason): void
    {
        $cache = $this->routeCache();
        $cache->warm();
        $written = (string) file_get_contents($cache->path());
        file_put_contents($cache->path(), match ($damage) {
            'corrupted' => "<?php return ['format' => ;\n",
            'truncated' => substr($written, 0, intdiv(strlen($written), 2)),
            'not an array' => "<?php return 'routes';\n",
            'another framework version' => (string) preg_replace(
                "/'framework' => '[^']*'/",
                "'framework' => '0.0.1'",
                $written,
            ),
            'a handler outside router.controllers' => str_replace(
                "'" . substr(var_export(self::NAMESPACE, true), 1, -1),
                "'Elsewhere",
                $written,
            ),
        });

        $routes = self::described($this->router('production')->routes());

        self::assertSame(self::described($this->discoveryRouter()->routes()), $routes);
        $this->assertLoggedOnce($cache->path(), $reason);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unreadableCacheFiles(): iterable
    {
        yield 'corrupted' => ['corrupted', 'it does not load (ParseError)'];
        yield 'truncated' => ['truncated', 'it does not load (ParseError)'];
        yield 'not an array' => ['not an array', 'it does not return a boot cache array'];
        yield 'another framework version' => [
            'another framework version',
            'it was written by another version of the framework',
        ];
        yield 'a handler outside router.controllers' => [
            'a handler outside router.controllers',
            'its route 0 has a handler outside router.controllers',
        ];
    }

    #[DataProvider('editsAfterTheWarm')]
    public function testACacheIsIgnoredOnceWhatItWasReadFromChanges(string $edit): void
    {
        $tree = $this->tree();
        $config = $tree->configure();
        $parent = $tree->parent('BaseController', "abstract class BaseController\n{\n}");
        $controller = $tree->controller('ReportController', self::controllerSource('ReportController', '/reports', 'Base\BaseController'));
        $cache = new RouteCache($config, $tree->root->path, $tree->root->path . '/config.yml');
        $cache->warm();

        match ($edit) {
            'a controller added' => $tree->controller('ExportController', self::controllerSource('ExportController', '/exports')),
            'an attribute edited' => self::rewrite($controller, '/reports', '/records'),
            'a parent outside the controller directory edited' => self::rewrite($parent, '{', '{ '),
            'config.yml edited' => self::rewrite($tree->root->path . '/config.yml', 'router:', "# edited\nrouter:"),
        };

        self::assertNull($cache->load());
        $this->assertLoggedOnce($cache->path(), 'it is stale, its source changed after it was written');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function editsAfterTheWarm(): iterable
    {
        yield 'a controller added' => ['a controller added'];
        yield 'an attribute edited' => ['an attribute edited'];
        yield 'a parent outside the controller directory edited' => ['a parent outside the controller directory edited'];
        yield 'config.yml edited' => ['config.yml edited'];
    }

    public function testAControllerAddedAfterTheWarmIsServedByDiscovery(): void
    {
        $tree = $this->tree();
        $tree->configure();
        $tree->controller('ReportController', self::controllerSource('ReportController', '/reports'));
        $this->routeCacheOf($tree)->warm();
        $tree->controller('ExportController', self::controllerSource('ExportController', '/exports'));

        $paths = array_map(
            static fn (Route $route): string => $route->path,
            array_values(Router::fromConfiguration($this->configuration('production', $tree), $tree->root->path)->routes()->all()),
        );

        self::assertSame(['/exports', '/reports'], $paths);
    }

    #[DataProvider('discoveries')]
    public function testTwoRoutesOfOneNameThatCanBeRegisteredTogetherAreRefused(string $discovery): void
    {
        $tree = $this->tree();
        $tree->configure();
        $tree->controller('TwinController', <<<'PHP'
            final class TwinController
            {
                #[Get('/one', name: 'twin')]
                #[RequiresEnv('MODE', 'web')]
                public function one(): void
                {
                }

                #[Get('/two', name: 'twin')]
                #[RequiresEnv('FLAG', '1')]
                public function two(): void
                {
                }
            }
            PHP);

        try {
            $discovery === 'cache:warm'
                ? $this->routeCacheOf($tree)->warm()
                : Router::fromConfiguration($this->configuration('development', $tree), $tree->root->path);
            self::fail('expected a refusal');
        } catch (RouteAttributeException $exception) {
            self::assertSame(
                sprintf(
                    'Route name "twin" is declared by %1$s\TwinController@one and by %1$s\TwinController@two, which '
                    . 'can be registered together: rename one, or require one environment variable at a different '
                    . 'value on each with #[RequiresEnv].',
                    $tree->namespace,
                ),
                $exception->getMessage(),
            );
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function discoveries(): iterable
    {
        yield 'cache:warm' => ['cache:warm'];
        yield 'discovery in development' => ['discovery in development'];
    }

    public function testAMiddlewareGroupLeftUndeclaredFailsTheWarm(): void
    {
        $tree = $this->tree();
        $tree->configure();
        $tree->controller('AdminController', <<<'PHP'
            #[MiddlewareGroup('admin')]
            final class AdminController
            {
                #[Get('/admin')]
                public function index(): void
                {
                }
            }
            PHP);

        try {
            $this->routeCacheOf($tree)->warm();
            self::fail('expected a refusal');
        } catch (RouteMiddlewareException $exception) {
            self::assertSame(
                sprintf(
                    'Middleware group "admin", named by a #[MiddlewareGroup] of %s\AdminController@index, is not '
                    . 'declared under router.middleware_groups: declare it there, or the route would run without its '
                    . 'middlewares.',
                    $tree->namespace,
                ),
                $exception->getMessage(),
            );
        }

        self::assertFileDoesNotExist($tree->root->path . '/' . RouterConfig::DEFAULT_CACHE);
    }

    public function testARequirementOnAVariableThatIsNeverReadFailsTheWarm(): void
    {
        $tree = $this->tree();
        $tree->configure();
        $tree->controller('SpoofedController', <<<'PHP'
            #[RequiresEnv('HTTP_MODE', 'web')]
            final class SpoofedController
            {
                #[Get('/spoofed')]
                public function index(): void
                {
                }
            }
            PHP);

        try {
            $this->routeCacheOf($tree)->warm();
            self::fail('expected a refusal');
        } catch (RouteAttributeException $exception) {
            self::assertSame(
                sprintf(
                    'Cannot read the #[RequiresEnv] of class "%s\SpoofedController": "HTTP_MODE": names starting with '
                    . 'HTTP_ can be set per request by the client request headers, so they are never read as '
                    . 'configuration; rename the variable.',
                    $tree->namespace,
                ),
                $exception->getMessage(),
            );
        }
    }

    public function testControllersWithoutRoutesAreNotCached(): void
    {
        $tree = $this->tree();
        $tree->configure();
        $tree->controller('EmptyController', "final class EmptyController\n{\n}");

        try {
            $this->routeCacheOf($tree)->warm();
            self::fail('expected a refusal');
        } catch (RouteCacheException $exception) {
            self::assertSame(
                sprintf(
                    'The controllers of router.controllers declare no route, so the routes cache %s was not written: '
                    . 'check that each namespace matches its directory.',
                    $tree->root->path . '/' . RouterConfig::DEFAULT_CACHE,
                ),
                $exception->getMessage(),
            );
        }
    }

    private static function directory(): string
    {
        return (string) realpath(__DIR__ . '/../../Fixtures/ConditionalControllers');
    }

    private function routerConfig(): RouterConfig
    {
        return RouterConfig::fromArray([
            'controllers' => [self::NAMESPACE => self::directory()],
            'middleware_groups' => self::MIDDLEWARE_GROUPS,
        ]);
    }

    private function routeCache(): RouteCache
    {
        return new RouteCache($this->routerConfig(), $this->root->path, $this->root->path . '/config.yml');
    }

    private function routeCacheOf(ControllerTree $tree): RouteCache
    {
        return new RouteCache(
            $this->configuration('production', $tree)->router ?? throw new \LogicException('router: expected'),
            $tree->root->path,
            $tree->root->path . '/config.yml',
        );
    }

    private function tree(): ControllerTree
    {
        return $this->tree = new ControllerTree();
    }

    private function configuration(string $environment, ?ControllerTree $tree = null): Configuration
    {
        return Configuration::fromArray([
            'application' => ['environment' => $environment],
            'router' => $tree === null
                ? $this->routerConfig()->toArray()
                : ['controllers' => [$tree->namespace => 'app/Controllers']],
        ]);
    }

    private function router(string $environment): Router
    {
        return Router::fromConfiguration($this->configuration($environment), $this->root->path);
    }

    private function discoveryRouter(): Router
    {
        $router = new Router();
        foreach (self::MIDDLEWARE_GROUPS as $name => $middlewares) {
            $router = $router->middlewareGroup($name, $middlewares);
        }

        return $router->discoverControllers(self::NAMESPACE, self::directory());
    }

    /**
     * @return list<array{string, string, string, ?string, array<int, string>, list<string>, array<string, string>}>
     */
    private static function described(RouteCollection $routes): array
    {
        return array_map(
            static fn (Route $route): array => [
                $route->method,
                $route->path,
                $route->handler,
                $route->name,
                $route->middlewares,
                $route->excludedMiddlewares,
                $route->constraints,
            ],
            array_values($routes->all()),
        );
    }

    private function assertLoggedOnce(string $cachePath, string $reason): void
    {
        $log = $this->capturedErrorLog();

        self::assertSame(1, substr_count($log, "\n"), $log);
        self::assertStringEndsWith(
            sprintf(
                "Zephyrus: ignored the boot cache %s: %s. Run vendor/bin/zephyrus cache:warm to rebuild it.\n",
                $cachePath,
                $reason,
            ),
            $log,
        );
    }

    private function groupWritableFile(string $path, string $payload): string
    {
        file_put_contents($path, $payload);
        chmod($path, 0o664);

        return sprintf('%s is writable by its group or by others', $path);
    }

    private function directoryWritableByOthers(string $path, string $payload): string
    {
        file_put_contents($path, $payload);
        chmod(dirname($path), 0o757);

        return sprintf('%s is writable by its group or by others', dirname($path));
    }

    private function linkOutside(string $path, string $payload): string
    {
        $this->outside = new CacheDirectory();
        unlink($path);
        symlink($this->outside->file('routes.php', $payload), $path);

        return sprintf('it resolves outside %s', $this->root->path);
    }

    private static function rewrite(string $file, string $search, string $replace): void
    {
        $modified = (int) filemtime($file) + 60;
        file_put_contents($file, str_replace($search, $replace, (string) file_get_contents($file)));
        touch($file, $modified);
    }

    private static function controllerSource(string $shortName, string $path, string $parent = ''): string
    {
        return sprintf(
            "final class %s%s\n{\n    #[Get('%s')]\n    public function index(): void\n    {\n    }\n}",
            $shortName,
            $parent === '' ? '' : ' extends ' . $parent,
            $path,
        );
    }
}
