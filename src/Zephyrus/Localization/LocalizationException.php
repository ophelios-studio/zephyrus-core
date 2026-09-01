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
 * ## Why the LAST TWO segments and not just the basename
 *
 * A catalog is nested as locale/<tag>/<file>.json, so a basename alone made
 * fr/legal.json and en/legal.json produce the IDENTICAL sentence, and the
 * locale tag is the single most useful disambiguator when a translation file
 * fails to parse. Naming the parent segment is not an invented convention, it
 * is the catalog's own structure, and a directory NAME is not a server path:
 * nothing above the catalog is disclosed. The accessor still carries the full
 * path for a caller that needs it.
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
            sprintf('Unable to read locale file "%s".', self::catalogRelativeName($path)),
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
        $message = sprintf('Invalid JSON in locale file "%s"', self::catalogRelativeName($path));
        if ($previous !== null) {
            $message .= ': ' . $previous->getMessage();
        }

        return self::withPath($message, $path, $previous);
    }

    public static function invalidFormat(string $path): self
    {
        return self::withPath(
            sprintf('Locale file "%s" must decode to an object.', self::catalogRelativeName($path)),
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

    /**
     * Name a locale file by its last two path segments ("fr/legal.json").
     *
     * Falls back to the file alone when there is no parent segment to show, so
     * a bare "en.json" or a root-level "/en.json" never grows a stray
     * separator. dirname() answers "." for the first and "/" (whose basename is
     * "") for the second.
     */
    private static function catalogRelativeName(string $path): string
    {
        $file = basename($path);
        $parent = basename(dirname($path));

        if ($parent === '' || $parent === '.' || $parent === DIRECTORY_SEPARATOR) {
            return $file;
        }

        return $parent . '/' . $file;
    }

    private static function withPath(string $message, string $path, ?\Throwable $previous = null): self
    {
        $exception = new self($message, previous: $previous);
        $exception->path = $path;

        return $exception;
    }
}
