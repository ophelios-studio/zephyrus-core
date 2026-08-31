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
 *       protected array $secretKeys = ['api.token'];
 *
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
 *
 * ## The typed getters REFUSE a value they cannot read
 *
 * They used to guess, and they guessed in the unsafe direction. getBool() ran
 * filter_var() without FILTER_NULL_ON_FAILURE, so every value the filter did
 * not recognise came back as FALSE and the caller's default was discarded on
 * the way past:
 *
 *   requireMfa: enabled   ->  getBool('requireMfa', true) === false
 *   requireMfa: oui       ->  false
 *   maxAttempts: unlimited->  getInt('maxAttempts', 5) === 0
 *
 * A protection an operator explicitly asked for therefore turned itself off,
 * quietly, on a typo, while the config file still read as if it were on. So an
 * unreadable value now throws ConfigurationException instead, and the process
 * stops at boot where somebody can see it.
 *
 * Two coercions are kept deliberately, because they are unambiguous and
 * because existing configuration relies on them: a float in an int slot
 * truncates (3.14 -> 3), and a scalar in a string slot is cast.
 */
abstract class ConfigSection
{
    /**
     * What toArray() substitutes for a secret it will not export.
     *
     * A distinctive literal on purpose: a reader must be able to tell "hidden"
     * from "empty" and from a value that merely looks masked.
     */
    public const string REDACTED = '[redacted]';

    /**
     * Dot-notation keys whose value toArray() must never export.
     *
     * Declared by the subclass, because only the subclass knows which of its
     * keys carry a secret:
     *
     *   protected array $secretKeys = ['smtp.password', 'api.token'];
     *
     * An empty or null value is left as-is: replacing it would make an
     * UNCONFIGURED secret look configured, which is its own trap.
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

    /**
     * @throws ConfigurationException when the value is not representable as a string.
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

        // An array used to become the literal string 'Array' plus a PHP
        // warning, which is a value no configuration ever meant.
        throw $this->rejected($key, $value, 'is not representable as a string');
    }

    /**
     * @throws ConfigurationException when the value is not an integer.
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

            // Kept: truncating a float is a defined, visible coercion, unlike
            // turning a word into zero.
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
     * @throws ConfigurationException when the value is not a number.
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
     * @throws ConfigurationException when the value is not a recognisable boolean.
     */
    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($parsed === null) {
            throw $this->rejected(
                $key,
                $value,
                "is not a boolean; use true/false, 1/0, on/off or yes/no. It used to resolve to "
                . 'FALSE and discard the caller default, so a protection asked for in the config '
                . 'file turned itself off',
            );
        }

        return $parsed;
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
     * Return the section values, with every declared secret redacted.
     *
     * This is what a debug panel, a diagnostic endpoint or a config dump
     * renders, so it defaults to the safe answer. Pass $revealSecrets only from
     * a caller that genuinely needs the values, and never from a rendering
     * path.
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
     * Each SEGMENT is normalized separately: normalizing the whole dotted
     * string would mangle a snake_case leaf ('api_key' inside 'smtp.api_key'
     * would come back capitalised).
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
     * The value IS named. A configuration section holds hosts, ports, paths and
     * feature switches; the one thing it holds that must never be echoed is a
     * secret, and a secret never reaches here, because a secret is read with
     * getString() and any string is already valid.
     */
    private function rejected(string $key, mixed $value, string $reason): ConfigurationException
    {
        return ConfigurationException::invalidValue(
            static::class,
            $key,
            is_scalar($value) ? (string) $value : get_debug_type($value),
            $reason,
        );
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
