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
     *
     * Deliberately NOT in getMessage(). See templateNotFound().
     */
    private ?string $resolvedPath = null;

    /**
     * The template could not be found on disk.
     *
     * ## The resolved path is context, not part of the sentence
     *
     * The message used to read 'Template [users/show] not found (resolved to
     * [/srv/app/views/users/show.latte]).' The message is the part that
     * TRAVELS: a log line, an alert email, a Tracy panel, an APM event,
     * occasionally a 500 page. Every one of those readers was handed the
     * deployment's filesystem layout, and none of them could do anything with
     * it, because the only actor who can fix a missing template is a developer
     * who already has the repository checked out.
     *
     * $page STAYS. It is a developer-authored identifier, not a filesystem
     * fact, and it is the entire diagnostic value of the sentence: it names the
     * render() call to go and look at.
     *
     * The path is not discarded, it is moved to resolvedPath(), so a caller
     * that genuinely needs it (a debug page that has already decided it is
     * allowed to show paths) asks for it explicitly instead of receiving it by
     * default. Same shape as LocalizationException::unreadableDirectory().
     */
    public static function templateNotFound(string $page, string $resolvedPath): self
    {
        $exception = new self(sprintf('Template [%s] not found.', $page));
        $exception->resolvedPath = $resolvedPath;

        return $exception;
    }

    /**
     * The absolute path the missing template resolved to, or null when this
     * exception carries no path.
     *
     * Null and '' are distinguishable on purpose: "no path was recorded" is a
     * different fact from "the path was empty".
     */
    public function resolvedPath(): ?string
    {
        return $this->resolvedPath;
    }

    /**
     * The template exists but rendering failed.
     *
     * ## The inherited message MAY carry a server path, and that is DELIBERATE
     *
     * $previous->getMessage() is appended verbatim, and a PHP include error or
     * an engine parse error routinely names the file. That is NOT the same
     * defect templateNotFound() had: there we FORMATTED a filesystem fact
     * ourselves, into a sentence that carried no other information. Here we are
     * preserving an upstream diagnostic, and a render failure with its cause
     * redacted is close to useless.
     *
     * Ruled and left as is. Do not "fix" it by stripping the previous message
     * without deciding what replaces the diagnostic.
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
