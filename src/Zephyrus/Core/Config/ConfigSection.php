<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

/**
 * Abstract base class for typed configuration sections.
 *
 * Extend this class to create custom configuration sections that can be
 * hydrated from YAML config arrays. Provides dot-notation access and type
 * coercion helpers.
 *
 * Usage in a project:
 *
 *   class AppConfig extends ConfigSection
 *   {
 *       public readonly string $name;
 *       public readonly bool $maintenance;
 *
 *       public static function fromArray(array $values): static
 *       {
 *           $instance = new static($values);
 *           // Hydrate your own properties:
 *           $instance->name = $instance->getString('name', 'MyApp');
 *           $instance->maintenance = $instance->getBool('maintenance', false);
 *           return $instance;
 *       }
 *   }
 */
abstract class ConfigSection
{
    /** @var array<string, mixed> */
    protected array $values = [];

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(array $values = [])
    {
        $this->values = self::normalizeKeys($values);
    }

    /**
     * Get a configuration value by key with an optional default.
     * Supports dot-notation for nested access (e.g. 'smtp.host').
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $normalized = self::normalizeKey($key);

        // Direct key lookup first.
        if (array_key_exists($normalized, $this->values)) {
            return $this->values[$normalized];
        }

        // Dot-notation traversal.
        if (str_contains($normalized, '.')) {
            $segments = explode('.', $normalized);
            $current = $this->values;

            foreach ($segments as $segment) {
                if (!is_array($current) || !array_key_exists($segment, $current)) {
                    return $default;
                }
                $current = $current[$segment];
            }

            return $current;
        }

        return $default;
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->get($key);
        return $value !== null ? (string) $value : $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);
        return $value !== null ? (int) $value : $default;
    }

    public function getFloat(string $key, float $default = 0.0): float
    {
        $value = $this->get($key);
        return $value !== null ? (float) $value : $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /**
     * @return array<mixed>
     */
    public function getArray(string $key, array $default = []): array
    {
        $value = $this->get($key);
        return is_array($value) ? $value : $default;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Return the raw values array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * Normalize all keys in an array to camelCase for uniform access.
     * Supports both snake_case and camelCase keys.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    protected static function normalizeKeys(array $values): array
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            $normalizedKey = self::normalizeKey((string) $key);
            $normalized[$normalizedKey] = is_array($value) ? self::normalizeKeys($value) : $value;
        }
        return $normalized;
    }

    /**
     * Normalize a key from snake_case to camelCase.
     */
    protected static function normalizeKey(string $key): string
    {
        if (!str_contains($key, '_')) {
            return $key;
        }

        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
    }
}
