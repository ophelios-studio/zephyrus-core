<?php

declare(strict_types=1);

namespace Zephyrus\Rendering;

use Zephyrus\FileSystem\SafePath;

/**
 * Plain PHP template rendering engine.
 *
 * Templates are `.php` files that receive variables via `extract()`. The page
 * identifier is a relative path without extension: `render('users/show')`
 * includes `{directory}/users/show.php`.
 *
 * ## This engine does not escape anything
 * A variable printed with a bare `<?= $value ?>` reaches the response as raw
 * HTML. Pass every value written into HTML through the global e() helper:
 *
 *   <p><?= e($user->displayName) ?></p>
 *
 * ## Path safety
 * The page identifier is `include`d, so it is untrusted. A page containing a
 * `..` segment or a null byte is refused, and the resolved file must sit under
 * the template directory after `realpath()`. `exists()` reports false for such
 * a page.
 */
final class PhpEngine implements RenderEngine
{
    private string $directory;
    private string $extension;

    /**
     * @param string $directory Absolute path to the template directory.
     * @param string $extension File extension including the leading dot (default '.php').
     */
    public function __construct(string $directory, string $extension = '.php')
    {
        $this->directory = rtrim($directory, '/\\');
        $this->extension = $extension;
    }

    public function render(string $page, array $args = []): string
    {
        $path = $this->resolvePath($page);

        if ($path === null) {
            throw RenderException::templateNotFound($page, $this->candidatePath($page));
        }

        try {
            return $this->capture($path, $args);
        } catch (\Throwable $e) {
            throw RenderException::renderFailed($page, $e);
        }
    }

    public function exists(string $page): bool
    {
        return $this->resolvePath($page) !== null;
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
        $path = SafePath::within($this->directory, $page . $this->extension);

        return $path !== null && is_file($path) ? $path : null;
    }

    /**
     * The path a page identifier would resolve to, for error reporting only.
     */
    private function candidatePath(string $page): string
    {
        return $this->directory . '/' . ltrim($page, '/\\') . $this->extension;
    }

    /**
     * Extract variables and capture the template output.
     *
     * @param string              $__path__ The absolute path to the template.
     * @param array<string,mixed> $__args__ Template variables.
     */
    private function capture(string $__path__, array $__args__): string
    {
        $renderer = static function (string $__path__, array $__args__): string {
            extract($__args__, EXTR_SKIP);
            ob_start();

            try {
                include $__path__;
                return (string) ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
        };

        return $renderer($__path__, $__args__);
    }
}
