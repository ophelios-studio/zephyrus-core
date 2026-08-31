<?php

declare(strict_types=1);

namespace Zephyrus\Localization;

use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use UnexpectedValueException;

/**
 * Loads locale catalogs from JSON files.
 *
 * Supports two modes:
 *
 * 1. **Directory mode** (preferred): Given a base path and locale "en", if a
 *    directory `{basePath}/en/` exists, all `*.json` files inside it (including
 *    subdirectories) are recursively discovered and merged into a single nested
 *    array using `array_replace_recursive`. This allows splitting translations
 *    across multiple files for organization:
 *
 *      locale/en/
 *        strings.json      {"welcome": {"title": "Hello"}}
 *        errors.json       {"errors": {"required": "Required"}}
 *        admin/users.json  {"admin": {"users": {"title": "Users"}}}
 *
 * 2. **Single-file mode** (backward compat): If no directory exists, falls back
 *    to looking for `{basePath}/{locale}.json` as a single file.
 *
 * The returned catalog is a **nested associative array** (not flattened).
 * The Translator resolves dot-notation keys at lookup time by traversing
 * the nesting levels.
 *
 * ## Path safety
 * The locale is concatenated into a filesystem path, so it is treated as
 * untrusted input regardless of where it came from. Two independent guards
 * are enforced here, in the loader itself, because `load()` is public API a
 * consumer may call directly without ever going through a locale resolver:
 *
 * 1. The locale must match {@see self::LOCALE_PATTERN}, a BCP-47-shaped tag.
 *    That shape admits no separator, no dot and no null byte, so no traversal
 *    sequence can survive it.
 * 2. The resolved directory or file must still sit under `$basePath` once
 *    `realpath()` has collapsed every symbolic link.
 *
 * A locale failing either guard yields an empty catalog, which is exactly the
 * behaviour of a locale that has no catalog on disk.
 */
final class JsonLocaleLoader implements LocaleLoaderInterface
{
    /**
     * BCP-47-shaped locale tag: a 2-3 letter language, then any number of
     * alphanumeric subtags separated by "-" or "_".
     *
     * Both separators are accepted because {@see self::localeCandidates()}
     * documents and supports underscore-named catalog directories, so an
     * application may legitimately configure "fr_CA".
     */
    private const string LOCALE_PATTERN = '/^[A-Za-z]{2,3}([-_][A-Za-z0-9]{2,8})*$/';

    public function __construct(
        private readonly string $basePath,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function load(string $locale): array
    {
        if (!self::isWellFormedLocale($locale)) {
            return [];
        }

        $base = rtrim($this->basePath, DIRECTORY_SEPARATOR);
        $candidates = $this->localeCandidates($locale);

        // 1. Try directory mode: {basePath}/{locale}/
        foreach ($candidates as $candidate) {
            $dir = $base . DIRECTORY_SEPARATOR . $candidate;
            if (is_dir($dir) && $this->isContainedIn($dir, $base)) {
                return $this->loadDirectory($dir);
            }
        }

        // 2. Fall back to single-file mode: {basePath}/{locale}.json
        foreach ($candidates as $candidate) {
            $file = $base . DIRECTORY_SEPARATOR . $candidate . '.json';
            if (is_file($file) && $this->isContainedIn($file, $base)) {
                return $this->loadFile($file);
            }
        }

        return [];
    }

    /**
     * Whether a locale tag is shaped like a language tag and therefore safe to
     * concatenate into a filesystem path.
     */
    public static function isWellFormedLocale(string $locale): bool
    {
        return preg_match(self::LOCALE_PATTERN, trim($locale)) === 1;
    }

    /**
     * Whether `$path` still resolves inside `$base` after symbolic links are
     * collapsed. A path that cannot be resolved at all is never contained.
     */
    private function isContainedIn(string $path, string $base): bool
    {
        $realBase = realpath($base);
        $realPath = realpath($path);

        if ($realBase === false || $realPath === false) {
            return false;
        }

        $realBase = rtrim($realBase, DIRECTORY_SEPARATOR);

        return str_starts_with($realPath, $realBase . DIRECTORY_SEPARATOR);
    }

    /**
     * Recursively scan a directory for *.json files and merge them.
     *
     * Files are sorted alphabetically for deterministic merge order.
     * Later files (alphabetically) override earlier files when keys conflict.
     *
     * @return array<string, mixed>
     */
    private function loadDirectory(string $directory): array
    {
        $merged = [];
        $files = $this->findJsonFiles($directory);
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            $decoded = $this->loadFile($file);
            $merged = array_replace_recursive($merged, $decoded);
        }

        return $merged;
    }

    /**
     * Load and decode a single JSON file.
     *
     * @return array<string, mixed>
     */
    private function loadFile(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw LocalizationException::unreadableFile($path);
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw LocalizationException::invalidJson($path, $exception);
        }

        if (!is_array($decoded)) {
            throw LocalizationException::invalidFormat($path);
        }

        return $decoded;
    }

    /**
     * Recursively find all *.json files in a directory.
     *
     * @return string[]
     */
    private function findJsonFiles(string $directory): array
    {
        $files = [];

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );

            /** @var \SplFileInfo $fileInfo */
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'json') {
                    $files[] = $fileInfo->getRealPath();
                }
            }
        } catch (UnexpectedValueException $exception) {
            // The SPL iterators embed the absolute server path in their message.
            // Wrap so the catalog directory name is reported and the full path
            // stays in the previous exception rather than in the message.
            throw LocalizationException::unreadableDirectory(basename($directory), $exception);
        }

        return $files;
    }

    /**
     * Build locale directory/file name candidates.
     *
     * For "fr-CA" this returns variants such as:
     *   ["fr-CA", "fr_CA", "fr-ca", "fr_ca"]
     * so projects can use either canonical or lowercase region casing.
     * For "en" this returns ["en"].
     *
     * @return string[]
     */
    private function localeCandidates(string $locale): array
    {
        $locale = trim($locale);
        if ($locale === '') {
            return [];
        }

        $candidates = [$locale];

        if (str_contains($locale, '-')) {
            [$language, $region] = array_pad(explode('-', $locale, 2), 2, '');
            $languageLower = strtolower($language);
            $regionUpper = strtoupper($region);
            $regionLower = strtolower($region);

            $canonical = $region === '' ? $languageLower : $languageLower . '-' . $regionUpper;
            $canonicalLowerRegion = $region === '' ? $languageLower : $languageLower . '-' . $regionLower;

            $candidates[] = $canonical;
            $candidates[] = str_replace('-', '_', $canonical);
            $candidates[] = $canonicalLowerRegion;
            $candidates[] = str_replace('-', '_', $canonicalLowerRegion);
        }

        return array_values(array_unique($candidates));
    }
}
