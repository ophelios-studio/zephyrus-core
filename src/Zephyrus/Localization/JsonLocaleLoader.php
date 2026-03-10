<?php

declare(strict_types=1);

namespace Zephyrus\Localization;

use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

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
 */
final class JsonLocaleLoader implements LocaleLoaderInterface
{
    public function __construct(
        private readonly string $basePath,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function load(string $locale): array
    {
        $base = rtrim($this->basePath, DIRECTORY_SEPARATOR);
        $candidates = $this->localeCandidates($locale);

        // 1. Try directory mode: {basePath}/{locale}/
        foreach ($candidates as $candidate) {
            $dir = $base . DIRECTORY_SEPARATOR . $candidate;
            if (is_dir($dir)) {
                return $this->loadDirectory($dir);
            }
        }

        // 2. Fall back to single-file mode: {basePath}/{locale}.json
        foreach ($candidates as $candidate) {
            $file = $base . DIRECTORY_SEPARATOR . $candidate . '.json';
            if (is_file($file)) {
                return $this->loadFile($file);
            }
        }

        return [];
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

        return $files;
    }

    /**
     * Build locale directory/file name candidates.
     *
     * For "fr-CA" this returns ["fr-CA", "fr_CA"].
     * For "en" this returns ["en"].
     *
     * @return string[]
     */
    private function localeCandidates(string $locale): array
    {
        $candidates = [$locale];
        if (str_contains($locale, '-')) {
            $candidates[] = str_replace('-', '_', $locale);
        }
        return array_values(array_unique($candidates));
    }
}
