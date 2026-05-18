<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Rendering;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\App;
use Zephyrus\Core\Config\Environment;
use Zephyrus\Rendering\Vite;
use Zephyrus\Rendering\ViteException;

final class ViteTest extends TestCase
{
    private const ENV_KEYS = [
        'APP_ENV',
        'VITE_DEV_SERVER',
        'VITE_DEV_SERVER_URL',
        'VITE_PUBLIC_DIRECTORY',
        'VITE_PUBLIC_DIR',
        'VITE_BUILD_DIRECTORY',
        'VITE_BUILD_DIR',
        'VITE_ASSET_URL',
    ];

    /** @var array<string, array{exists: bool, value: mixed}> */
    private array $envBackup = [];

    /** @var array<string, array{exists: bool, value: mixed}> */
    private array $serverBackup = [];

    /** @var array<string, string|false> */
    private array $processEnvBackup = [];

    /** @var string[] */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        App::reset();
        $this->backupEnvironment();
        $this->clearViteEnvironment();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tempDirs) as $dir) {
            $this->cleanDir($dir);
        }

        $this->restoreEnvironment();
        App::reset();
    }

    public function testRenderReturnsEmptyStringForEmptyEntrypoints(): void
    {
        self::assertSame('', Vite::render(['', '   ', 123, null], [
            'environment' => Environment::Development,
        ]));
    }

    public function testDevelopmentUsesDefaultEntryAndDefaultDevServer(): void
    {
        self::assertSame(
            implode("\n", [
                '<script type="module" src="http://localhost:5173/@vite/client"></script>',
                '<script type="module" src="http://localhost:5173/' . Vite::DEFAULT_ENTRY . '"></script>',
            ]),
            Vite::render(options: ['environment' => Environment::Development]),
        );
    }

    public function testDevelopmentUsesConfiguredDevServerAndMultipleEntries(): void
    {
        self::assertSame(
            implode("\n", [
                '<script type="module" src="http://vite.test:5173/@vite/client"></script>',
                '<script type="module" src="http://vite.test:5173/resources/js/app.js"></script>',
                '<script type="module" src="http://vite.test:5173/resources/js/admin.js"></script>',
            ]),
            Vite::render(['resources/js/app.js', 'resources/js/admin.js'], [
                'environment' => 'local',
                'devServer' => 'http://vite.test:5173/',
            ]),
        );
    }

    public function testDevelopmentUsesEnvHelperValues(): void
    {
        $_ENV['APP_ENV'] = 'development';
        $_ENV['VITE_DEV_SERVER'] = '   ';
        $_ENV['VITE_DEV_SERVER_URL'] = 'http://env-vite.test:5173/';

        self::assertSame(
            implode("\n", [
                '<script type="module" src="http://env-vite.test:5173/@vite/client"></script>',
                '<script type="module" src="http://env-vite.test:5173/main.js"></script>',
            ]),
            Vite::render('main.js'),
        );
    }

    public function testDevelopmentUsesProcessEnvironmentFallback(): void
    {
        putenv('APP_ENV=dev');
        putenv('VITE_DEV_SERVER=http://process-vite.test:5173');

        self::assertSame(
            implode("\n", [
                '<script type="module" src="http://process-vite.test:5173/@vite/client"></script>',
                '<script type="module" src="http://process-vite.test:5173/main.js"></script>',
            ]),
            Vite::render('main.js'),
        );
    }

    public function testProductionReadsDotViteManifestWithCamelCaseOptionsAndAssetUrl(): void
    {
        $publicDir = $this->makeTempDir('zephyrus_vite_public_');
        mkdir($publicDir . '/dist/.vite', 0755, true);
        $this->writeJson($publicDir . '/dist/.vite/manifest.json', [
            'resources/js/dashboard.js' => [
                'file' => 'assets/dashboard-123.js',
                'css' => ['assets/dashboard-123.css'],
            ],
        ]);

        self::assertSame(
            implode("\n", [
                '<link rel="stylesheet" href="https://cdn.example.test/assets/dashboard-123.css">',
                '<script type="module" src="https://cdn.example.test/assets/dashboard-123.js"></script>',
            ]),
            Vite::render('resources/js/dashboard.js', [
                'environment' => 'production',
                'publicDirectory' => $publicDir,
                'buildDirectory' => 'dist',
                'assetUrl' => 'https://cdn.example.test/',
            ]),
        );
    }

    public function testProductionFindsChunksBySourceAndDeduplicatesAssets(): void
    {
        $manifest = $this->makeTempDir('zephyrus_vite_manifest_') . '/manifest.json';
        $this->writeJson($manifest, [
            'ignored' => 'not-a-chunk',
            '_shared.js' => [
                'file' => 'assets/shared-123.js',
                'css' => ['assets/shared-123.css', '', 123],
            ],
            'entry-a' => [
                'src' => 'resources/js/app.js',
                'file' => 'https://cdn.example.test/assets/app-123.js',
                'imports' => ['_shared.js', '_missing.js', 123],
                'css' => ['assets/app-123.css', 'assets/shared-123.css'],
            ],
            'entry-b' => [
                'src' => 'resources/js/duplicate.js',
                'file' => 'https://cdn.example.test/assets/app-123.js',
                'css' => ['assets/app-123.css'],
            ],
        ]);

        self::assertSame(
            implode("\n", [
                '<link rel="stylesheet" href="/build/assets/shared-123.css">',
                '<link rel="stylesheet" href="/build/assets/app-123.css">',
                '<script type="module" src="https://cdn.example.test/assets/app-123.js"></script>',
            ]),
            Vite::render(['resources/js/app.js', 'resources/js/duplicate.js'], [
                'environment' => 'production',
                'manifest_path' => $manifest,
            ]),
        );
    }

    public function testProductionThrowsWhenDefaultManifestPathsAreMissing(): void
    {
        $publicDir = $this->makeTempDir('zephyrus_vite_empty_public_');

        $this->expectException(ViteException::class);
        $this->expectExceptionMessage($publicDir . '/build/manifest.json');
        $this->expectExceptionMessage($publicDir . '/build/.vite/manifest.json');

        Vite::render('resources/js/app.js', [
            'environment' => 'production',
            'public_directory' => $publicDir,
        ]);
    }

    public function testProductionUsesEnvironmentAssetPaths(): void
    {
        $publicDir = $this->makeTempDir('zephyrus_vite_env_public_');
        mkdir($publicDir . '/dist', 0755, true);
        $this->writeJson($publicDir . '/dist/manifest.json', [
            'resources/js/app.js' => [
                'file' => 'assets/app-123.js',
            ],
        ]);

        $_ENV['VITE_PUBLIC_DIRECTORY'] = $publicDir;
        $_ENV['VITE_BUILD_DIRECTORY'] = 'dist/';
        $_ENV['VITE_ASSET_URL'] = '/static/';

        self::assertSame(
            '<script type="module" src="/static/assets/app-123.js"></script>',
            Vite::render('resources/js/app.js', ['environment' => 'production']),
        );
    }

    public function testProductionThrowsWhenExplicitManifestPathIsMissing(): void
    {
        $missingManifest = $this->makeTempDir('zephyrus_vite_missing_manifest_') . '/manifest.json';

        $this->expectException(ViteException::class);
        $this->expectExceptionMessage($missingManifest);

        Vite::render('resources/js/app.js', [
            'environment' => 'production',
            'manifestPath' => $missingManifest,
        ]);
    }

    public function testProductionThrowsForInvalidJsonManifest(): void
    {
        $manifest = $this->makeTempDir('zephyrus_vite_invalid_manifest_') . '/manifest.json';
        file_put_contents($manifest, '{not-json');

        $this->expectException(ViteException::class);
        $this->expectExceptionMessage('not readable or valid JSON');

        Vite::render('resources/js/app.js', [
            'environment' => 'production',
            'manifest_path' => $manifest,
        ]);
    }

    public function testProductionThrowsForNonArrayManifest(): void
    {
        $manifest = $this->makeTempDir('zephyrus_vite_non_array_manifest_') . '/manifest.json';
        file_put_contents($manifest, '"not-an-object"');

        $this->expectException(ViteException::class);
        $this->expectExceptionMessage('not readable or valid JSON');

        Vite::render('resources/js/app.js', [
            'environment' => 'production',
            'manifest_path' => $manifest,
        ]);
    }

    public function testProductionThrowsWhenManifestEntryIsMissing(): void
    {
        $manifest = $this->makeTempDir('zephyrus_vite_missing_entry_') . '/manifest.json';
        $this->writeJson($manifest, [
            'resources/js/other.js' => [
                'file' => 'assets/other.js',
            ],
        ]);

        $this->expectException(ViteException::class);
        $this->expectExceptionMessage('resources/js/app.js');

        Vite::render('resources/js/app.js', [
            'environment' => 'production',
            'manifest_path' => $manifest,
        ]);
    }

    public function testProductionThrowsWhenManifestEntryHasNoFile(): void
    {
        $manifest = $this->makeTempDir('zephyrus_vite_missing_file_') . '/manifest.json';
        $this->writeJson($manifest, [
            'resources/js/app.js' => [
                'css' => ['assets/app.css'],
            ],
        ]);

        $this->expectException(ViteException::class);
        $this->expectExceptionMessage('does not define a file');

        Vite::render('resources/js/app.js', [
            'environment' => 'production',
            'manifest_path' => $manifest,
        ]);
    }

    private function backupEnvironment(): void
    {
        foreach (self::ENV_KEYS as $key) {
            $this->envBackup[$key] = [
                'exists' => array_key_exists($key, $_ENV),
                'value' => $_ENV[$key] ?? null,
            ];
            $this->serverBackup[$key] = [
                'exists' => array_key_exists($key, $_SERVER),
                'value' => $_SERVER[$key] ?? null,
            ];
            $this->processEnvBackup[$key] = getenv($key);
        }
    }

    private function clearViteEnvironment(): void
    {
        foreach (self::ENV_KEYS as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
    }

    private function restoreEnvironment(): void
    {
        foreach (self::ENV_KEYS as $key) {
            if ($this->envBackup[$key]['exists']) {
                $_ENV[$key] = $this->envBackup[$key]['value'];
            } else {
                unset($_ENV[$key]);
            }

            if ($this->serverBackup[$key]['exists']) {
                $_SERVER[$key] = $this->serverBackup[$key]['value'];
            } else {
                unset($_SERVER[$key]);
            }

            $value = $this->processEnvBackup[$key];
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
    }

    private function makeTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . uniqid();
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        file_put_contents($path, json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    private function cleanDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
