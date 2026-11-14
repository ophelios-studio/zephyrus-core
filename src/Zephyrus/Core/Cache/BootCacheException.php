<?php

declare(strict_types=1);

namespace Zephyrus\Core\Cache;

use Zephyrus\Exceptions\ZephyrusException;

/**
 * A boot cache could not be written or removed. Raised by cache:warm and cache:clear, never at runtime.
 *
 * @internal
 */
final class BootCacheException extends ZephyrusException
{
    public static function refusedWrite(string $target, string $reason): self
    {
        return new self(sprintf('Cannot write the boot cache %s: %s.', $target, $reason));
    }

    public static function refusedRemoval(string $target, string $reason): self
    {
        return new self(sprintf('Cannot remove the boot cache %s: %s.', $target, $reason));
    }

    public static function unsupportedValue(string $keyPath, string $type): self
    {
        return new self(sprintf(
            'Cannot cache the value at %s: %s is not supported, only null, booleans, numbers, strings, arrays and YAML tags are.',
            $keyPath === '' ? 'the top level' : $keyPath,
            $type,
        ));
    }
}
