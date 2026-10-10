<?php

declare(strict_types=1);

namespace Zephyrus\Localization;

use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use UnexpectedValueException;

/**
 * Loads locale catalogs from JSON files as a nested (not flattened) array.
 *
 * For locale "en", every *.json file under {basePath}/en/ is read recursively and merged in alphabetical order,
 * later files winning on conflicting keys. Without that directory, the single file {basePath}/en.json is read.
 * A missing catalog yields an empty array.
 *
 * Path safety: the locale becomes part of a filesystem path, so load() checks it even when called directly.
 * The locale must match LOCALE_PATTERN (no separator, dot or NUL byte), and the resolved path must stay under
 * $basePath after symbolic links are collapsed. A locale failing either check yields an empty catalog.
 */
final class JsonLocaleLoader implements LocaleLoaderInterface
{
    /**
     * BCP-47-shaped tag: a 2-3 letter language, then alphanumeric subtags separated by "-" or "_".
     * The underscore stays because catalog directories may be named like fr_CA (see localeCandidates()).
     */
    private const string LOCALE_PATTERN = '/^[A-Za-z]{2,3}([-_][A-Za-z0-9]{2,8})*$/';

    public function __construct(
        private readonly string $basePath,
    ) {
    }

    /**
     * Returns the catalog for $locale, or an empty array when none exists or the locale is refused.
     *
     * @return array<string, mixed>
     *
     * @throws LocalizationException When a catalog file or directory is unreadable, not valid JSON, or does not
     *                               decode to a JSON object or array.
     */
    public function load(string $locale): array
    {
        if (!self::isWellFormedLocale($locale)) {
            return [];
        }

        $base = rtrim($this->basePath, DIRECTORY_SEPARATOR);
        $candidates = $this->localeCandidates($locale);

        foreach ($candidates as $candidate) {
            $dir = $base . DIRECTORY_SEPARATOR . $candidate;
            if (is_dir($dir) && $this->isContainedIn($dir, $base)) {
                return $this->loadDirectory($dir);
            }
        }

        foreach ($candidates as $candidate) {
            $file = $base . DIRECTORY_SEPARATOR . $candidate . '.json';
            if (is_file($file) && $this->isContainedIn($file, $base)) {
                return $this->loadFile($file);
            }
        }

        return [];
    }

    /**
     * Whether $locale is a BCP-47-shaped tag, safe to concatenate into a file path.
     */
    public static function isWellFormedLocale(string $locale): bool
    {
        return preg_match(self::LOCALE_PATTERN, trim($locale)) === 1;
    }

    /**
     * Whether $path still resolves inside $base after symbolic links are collapsed. An unresolvable path is never contained.
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
            // The SPL message embeds the absolute path; only the directory name is reported.
            throw LocalizationException::unreadableDirectory(basename($directory), $exception);
        }

        return $files;
    }

    /**
     * Directory and file name variants of a tag, e.g. "fr-CA" gives ["fr-CA", "fr_CA", "fr-ca", "fr_ca"].
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
