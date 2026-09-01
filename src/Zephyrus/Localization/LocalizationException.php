<?php

declare(strict_types=1);

namespace Zephyrus\Localization;

use Zephyrus\Exceptions\ZephyrusRuntimeException;

/**
 * Thrown when locale file loading or parsing fails.
 *
 * ## The absolute server path is CONTEXT, never part of the sentence
 *
 * Every factory here used to interpolate the full path it was handed. The
 * message is the part that TRAVELS: a log line, an alert email, a Tracy panel,
 * an APM event, sometimes a 500 page. Worse than most, locale loading runs at
 * BOOT, before the kernel's error handling exists, so these messages are among
 * the likeliest in the whole framework to land raw in front of somebody. They
 * disclosed the deployment's filesystem layout to every one of those readers
 * for nothing, because the only actor who can fix a broken locale file is a
 * developer who already has the repository.
 *
 * The file NAME stays, because that is the diagnostic. The path moved to
 * path(), so a caller that genuinely needs it asks instead of receiving it by
 * default. Same shape as RenderException::templateNotFound().
 *
 * A catalog is nested (locale/fr/legal.json), so basename() alone can be
 * ambiguous across locales. That is exactly why the accessor exists rather than
 * the path simply being dropped.
 */
final class LocalizationException extends ZephyrusRuntimeException
{
    /**
     * The absolute path this exception is about, when it was given one.
     *
     * Deliberately NOT in getMessage(). Null rather than '' when there is no
     * path, so "none recorded" stays distinguishable from "it was empty".
     */
    private ?string $path = null;

    public static function unreadableFile(string $path): self
    {
        return self::withPath(
            sprintf('Unable to read locale file "%s".', basename($path)),
            $path,
        );
    }

    /**
     * The JsonException message IS kept. It reads "Syntax error" or "Control
     * character error", never a path, and it is the entire reason a developer
     * reads this line.
     */
    public static function invalidJson(string $path, ?\Throwable $previous = null): self
    {
        $message = sprintf('Invalid JSON in locale file "%s"', basename($path));
        if ($previous !== null) {
            $message .= ': ' . $previous->getMessage();
        }

        return self::withPath($message, $path, $previous);
    }

    public static function invalidFormat(string $path): self
    {
        return self::withPath(
            sprintf('Locale file "%s" must decode to an object.', basename($path)),
            $path,
        );
    }

    /**
     * A catalog directory exists but could not be opened or traversed.
     *
     * The absolute server path is deliberately kept out of the message and left
     * in the previous exception, so a leaked message cannot disclose the
     * deployment layout. This factory receives a NAME, not a path, so path()
     * stays null for it.
     */
    public static function unreadableDirectory(string $name, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('Unable to read locale catalog directory "%s".', $name),
            previous: $previous,
        );
    }

    /**
     * The absolute path this exception is about, or null when it carries none.
     */
    public function path(): ?string
    {
        return $this->path;
    }

    private static function withPath(string $message, string $path, ?\Throwable $previous = null): self
    {
        $exception = new self($message, previous: $previous);
        $exception->path = $path;

        return $exception;
    }
}
