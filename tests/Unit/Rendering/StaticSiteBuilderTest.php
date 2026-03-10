<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Rendering;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\App;
use Zephyrus\Core\ApplicationBuilder;
use Zephyrus\Rendering\StaticBuildResult;
use Zephyrus\Rendering\StaticSiteBuilder;
use Zephyrus\Routing\Router;
use Zephyrus\Tests\Fixtures\Controllers\StaticPageController;

final class StaticSiteBuilderTest extends TestCase
{
    private string $outputDir;

    protected function setUp(): void
    {
        $this->outputDir = sys_get_temp_dir() . '/zephyrus_static_test_' . uniqid();
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->outputDir);
        App::reset();
    }

    public function testBuildCreatesIndexHtmlForRootRoute(): void
    {
        [$app, $router] = $this->buildApp(function (Router $router): Router {
            return $router->get('/', StaticPageController::class . '@index');
        });

        $builder = new StaticSiteBuilder($app, $router);
        $builder->setOutputDirectory($this->outputDir);
        $result = $builder->build();

        self::assertTrue($result->isSuccessful());
        self::assertSame(1, $result->pagesBuilt);
        self::assertFileExists($this->outputDir . '/index.html');
    }

    public function testBuildCreatesNestedDirectoriesForPaths(): void
    {
        [$app, $router] = $this->buildApp(function (Router $router): Router {
            return $router
                ->get('/', StaticPageController::class . '@index')
                ->get('/about', StaticPageController::class . '@index')
                ->get('/docs/routing', StaticPageController::class . '@index');
        });

        $builder = new StaticSiteBuilder($app, $router);
        $builder->setOutputDirectory($this->outputDir);
        $result = $builder->build();

        self::assertSame(3, $result->pagesBuilt);
        self::assertFileExists($this->outputDir . '/index.html');
        self::assertFileExists($this->outputDir . '/about/index.html');
        self::assertFileExists($this->outputDir . '/docs/routing/index.html');
    }

    public function testBuildSkipsParameterizedRoutes(): void
    {
        [$app, $router] = $this->buildApp(function (Router $router): Router {
            return $router
                ->get('/', StaticPageController::class . '@index')
                ->get('/users/{id}', StaticPageController::class . '@index');
        });

        $builder = new StaticSiteBuilder($app, $router);
        $builder->setOutputDirectory($this->outputDir);
        $result = $builder->build();

        self::assertSame(1, $result->pagesBuilt);
        self::assertFileExists($this->outputDir . '/index.html');
        self::assertDirectoryDoesNotExist($this->outputDir . '/users');
    }

    public function testBuildSkipsNonGetRoutes(): void
    {
        [$app, $router] = $this->buildApp(function (Router $router): Router {
            return $router
                ->get('/', StaticPageController::class . '@index')
                ->post('/api/submit', StaticPageController::class . '@index')
                ->delete('/api/delete', StaticPageController::class . '@index');
        });

        $builder = new StaticSiteBuilder($app, $router);
        $builder->setOutputDirectory($this->outputDir);
        $result = $builder->build();

        self::assertSame(1, $result->pagesBuilt);
    }

    public function testAddPathsIncludesExplicitPaths(): void
    {
        [$app, $router] = $this->buildApp(function (Router $router): Router {
            return $router->get('/docs/{section}/{slug}', StaticPageController::class . '@index');
        });

        $builder = new StaticSiteBuilder($app, $router);
        $builder->setOutputDirectory($this->outputDir);
        $builder->addPath('/docs/getting-started/introduction');
        $builder->addPaths(['/docs/fundamentals/routing']);
        $result = $builder->build();

        self::assertSame(2, $result->pagesBuilt);
        self::assertFileExists($this->outputDir . '/docs/getting-started/introduction/index.html');
        self::assertFileExists($this->outputDir . '/docs/fundamentals/routing/index.html');
    }

    public function testExcludePatternsFiltersRoutes(): void
    {
        [$app, $router] = $this->buildApp(function (Router $router): Router {
            return $router
                ->get('/', StaticPageController::class . '@index')
                ->get('/about', StaticPageController::class . '@index')
                ->get('/api/health', StaticPageController::class . '@index')
                ->get('/api/status', StaticPageController::class . '@index');
        });

        $builder = new StaticSiteBuilder($app, $router);
        $builder->setOutputDirectory($this->outputDir);
        $builder->excludePatterns(['#^/api/#']);
        $result = $builder->build();

        self::assertSame(2, $result->pagesBuilt);
        self::assertFileExists($this->outputDir . '/index.html');
        self::assertFileExists($this->outputDir . '/about/index.html');
        self::assertDirectoryDoesNotExist($this->outputDir . '/api');
    }

    public function testBuildCopiesPublicAssets(): void
    {
        [$app, $router] = $this->buildApp(function (Router $router): Router {
            return $router->get('/', StaticPageController::class . '@index');
        });

        // Create a fake public directory with assets
        $publicDir = $this->outputDir . '_public';
        mkdir($publicDir . '/assets/css', 0755, true);
        file_put_contents($publicDir . '/assets/css/app.css', 'body { color: red; }');
        file_put_contents($publicDir . '/index.php', '<?php // entry point');
        file_put_contents($publicDir . '/.htaccess', 'RewriteEngine On');

        $builder = new StaticSiteBuilder($app, $router);
        $builder->setOutputDirectory($this->outputDir);
        $builder->setPublicDirectory($publicDir);
        $result = $builder->build();

        // CSS file should be copied
        self::assertFileExists($this->outputDir . '/assets/css/app.css');
        self::assertSame('body { color: red; }', file_get_contents($this->outputDir . '/assets/css/app.css'));

        // index.php and .htaccess should be excluded
        self::assertFileDoesNotExist($this->outputDir . '/index.php');
        self::assertFileDoesNotExist($this->outputDir . '/.htaccess');

        $this->removeDirectory($publicDir);
    }

    public function testBuildThrowsWhenOutputDirectoryNotSet(): void
    {
        [$app, $router] = $this->buildApp(function (Router $router): Router {
            return $router->get('/', StaticPageController::class . '@index');
        });

        $builder = new StaticSiteBuilder($app, $router);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Output directory must be set');
        $builder->build();
    }

    public function testBuildResultSummaryFormatsCorrectly(): void
    {
        $result = new StaticBuildResult(
            pagesBuilt: 10,
            totalPaths: 10,
            elapsedMs: 42.5,
            errors: [],
            outputDirectory: '/tmp/dist',
        );

        self::assertTrue($result->isSuccessful());
        self::assertSame('Built 10/10 pages in 42.5ms [OK] -> /tmp/dist', $result->summary());
    }

    public function testBuildResultSummaryWithErrors(): void
    {
        $result = new StaticBuildResult(
            pagesBuilt: 8,
            totalPaths: 10,
            elapsedMs: 35.0,
            errors: ['/missing returned HTTP 404', '/broken failed: Error'],
            outputDirectory: '/tmp/dist',
        );

        self::assertFalse($result->isSuccessful());
        self::assertStringContainsString('2 error(s)', $result->summary());
    }

    public function testBuildDeduplicatesPaths(): void
    {
        [$app, $router] = $this->buildApp(function (Router $router): Router {
            return $router->get('/', StaticPageController::class . '@index');
        });

        $builder = new StaticSiteBuilder($app, $router);
        $builder->setOutputDirectory($this->outputDir);
        $builder->addPath('/');
        $builder->addPath('/');
        $result = $builder->build();

        // Should only build once even though / appears 3 times (route + 2 explicit)
        self::assertSame(1, $result->pagesBuilt);
        self::assertSame(1, $result->totalPaths);
    }

    /**
     * Build a minimal Zephyrus application with a controller factory that
     * returns a simple HTML response for any handler.
     *
     * @return array{0: \Zephyrus\Core\Application, 1: Router}
     */
    private function buildApp(callable $routeRegistrar): array
    {
        $router = $routeRegistrar(new Router());

        $app = ApplicationBuilder::create()
            ->withRouter($router)
            ->build();

        return [$app, $router];
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
    }
}
