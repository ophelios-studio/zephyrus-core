<?php

declare(strict_types=1);

namespace Zephyrus\Rendering;

use Latte\Engine;
use Latte\Extension;

/**
 * Latte 3.x template rendering engine.
 *
 * Wraps the Latte Engine with configurable template directory, cache
 * directory, auto-refresh behaviour, and support for Latte extensions.
 *
 * Template files must use the `.latte` extension. The page identifier
 * passed to `render()` is a relative path without extension:
 *
 *   $engine->render('users/show', ['user' => $user]);
 *   // resolves to: {directory}/users/show.latte
 *
 * Cache mode:
 *   - 'always' (default): templates are recompiled when the source changes.
 *   - 'never': templates are always recompiled (useful during development).
 */
final class LatteEngine implements RenderEngine
{
    public const string EXTENSION = '.latte';

    private Engine $latte;
    private string $directory;

    /**
     * @param string      $directory      Absolute path to the template directory.
     * @param string      $cacheDirectory Absolute path to the cache directory for compiled templates.
     * @param string      $cacheMode      Cache behaviour: 'always' or 'never'.
     * @param Extension[] $extensions     Latte extensions to register.
     */
    public function __construct(
        string $directory,
        string $cacheDirectory,
        string $cacheMode = 'always',
        array $extensions = [],
    ) {
        $this->directory = rtrim($directory, '/\\');
        $this->latte = new Engine();
        $this->configureCacheDirectory($cacheDirectory);
        $this->configureCacheMode($cacheMode);

        foreach ($extensions as $extension) {
            $this->latte->addExtension($extension);
        }
    }

    public function render(string $page, array $args = []): string
    {
        $path = $this->resolvePath($page);

        if (!is_file($path) || !is_readable($path)) {
            throw RenderException::templateNotFound($page, $path);
        }

        try {
            return $this->latte->renderToString($path, $args);
        } catch (\Throwable $e) {
            throw RenderException::renderFailed($page, $e);
        }
    }

    public function exists(string $page): bool
    {
        $path = $this->resolvePath($page);
        return is_file($path) && is_readable($path);
    }

    /**
     * Add a Latte extension after construction.
     */
    public function addExtension(Extension $extension): void
    {
        $this->latte->addExtension($extension);
    }

    /**
     * Access the underlying Latte engine for advanced configuration.
     */
    public function getLatteEngine(): Engine
    {
        return $this->latte;
    }

    /**
     * Resolve a page identifier to an absolute file path.
     */
    private function resolvePath(string $page): string
    {
        return $this->directory . '/' . ltrim($page, '/\\') . self::EXTENSION;
    }

    private function configureCacheDirectory(string $cacheDirectory): void
    {
        $cacheDirectory = rtrim($cacheDirectory, '/\\');

        if (!is_dir($cacheDirectory)) {
            if (!@mkdir($cacheDirectory, 0755, true) && !is_dir($cacheDirectory)) {
                throw RenderException::engineError(sprintf(
                    'Unable to create Latte cache directory: %s',
                    $cacheDirectory,
                ));
            }
        }

        $this->latte->setTempDirectory($cacheDirectory);
    }

    private function configureCacheMode(string $cacheMode): void
    {
        if ($cacheMode === 'never') {
            $this->latte->setAutoRefresh(true);
        } elseif ($cacheMode === 'always') {
            // Latte's default: auto-refresh is on, checks file mtime.
            $this->latte->setAutoRefresh(true);
        }
    }
}
