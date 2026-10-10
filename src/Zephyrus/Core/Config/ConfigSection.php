<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

/**
 * Abstract base class for typed configuration sections, hydrated from a configuration array.
 *
 * Provides dot-notation access and typed getters. Example subclass:
 *
 *   class AppConfig extends ConfigSection
 *   {
 *       protected array $secretKeys = ['api.token'];
 *
 *       public readonly string $name;
 *       public readonly bool $maintenance;
 *
 *       public static function fromArray(array $values): static
 *       {
 *           $instance = new static($values);
 *           $instance->name = $instance->getString('name', 'MyApp');
 *           $instance->maintenance = $instance->getBool('maintenance', false);
 *           return $instance;
 *       }
 *   }
 *
 * The typed getters refuse a value they cannot read rather than guess. getBool()
 * accepts only true, false, 1, 0, on, off, yes and no (any case, surrounding
 * whitespace ignored), so a typo cannot switch a protection off.
 *
 * Two coercions are kept: a float in an int slot truncates (3.14 becomes 3), and a
 * scalar in a string slot is cast. A numeric string is never truncated: '3.5' in an
 * int slot is refused by getInt().
 */
abstract class ConfigSection
{
    /** Replacement written by toArray() for a declared secret, distinct from '' and null. */
    public const string REDACTED = '[redacted]';

    /**
     * Dot-notation keys whose value toArray() must never export, declared by the subclass:
     *
     *   protected array $secretKeys = ['smtp.password', 'api.token'];
     *
     * An empty or null value is left as-is, so an unconfigured secret stays visibly unset.
     *
     * @var list<string>
     */
    protected array $secretKeys = [];

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
     * Build a section from its raw configuration array.
     *
     * Concrete, not abstract: subclasses that only use the typed getters do not declare it.
     * A subclass keeps the constructor signature, because `new static($values)` relies on it;
     * a subclass hydrating typed properties overrides this method.
     *
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): static
    {
        return new static($values);
    }

    /**
     * Get a configuration value by key with an optional default.
     * Supports dot-notation for nested access (e.g. 'smtp.host').
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $normalized = self::normalizeKey($key);

        if (array_key_exists($normalized, $this->values)) {
            return $this->values[$normalized];
        }

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

    /**
     * @throws ConfigurationException when the value is an array, or an object without __toString().
     */
    public function getString(string $key, string $default = ''): string
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        // Arrays are refused: casting one yields 'Array' plus a warning.
        throw $this->rejected($key, $value, 'is not representable as a string');
    }

    /**
     * @throws ConfigurationException when the value is not an integer, or is a NaN or infinite float.
     */
    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                throw $this->rejected($key, $value, 'is not a finite number');
            }

            // Truncation is visible and defined; a word is never read as zero.
            return (int) $value;
        }

        if (is_string($value)) {
            $parsed = filter_var(trim($value), FILTER_VALIDATE_INT);
            if ($parsed !== false) {
                return $parsed;
            }
        }

        throw $this->rejected($key, $value, 'is not an integer');
    }

    /**
     * @throws ConfigurationException when the value is not a number, or is a NaN or infinite float.
     */
    public function getFloat(string $key, float $default = 0.0): float
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                throw $this->rejected($key, $value, 'is not a finite number');
            }

            return $value;
        }

        if (is_string($value)) {
            $parsed = filter_var(trim($value), FILTER_VALIDATE_FLOAT);
            if ($parsed !== false) {
                return $parsed;
            }
        }

        throw $this->rejected($key, $value, 'is not a number');
    }

    /**
     * @throws ConfigurationException when the value is not one of the accepted boolean spellings.
     */
    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        return ConfigBoolean::parse(static::class, $key, $value);
    }

    /**
     * Return the value as an array, or $default when it is absent or not an array.
     *
     * @return array<mixed>
     */
    public function getArray(string $key, array $default = []): array
    {
        $value = $this->get($key);
        return is_array($value) ? $value : $default;
    }

    /**
     * Whether the key is set, even when its value is an explicit null.
     */
    public function has(string $key): bool
    {
        $absent = new \stdClass();

        return $this->get($key, $absent) !== $absent;
    }

    /**
     * Return the values with every declared secret replaced by REDACTED.
     *
     * Pass $revealSecrets only where the raw value is needed, never from a rendering path
     * such as a debug panel or a config dump.
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $revealSecrets = false): array
    {
        if ($revealSecrets || $this->secretKeys === []) {
            return $this->values;
        }

        $redacted = $this->values;

        foreach ($this->secretKeys as $secretKey) {
            self::redactKey($redacted, (string) $secretKey);
        }

        return $redacted;
    }

    /**
     * Replace one dot-notation key's value with REDACTED, in place.
     *
     * @param array<string, mixed> $values
     */
    private static function redactKey(array &$values, string $key): void
    {
        $segments = array_map(
            static fn (string $segment): string => self::normalizeKey($segment),
            explode('.', $key),
        );

        $last = array_key_last($segments);
        $cursor = &$values;

        foreach ($segments as $index => $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return;
            }

            if ($index === $last) {
                // An absent secret must stay visibly absent.
                if ($cursor[$segment] !== null && $cursor[$segment] !== '') {
                    $cursor[$segment] = self::REDACTED;
                }

                return;
            }

            $cursor = &$cursor[$segment];
        }
    }

    /**
     * Build the refusal for a value this section cannot read.
     *
     * The refused value is echoed in the message, so read secrets with getString(),
     * which never refuses a string.
     */
    private function rejected(string $key, mixed $value, string $reason): ConfigurationException
    {
        return ConfigurationException::invalidValue(
            static::class,
            $key,
            $value,
            $reason,
        );
    }

    /**
     * Normalize keys to camelCase, recursively.
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
