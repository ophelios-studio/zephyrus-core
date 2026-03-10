<?php

declare(strict_types=1);

namespace Zephyrus\Rendering;

use Zephyrus\Core\Application;
use Zephyrus\Http\Request;
use Zephyrus\Routing\Router;

/**
 * Builds a static HTML site from a running Zephyrus application.
 *
 * Collects all non-parameterized GET routes from the Router, dispatches each
 * through the full Application stack (middleware, events, controllers), and
 * writes the rendered HTML to an output directory.
 *
 * Usage:
 *
 *   $builder = new StaticSiteBuilder($app, $router);
 *   $builder->setOutputDirectory(ROOT_DIR . '/dist');
 *   $builder->setPublicDirectory(ROOT_DIR . '/public');
 *   $builder->addPaths($dynamicPaths);   // for parameterized routes
 *   $result = $builder->build();
 *
 * The output directory will contain:
 *   - /index.html              (for route /)
 *   - /about/index.html        (for route /about)
 *   - /docs/routing/index.html (for route /docs/routing)
 *   - /assets/...              (copied from public directory)
 */
final class StaticSiteBuilder
{
    private string $outputDirectory = '';
    private string $publicDirectory = '';
    private string $baseUrl = 'http://localhost';

    /** @var list<string> */
    private array $additionalPaths = [];

    /** @var list<string> */
    private array $excludePatterns = [];

    /** @var list<string> */
    private array $assetExcludes = ['index.php', '.htaccess'];

    public function __construct(
        private readonly Application $application,
        private readonly Router $router,
    ) {
    }

    /**
     * Set the output directory where static files will be written.
     */
    public function setOutputDirectory(string $path): void
    {
        $this->outputDirectory = rtrim($path, '/\\');
    }

    /**
     * Set the public directory whose assets (CSS, JS, images) will be copied
     * to the output. The index.php and .htaccess files are excluded by default.
     */
    public function setPublicDirectory(string $path): void
    {
        $this->publicDirectory = rtrim($path, '/\\');
    }

    /**
     * Set the base URL used when constructing internal Request objects.
     */
    public function setBaseUrl(string $url): void
    {
        $this->baseUrl = rtrim($url, '/');
    }

    /**
     * Add explicit paths to build. Use this for parameterized routes where
     * you supply the concrete values (e.g. /docs/routing/introduction).
     *
     * @param list<string> $paths
     */
    public function addPaths(array $paths): void
    {
        foreach ($paths as $path) {
            $this->additionalPaths[] = '/' . ltrim($path, '/');
        }
    }

    /**
     * Add a single explicit path to build.
     */
    public function addPath(string $path): void
    {
        $this->additionalPaths[] = '/' . ltrim($path, '/');
    }

    /**
     * Exclude routes matching the given regex patterns from the build.
     *
     * @param list<string> $patterns Regex patterns (e.g. '#^/api/#').
     */
    public function excludePatterns(array $patterns): void
    {
        $this->excludePatterns = $patterns;
    }

    /**
     * Set filenames to exclude when copying the public directory.
     *
     * @param list<string> $filenames Defaults to ['index.php', '.htaccess'].
     */
    public function setAssetExcludes(array $filenames): void
    {
        $this->assetExcludes = $filenames;
    }

    /**
     * Build the static site.
     *
     * @return StaticBuildResult
     */
    public function build(): StaticBuildResult
    {
        if ($this->outputDirectory === '') {
            throw new \RuntimeException('Output directory must be set before building.');
        }

        $startTime = hrtime(true);
        $paths = $this->collectPaths();
        $pagesBuilt = 0;
        $errors = [];

        foreach ($paths as $path) {
            try {
                $request = Request::fromArray('GET', $this->baseUrl . $path);
                $response = $this->application->handle($request);

                if ($response->status >= 300 && $response->status < 400) {
                    // Skip redirects — they don't produce HTML output.
                    continue;
                }

                if ($response->status !== 200) {
                    $errors[] = sprintf('%s returned HTTP %d', $path, $response->status);
                    continue;
                }

                $this->writePage($path, $response->body);
                $pagesBuilt++;
            } catch (\Throwable $e) {
                $errors[] = sprintf('%s failed: %s', $path, $e->getMessage());
            }
        }

        if ($this->publicDirectory !== '' && is_dir($this->publicDirectory)) {
            $this->copyAssets();
        }

        $elapsed = (hrtime(true) - $startTime) / 1_000_000; // milliseconds

        return new StaticBuildResult(
            pagesBuilt: $pagesBuilt,
            totalPaths: count($paths),
            elapsedMs: round($elapsed, 2),
            errors: $errors,
            outputDirectory: $this->outputDirectory,
        );
    }

    /**
     * Collect all paths to build: static GET routes + additional explicit paths.
     *
     * @return list<string>
     */
    private function collectPaths(): array
    {
        $paths = [];

        foreach ($this->router->routes()->all() as $route) {
            if ($route->method !== 'GET') {
                continue;
            }
            if (str_contains($route->path, '{')) {
                continue;
            }
            $paths[] = $route->path;
        }

        $paths = array_merge($paths, $this->additionalPaths);
        $paths = array_values(array_unique($paths));

        if ($this->excludePatterns !== []) {
            $paths = array_values(array_filter($paths, function (string $path): bool {
                foreach ($this->excludePatterns as $pattern) {
                    if (preg_match($pattern, $path)) {
                        return false;
                    }
                }
                return true;
            }));
        }

        sort($paths);
        return $paths;
    }

    /**
     * Write a rendered page to the output directory.
     */
    private function writePage(string $path, string $body): void
    {
        $filePath = $this->outputDirectory . ($path === '/' ? '/index.html' : $path . '/index.html');
        $directory = dirname($filePath);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($filePath, $body);
    }

    /**
     * Recursively copy public assets to the output directory, excluding
     * server-side files (index.php, .htaccess by default).
     */
    private function copyAssets(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->publicDirectory,
                \RecursiveDirectoryIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($this->publicDirectory) + 1);

            if (in_array(basename($relativePath), $this->assetExcludes, true)) {
                continue;
            }

            $targetPath = $this->outputDirectory . '/' . $relativePath;

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } else {
                $targetDir = dirname($targetPath);
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }
                copy($item->getPathname(), $targetPath);
            }
        }
    }
}
