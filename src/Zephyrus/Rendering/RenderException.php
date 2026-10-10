<?php

declare(strict_types=1);

namespace Zephyrus\Rendering;

use Zephyrus\Exceptions\ZephyrusRuntimeException;

/**
 * Thrown when template rendering fails.
 *
 * Covers missing templates, engine misconfiguration, and render-time errors.
 */
final class RenderException extends ZephyrusRuntimeException
{
    /**
     * The absolute path the page identifier resolved to, when there is one.
     */
    private ?string $resolvedPath = null;

    /**
     * The template could not be found on disk.
     *
     * The message carries only the page identifier, so logs and error pages do
     * not expose the filesystem layout. The path is available via resolvedPath().
     */
    public static function templateNotFound(string $page, string $resolvedPath): self
    {
        $exception = new self(sprintf('Template [%s] not found.', $page));
        $exception->resolvedPath = $resolvedPath;

        return $exception;
    }

    /**
     * The absolute path the missing template resolved to, or null when none was recorded.
     */
    public function resolvedPath(): ?string
    {
        return $this->resolvedPath;
    }

    /**
     * The template exists but rendering failed.
     *
     * The previous message is appended verbatim, so it may name a server path
     * when the engine or PHP reports one. It is kept on purpose: a render failure
     * without its cause cannot be diagnosed, unlike templateNotFound().
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
