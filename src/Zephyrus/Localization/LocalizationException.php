<?php

declare(strict_types=1);

namespace Zephyrus\Localization;

use Zephyrus\Exceptions\ZephyrusRuntimeException;

/**
 * Thrown when locale loading or parsing fails, or a translation pipe cannot be applied.
 *
 * Messages name the file by its last two segments ("fr/legal.json") and never the absolute path, since they
 * can reach logs or error pages. The full path is available through path().
 */
final class LocalizationException extends ZephyrusRuntimeException
{
    /** Absolute path, deliberately kept out of getMessage(). Null when none was recorded. */
    private ?string $path = null;

    public static function unreadableFile(string $path): self
    {
        return self::withPath(
            sprintf('Unable to read locale file "%s".', self::catalogRelativeName($path)),
            $path,
        );
    }

    /**
     * Keeps the JsonException message, which names the syntax error and never a path.
     */
    public static function invalidJson(string $path, ?\Throwable $previous = null): self
    {
        $message = sprintf('Invalid JSON in locale file "%s"', self::catalogRelativeName($path));
        if ($previous !== null) {
            $message .= ': ' . $previous->getMessage();
        }

        return self::withPath($message, $path, $previous);
    }

    /**
     * Names the pipe, the key and the valid pipes, never the value being rendered.
     *
     * @param list<string> $validPipes
     */
    public static function unknownPipe(string $pipe, string $key, array $validPipes): self
    {
        return new self(sprintf(
            'Unknown pipe "%s" in translation key "%s". Valid pipes: %s. Add a formatter with Formatter::register().',
            $pipe,
            $key,
            implode(', ', $validPipes),
        ));
    }

    /**
     * A formatter pipe was used while no Formatter is set in App.
     */
    public static function formatterRequired(string $pipe, string $key): self
    {
        return new self(sprintf(
            'Pipe "%s" in translation key "%s" needs a Formatter: call App::setFormatter() first.',
            $pipe,
            $key,
        ));
    }

    /**
     * Names the pipe and the key, never the value; the cause stays in the previous exception.
     */
    public static function formatterPipeFailed(string $pipe, string $key, \Throwable $previous): self
    {
        return new self(sprintf('Unable to apply pipe "%s" in translation key "%s".', $pipe, $key), previous: $previous);
    }

    public static function invalidFormat(string $path): self
    {
        return self::withPath(
            sprintf('Locale file "%s" must decode to an object.', self::catalogRelativeName($path)),
            $path,
        );
    }

    /**
     * Takes a directory name rather than a path, so path() stays null and the message never discloses the layout.
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
     * Shows the parent segment so "fr/legal.json" and "en/legal.json" differ; a root-level file gets no separator.
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
