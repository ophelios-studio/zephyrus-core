<?php

declare(strict_types=1);

namespace Zephyrus\Rendering;

/**
 * Contract for template rendering engines.
 *
 * Implementations translate a page identifier (a relative path without
 * extension) and template variables into a rendered HTML string.
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
