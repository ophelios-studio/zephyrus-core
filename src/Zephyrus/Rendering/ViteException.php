<?php

declare(strict_types=1);

namespace Zephyrus\Rendering;

use Zephyrus\Exceptions\ZephyrusRuntimeException;

final class ViteException extends ZephyrusRuntimeException
{
    /**
     * @param string[] $paths
     */
    public static function manifestNotFound(array $paths): self
    {
        return new self(sprintf(
            'Vite manifest not found. Checked: %s.',
            implode(', ', $paths),
        ));
    }

    public static function invalidManifest(string $path, ?\Throwable $previous = null): self
    {
        return new self(sprintf('Vite manifest [%s] is not readable or valid JSON.', $path), previous: $previous);
    }

    public static function entryNotFound(string $entry, string $path): self
    {
        return new self(sprintf('Vite entry [%s] was not found in manifest [%s].', $entry, $path));
    }

    public static function entryMissingFile(string $entry, string $path): self
    {
        return new self(sprintf('Vite entry [%s] in manifest [%s] does not define a file.', $entry, $path));
    }
}
