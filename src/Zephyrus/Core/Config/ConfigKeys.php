<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

/**
 * Checks the keys of a configuration section against the spellings its properties accept.
 *
 * A spelling such as 'csrf.enabled' names the key 'enabled' of the mapping written under 'csrf'.
 *
 * @internal
 */
final class ConfigKeys
{
    private const int MAX_SUGGESTION_DISTANCE = 2;

    /** Longer keys get no suggestion, which bounds the cost of levenshtein(). */
    private const int MAX_COMPARED_LENGTH = 64;

    /**
     * Refuses a key that no property accepts, suggesting the closest accepted spelling.
     *
     * A mapping name holding anything but an array is left to the caller.
     *
     * @param array<array-key, mixed>     $values
     * @param array<string, list<string>> $spellings Property => accepted keys, preferred first.
     * @throws ConfigurationException when a key is not accepted.
     */
    public static function assertKnown(string $section, array $values, array $spellings): void
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
                $mapping = str_contains($path, '.') ? strstr($path, '.', true) : '';
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
