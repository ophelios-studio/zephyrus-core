<?php

declare(strict_types=1);

namespace Zephyrus\Rendering;

use Zephyrus\Core\Config\ConfigSection;

/**
 * Configuration section for the rendering engine.
 *
 * YAML example:
 *
 *   render:
 *     engine: latte          # 'latte' or 'php'
 *     directory: app/Views   # relative to project root or absolute
 *     cache: cache/latte     # Latte cache directory
 *     mode: always           # 'always' or 'never'
 *     extension: .php        # file extension for PhpEngine (default '.php')
 *
 * Defaults:
 *   - engine:    'latte'
 *   - directory: 'app/Views'
 *   - cache:     'cache/latte'
 *   - mode:      'always'
 *   - extension: '.php'
 */
final class RenderConfig extends ConfigSection
{
    public readonly string $engine;
    public readonly string $directory;
    public readonly string $cache;
    public readonly string $mode;
    public readonly string $extension;

    public static function fromArray(array $values): static
    {
        $instance = new static($values);
        $instance->engine = $instance->getString('engine', 'latte');
        $instance->directory = $instance->getString('directory', 'app/Views');
        $instance->cache = $instance->getString('cache', 'cache/latte');
        $instance->mode = $instance->getString('mode', 'always');
        $instance->extension = $instance->getString('extension', '.php');
        return $instance;
    }

    /**
     * Build a RenderEngine instance from this configuration.
     *
     * @param string|null $basePath Project root directory used to resolve
     *                              relative directory/cache paths. If null,
     *                              the directory and cache values must be absolute.
     */
    public function createEngine(?string $basePath = null): RenderEngine
    {
        $directory = $this->resolvePath($this->directory, $basePath);
        $cache = $this->resolvePath($this->cache, $basePath);

        return match ($this->engine) {
            'latte' => new LatteEngine($directory, $cache, $this->mode),
            'php' => new PhpEngine($directory, $this->extension),
            default => throw RenderException::engineError(sprintf(
                'Unknown render engine [%s]. Supported engines: latte, php.',
                $this->engine,
            )),
        };
    }

    private function resolvePath(string $path, ?string $basePath): string
    {
        // Already absolute.
        if (str_starts_with($path, '/')) {
            return $path;
        }

        if ($basePath === null) {
            return $path;
        }

        return rtrim($basePath, '/\\') . '/' . $path;
    }
}
