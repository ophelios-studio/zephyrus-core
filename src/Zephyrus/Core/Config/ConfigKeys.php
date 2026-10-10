<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

/**
 * The properties a configuration section writes, read against the spellings each property accepts.
 *
 * A spelling such as 'csrf.enabled' names the key 'enabled' of the mapping written under 'csrf'.
 *
 * @internal
 */
final readonly class ConfigKeys
{
    private const int MAX_SUGGESTION_DISTANCE = 2;

    /** Longer keys get no suggestion, which bounds the cost of levenshtein(). */
    private const int MAX_COMPARED_LENGTH = 64;

    /**
     * @param array<string, array{string, mixed}> $written   Property => [key as written, value].
     * @param array<string, list<string>>         $spellings
     */
    private function __construct(
        private string $section,
        private array $written,
        private array $spellings,
    ) {
    }

    /**
     * Reads a section, refusing a key that no property accepts and a property written under two keys.
     *
     * A mapping name holding anything but an array is left to the caller.
     *
     * @param array<array-key, mixed>     $values
     * @param array<string, list<string>> $spellings Property => accepted keys, preferred first.
     * @throws ConfigurationException when a key is not accepted, or two keys of one property are written.
     */
    public static function read(string $section, #[\SensitiveParameter] array $values, array $spellings): self
    {
        self::assertKnown($section, $values, $spellings);

        $written = [];
        foreach ($spellings as $property => $paths) {
            foreach ($paths as $path) {
                $location = self::locate($values, $path);
                if ($location === null) {
                    continue;
                }

                if (isset($written[$property])) {
                    throw ConfigurationException::conflictingKeys($section, $written[$property][0], $path);
                }

                $written[$property] = [$path, $location[0]];
            }
        }

        return new self($section, $written, $spellings);
    }

    /**
     * Whether the property is written, even as null.
     */
    public function has(string $property): bool
    {
        return isset($this->written[$property]);
    }

    /**
     * The property's value, or null when it is not written.
     */
    public function value(string $property): mixed
    {
        return $this->written[$property][1] ?? null;
    }

    /**
     * The key the property is written under, or its preferred spelling when it is not written.
     */
    public function key(string $property): string
    {
        return $this->written[$property][0] ?? $this->spellings[$property][0];
    }

    /**
     * The property read as a boolean, or the default when it is not written.
     *
     * @param string $nullHint Appended to the refusal of a written null.
     * @throws ConfigurationException when the written value, null included, is not a recognisable boolean.
     */
    public function boolean(string $property, bool $default, string $nullHint = ''): bool
    {
        return $this->has($property)
            ? ConfigBoolean::parse($this->section, $this->key($property), $this->value($property), $nullHint)
            : $default;
    }

    /**
     * The properties written, even as null, in the order the spellings declare them.
     *
     * @return list<string>
     */
    public function properties(): array
    {
        return array_keys($this->written);
    }

    /**
     * @param array<array-key, mixed>     $values
     * @param array<string, list<string>> $spellings
     * @throws ConfigurationException when a key is not accepted.
     */
    private static function assertKnown(string $section, #[\SensitiveParameter] array $values, array $spellings): void
    {
        $levels = self::levels($spellings);
        $mappings = array_keys(array_diff_key($levels, ['' => true]));
        $preferred = array_map(static fn (array $paths): string => $paths[0], array_values($spellings));

        foreach ($values as $key => $value) {
            $key = (string) $key;
            if ($key !== '' && isset($levels[$key])) {
                foreach (is_array($value) ? array_keys($value) : [] as $nestedKey) {
                    $path = $key . '.' . $nestedKey;
                    if (!isset($levels[$key][$path])) {
                        $suggestion = self::closest($path, array_keys($levels[$key]));
                        throw ConfigurationException::unknownKey($section, $path, $suggestion, $preferred);
                    }
                }

                continue;
            }

            if (!isset($levels[''][$key])) {
                // A misspelled mapping name is compared with the section's own keys.
                $suggestion = self::closest($key, [...array_keys($levels[''] ?? []), ...$mappings]);
                throw ConfigurationException::unknownKey($section, $key, $suggestion, $preferred);
            }
        }
    }

    /**
     * The value written under a spelling, wrapped so that a written null differs from an absent key.
     *
     * @param array<array-key, mixed> $values
     * @return array{mixed}|null
     */
    private static function locate(#[\SensitiveParameter] array $values, string $path): ?array
    {
        if (!str_contains($path, '.')) {
            return array_key_exists($path, $values) ? [$values[$path]] : null;
        }

        [$mapping, $key] = explode('.', $path, 2);
        $nested = $values[$mapping] ?? null;

        return is_array($nested) && array_key_exists($key, $nested) ? [$nested[$key]] : null;
    }

    /**
     * The accepted keys of each mapping, '' being the section itself, with the property each one sets.
     *
     * @param array<string, list<string>> $spellings
     * @return array<string, array<string, string>> Mapping name => [path => property].
     */
    private static function levels(array $spellings): array
    {
        $levels = [];
        foreach ($spellings as $property => $paths) {
            foreach ($paths as $path) {
                $mapping = str_contains($path, '.') ? explode('.', $path, 2)[0] : '';
                $levels[$mapping][$path] = $property;
            }
        }

        return $levels;
    }

    /**
     * The candidate nearest to the key once case, underscores and hyphens are ignored, or null when none is near.
     * Ties go to the candidate written most like the key, then to the first one.
     *
     * @param list<string> $candidates
     */
    private static function closest(string $key, array $candidates): ?string
    {
        $folded = self::fold($key);
        if ($folded === '' || strlen($key) > self::MAX_COMPARED_LENGTH) {
            return null;
        }

        $closest = null;
        $closestDistance = PHP_INT_MAX;
        $closestStyleDistance = PHP_INT_MAX;
        foreach ($candidates as $candidate) {
            $foldedCandidate = self::fold($candidate);
            $distance = levenshtein($folded, $foldedCandidate);
            if ($distance > min(self::MAX_SUGGESTION_DISTANCE, intdiv(strlen($foldedCandidate), 2))) {
                continue;
            }

            $styleDistance = levenshtein(strtolower($key), strtolower($candidate));
            if ($distance < $closestDistance || ($distance === $closestDistance && $styleDistance < $closestStyleDistance)) {
                $closest = $candidate;
                $closestDistance = $distance;
                $closestStyleDistance = $styleDistance;
            }
        }

        return $closest;
    }

    private static function fold(string $key): string
    {
        return str_replace(['_', '-'], '', strtolower($key));
    }
}
