<?php

declare(strict_types=1);

namespace Zephyrus\Rendering;

use Zephyrus\Exceptions\ZephyrusRuntimeException;

/**
 * Thrown when template rendering fails.
 *
 * Covers missing templates, engine misconfiguration, and render-time errors.
 */
class RenderException extends ZephyrusRuntimeException
{
    /**
     * The template could not be found on disk.
     */
    public static function templateNotFound(string $page, string $resolvedPath): self
    {
        return new self(sprintf(
            'Template [%s] not found (resolved to [%s]).',
            $page,
            $resolvedPath,
        ));
    }

    /**
     * The template exists but rendering failed.
     */
    public static function renderFailed(string $page, \Throwable $previous): self
    {
        return new self(
            sprintf('Failed to render template [%s]: %s', $page, $previous->getMessage()),
            previous: $previous,
        );
    }

    /**
     * The rendering engine is misconfigured.
     */
    public static function engineError(string $message, ?\Throwable $previous = null): self
    {
        return new self($message, previous: $previous);
    }
}
