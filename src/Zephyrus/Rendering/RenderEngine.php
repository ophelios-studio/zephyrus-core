<?php

declare(strict_types=1);

namespace Zephyrus\Rendering;

/**
 * Contract for template rendering engines.
 *
 * Implementations translate a page identifier (typically a relative path
 * without extension) and an associative array of template variables into a
 * rendered HTML string.
 *
 * Unlike Zephyrus v1 where engines output directly via echo/include, v2
 * engines return the rendered content as a string for cleaner composition
 * with the immutable Response value object.
 */
interface RenderEngine
{
    /**
     * Render the given page with the provided template variables.
     *
     * @param string              $page The page identifier (e.g. 'users/show').
     * @param array<string,mixed> $args Template variables.
     * @return string The rendered output.
     * @throws RenderException If the page cannot be found or rendered.
     */
    public function render(string $page, array $args = []): string;

    /**
     * Check whether the given page template exists.
     */
    public function exists(string $page): bool;
}
