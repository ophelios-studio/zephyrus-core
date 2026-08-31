<?php

declare(strict_types=1);

namespace Zephyrus\Rendering;

use Latte\Engine;
use Latte\Extension;
use Zephyrus\FileSystem\SafePath;
use Zephyrus\Formatting\Formatter;

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
 *   - 'always' (default): compiled templates are written to the cache directory
 *     and recompiled when the source changes.
 *   - 'never': no cache directory is used at all, so every render recompiles
 *     the template in memory (useful during development).
 * Any other value is a configuration error and is refused at construction.
 *
 * ## Path safety
 * The page identifier is resolved against the template directory, so it is
 * treated as untrusted. A page containing a `..` segment or a null byte is
 * refused outright, and the resolved file must still sit under the configured
 * template directory once `realpath()` has collapsed symbolic links.
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
        $this->configureCache($cacheDirectory, $cacheMode);

        foreach ($extensions as $extension) {
            $this->latte->addExtension($extension);
        }
    }

    public function render(string $page, array $args = []): string
    {
        $path = $this->resolvePath($page);

        if ($path === null) {
            throw RenderException::templateNotFound($page, $this->candidatePath($page));
        }

        try {
            return $this->latte->renderToString($path, $args);
        } catch (\Throwable $e) {
            throw RenderException::renderFailed($page, $e);
        }
    }

    public function exists(string $page): bool
    {
        return $this->resolvePath($page) !== null;
    }

    /**
     * Add a Latte extension after construction.
     */
    public function addExtension(Extension $extension): void
    {
        $this->latte->addExtension($extension);
    }

    /**
     * Register all Formatter methods (built-in and custom) as Latte filters.
     *
     * After calling this, templates can use pipe syntax such as
     * `{$price|money}`, `{$date|date}`, or `{$value|phone}` for any
     * custom formatter registered on the Formatter instance.
     *
     * Built-in formatters: money, date, datetime, time, filesize, percent,
     * decimal, timeago, duration, list, ordinal, spellOut, truncate.
     */
    public function registerFormatterFilters(Formatter $formatter): void
    {
        $builtInFilters = [
            'money', 'date', 'datetime', 'time', 'filesize', 'percent',
            'decimal', 'timeago', 'duration', 'list', 'ordinal', 'spellOut',
            'truncate',
        ];

        foreach ($builtInFilters as $name) {
            $this->latte->addFilter($name, $formatter->$name(...));
        }

        foreach ($formatter->getCustomFormatterNames() as $name) {
            $this->latte->addFilter($name, fn (mixed ...$args): string => $formatter->format($name, ...$args));
        }
    }

    /**
     * Access the underlying Latte engine for advanced configuration.
     */
    public function getLatteEngine(): Engine
    {
        return $this->latte;
    }

    /**
     * Resolve a page identifier to a readable absolute file path contained
     * within the template directory.
     *
     * @return string|null Null when the page traverses out of the directory,
     *                     is unreadable, or does not exist.
     */
    private function resolvePath(string $page): ?string
    {
        $path = SafePath::within($this->directory, $page . self::EXTENSION);

        return $path !== null && is_file($path) ? $path : null;
    }

    /**
     * The path a page identifier would have resolved to, for error reporting only.
     */
    private function candidatePath(string $page): string
    {
        return $this->directory . '/' . ltrim($page, '/\\') . self::EXTENSION;
    }

    /**
     * Apply the cache mode, which decides whether a compiled-template cache is
     * used at all.
     */
    private function configureCache(string $cacheDirectory, string $cacheMode): void
    {
        if ($cacheMode === 'never') {
            // No temp directory: Latte compiles the template on every render.
            $this->latte->setAutoRefresh(true);
            return;
        }

        if ($cacheMode !== 'always') {
            throw RenderException::engineError(sprintf(
                'Unknown Latte cache mode [%s]. Supported modes: always, never.',
                $cacheMode,
            ));
        }

        $this->configureCacheDirectory($cacheDirectory);
        $this->latte->setAutoRefresh(true);
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
}
