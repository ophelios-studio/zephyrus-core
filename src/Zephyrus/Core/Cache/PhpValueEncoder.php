<?php

declare(strict_types=1);

namespace Zephyrus\Core\Cache;

use Symfony\Component\Yaml\Tag\TaggedValue;

/**
 * Encodes a value as a PHP expression that rebuilds it when included. Never serialize() nor var_export() of an object.
 *
 * @internal
 */
final class PhpValueEncoder
{
    /**
     * @throws BootCacheException when the value holds an object other than a YAML TaggedValue, or a resource.
     */
    public static function encode(mixed $value): string
    {
        return self::encodeAt($value, '');
    }

    private static function encodeAt(mixed $value, string $keyPath): string
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return var_export($value, true);
        }

        if (is_array($value)) {
            $entries = [];
            foreach ($value as $key => $item) {
                $entries[] = var_export($key, true) . ' => ' . self::encodeAt($item, self::childPath($keyPath, $key));
            }

            return '[' . implode(', ', $entries) . ']';
        }

        if ($value instanceof TaggedValue) {
            return sprintf(
                'new \\%s(%s, %s)',
                TaggedValue::class,
                var_export($value->getTag(), true),
                self::encodeAt($value->getValue(), $keyPath),
            );
        }

        throw BootCacheException::unsupportedValue($keyPath, get_debug_type($value));
    }

    private static function childPath(string $keyPath, int|string $key): string
    {
        return $keyPath === '' ? (string) $key : $keyPath . '.' . $key;
    }
}
