<?php

declare(strict_types=1);

namespace Zephyrus\Rendering;

use Closure;
use InvalidArgumentException;
use Latte\Engine;
use Latte\Extension;
use Zephyrus\Exceptions\MessageValue;
use Zephyrus\FileSystem\SafePath;
use Zephyrus\Formatting\Formatter;

/**
 * Latte 3.x template rendering engine.
 *
 * Templates use the `.latte` extension. The page identifier is a relative path
 * without extension: `render('users/show')` renders `{directory}/users/show.latte`.
 *
 * Filters added by registerFormatterFilters() are usable in templates, e.g. `{$price|money}`.
 *
 * Cache mode:
 *   - 'always' (default): compiled templates are written to the cache directory and
 *     recompiled when the source file changes.
 *   - 'never': nothing is cached, every render compiles in memory.
 * Any other value is refused at construction.
 *
 * A page of the form `@namespace/page` is read from a directory declared with addNamespace().
 *
 * ## Path safety
 * The page identifier is untrusted. A page containing a `..` segment or a null
 * byte is refused, and the resolved file must sit under the template directory,
 * or under the declared directory of its namespace, after `realpath()`. A declared
 * namespace therefore opens every `.latte` file under its directory to a page
 * identifier taken from user input: declare the views sub-directory of a package,
 * never its root.
 */
final class LatteEngine implements RenderEngine
{
    public const string EXTENSION = '.latte';

    private const array BUILT_IN_FILTERS = [
        'money', 'date', 'datetime', 'time', 'filesize', 'percent',
        'decimal', 'timeago', 'duration', 'list', 'ordinal', 'spellOut',
        'truncate',
    ];

    private Engine $latte;
    private string $directory;
    private NamespacedLoader $loader;

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
        $this->loader = new NamespacedLoader($this->directory);
        $this->latte->setLoader($this->loader);
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

    /**
     * Whether a page resolves to a template; false for a page of an undeclared namespace.
     */
    public function exists(string $page): bool
    {
        try {
            return $this->resolvePath($page) !== null;
        } catch (RenderException) {
            return false;
        }
    }

    /**
     * Make the templates of a package reachable as `@namespace/page`.
     *
     * Only a LatteEngine has namespaces: check the engine type before calling this on a RenderEngine.
     * The application overrides a template with a file of the same name under `{views}/vendor/{namespace}/`,
     * consulted before the package directory. A relative name inside a template resolves against the
     * directory of that file, so an override does not see its package siblings: in a package template,
     * include its siblings as `@namespace/name.latte`.
     *
     * @param string $namespace Lowercase letters, digits, `_` and `-`, starting with a letter or a digit.
     * @param string $directory Absolute path to the existing views directory of the package, not its root.
     * @throws InvalidArgumentException When the name or directory is invalid, or the namespace is declared.
     */
    public function addNamespace(string $namespace, string $directory): void
    {
        if (preg_match('/\A[a-z0-9][a-z0-9_-]*\z/', $namespace) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Template namespace %s is invalid: use lowercase letters, digits, "_" and "-", '
                . 'starting with a letter or a digit.',
                MessageValue::quote($namespace),
            ));
        }

        if (!self::isAbsolute($directory) || !SafePath::isDirectory($directory)) {
            throw new InvalidArgumentException(sprintf(
                'Template namespace %s needs an existing absolute directory, got %s.',
                MessageValue::quote($namespace),
                MessageValue::quote($directory, 512, keepEnd: true),
            ));
        }

        if ($this->loader->isDeclared($namespace)) {
            throw new InvalidArgumentException(sprintf(
                'Template namespace %s is already declared.',
                MessageValue::quote($namespace),
            ));
        }

        $this->loader->declare($namespace, $directory);
    }

    /**
     * Add a Latte extension after construction.
     */
    public function addExtension(Extension $extension): void
    {
        $this->latte->addExtension($extension);
    }

    /**
     * Register the built-in and custom Formatter methods as Latte filters.
     *
     * A Closure is called on every filter use, so a formatter replaced later is honoured. The custom
     * filter names are read from the formatter it returns at registration time.
     *
     * @param Formatter|Closure(): Formatter $formatter
     */
    public function registerFormatterFilters(Formatter|Closure $formatter): void
    {
        $provider = $formatter instanceof Closure ? $formatter : static fn (): Formatter => $formatter;

        foreach (self::BUILT_IN_FILTERS as $name) {
            $this->latte->addFilter($name, fn (mixed ...$args): mixed => $provider()->$name(...$args));
        }

        foreach ($provider()->getCustomFormatterNames() as $name) {
            $this->latte->addFilter($name, fn (mixed ...$args): string => $provider()->format($name, ...$args));
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
     * Resolve a page identifier to a readable file inside the template directory.
     *
     * @return string|null Null when the page traverses out of the directory,
     *                     is unreadable, or does not exist.
     * @throws RenderException When the page names an undeclared namespace.
     */
    private function resolvePath(string $page): ?string
    {
        if (NamespacedLoader::isNamespaced($page)) {
            return $this->loader->resolve($page, self::EXTENSION);
        }

        $path = SafePath::within($this->directory, $page . self::EXTENSION);

        return $path !== null && is_file($path) ? $path : null;
    }

    /**
     * The path a page identifier would resolve to, for error reporting only.
     */
    private function candidatePath(string $page): string
    {
        if (NamespacedLoader::isNamespaced($page)) {
            return $this->loader->candidatePath($page, self::EXTENSION);
        }

        return $this->directory . '/' . ltrim($page, '/\\') . self::EXTENSION;
    }

    private static function isAbsolute(string $path): bool
    {
        return preg_match('#\A(?:[/\\\\]|[A-Za-z]:[/\\\\])#', $path) === 1;
    }

    /**
     * Apply the cache mode.
     */
    private function configureCache(string $cacheDirectory, string $cacheMode): void
    {
        if ($cacheMode === 'never') {
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
