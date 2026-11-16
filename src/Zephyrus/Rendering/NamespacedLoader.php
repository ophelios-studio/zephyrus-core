<?php

declare(strict_types=1);

namespace Zephyrus\Rendering;

use Latte\Loader;
use Latte\Loaders\FileLoader;
use Zephyrus\FileSystem\SafePath;

/**
 * Latte loader that resolves `@namespace/name` templates and delegates every other name to the file loader.
 *
 * A namespaced name is looked up under `{views}/vendor/{namespace}/` first, then under the namespace directory.
 *
 * @internal
 */
final class NamespacedLoader implements Loader
{
    private const string NAMESPACED = '#\A@[a-z0-9][a-z0-9_-]*/#';

    private FileLoader $files;
    private string $overrideRoot;

    /** @var array<string, string> */
    private array $directories = [];

    public function __construct(string $viewsDirectory)
    {
        $this->files = new FileLoader();
        $this->overrideRoot = rtrim($viewsDirectory, '/\\') . '/vendor';
    }

    /**
     * Declare a namespace; the name and directory are validated by the caller.
     */
    public function declare(string $namespace, string $directory): void
    {
        $this->directories[$namespace] = rtrim($directory, '/\\');
    }

    /**
     * Whether the namespace has been declared.
     */
    public function isDeclared(string $namespace): bool
    {
        return isset($this->directories[$namespace]);
    }

    /**
     * Whether a name is `@namespace/...`; any other name, `@layout` included, is a plain file name.
     */
    public static function isNamespaced(string $name): bool
    {
        return preg_match(self::NAMESPACED, $name) === 1;
    }

    /**
     * The file a namespaced name resolves to, or null when it is unsafe or missing.
     *
     * @param string $suffix Appended to the name once it is known to name a template.
     * @throws RenderException When the namespace is not declared.
     */
    public function resolve(string $name, string $suffix = ''): ?string
    {
        [$namespace, $rest] = $this->split($name);
        $this->assertDeclared($namespace);
        if ($rest === '') {
            return null;
        }
        $rest .= $suffix;

        foreach ([$this->overrideRoot . '/' . $namespace, $this->directories[$namespace]] as $root) {
            $path = SafePath::within($root, $rest);
            if ($path !== null && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Where a namespaced name would sit in its namespace directory, for error reporting only.
     */
    public function candidatePath(string $name, string $suffix = ''): string
    {
        [$namespace, $rest] = $this->split($name);

        return ($this->directories[$namespace] ?? '') . '/' . ltrim($rest, '/\\') . $suffix;
    }

    /**
     * The source of a template, namespaced or not.
     */
    public function getContent(string $name): string
    {
        return $this->files->getContent($this->locate($name));
    }

    /**
     * The file an include, layout, embed or import names: resolved for `@namespace/...`, else relative to the referrer.
     */
    public function getReferredName(string $name, string $referringName): string
    {
        return self::isNamespaced($name)
            ? $this->locate($name)
            : $this->files->getReferredName($name, $referringName);
    }

    /**
     * The cache identity of a template: its resolved real path.
     */
    public function getUniqueId(string $name): string
    {
        return $this->files->getUniqueId($this->locate($name));
    }

    private function locate(string $name): string
    {
        if (!self::isNamespaced($name)) {
            return $name;
        }

        return $this->resolve($name) ?? throw RenderException::templateNotFound($name, $this->candidatePath($name));
    }

    /**
     * @return array{string, string}
     */
    private function split(string $name): array
    {
        $parts = explode('/', substr($name, 1), 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    private function assertDeclared(string $namespace): void
    {
        if (!$this->isDeclared($namespace)) {
            throw RenderException::unknownTemplateNamespace(
                $namespace,
                array_map('strval', array_keys($this->directories)),
            );
        }
    }
}
