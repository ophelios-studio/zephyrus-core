<?php

declare(strict_types=1);

namespace Zephyrus\Rendering;

use Zephyrus\Core\Config\Environment;

final class Vite
{
    public const DEFAULT_ENTRY = 'app/Views/app.js';
    public const DEFAULT_BUILD_DIRECTORY = 'build';
    public const DEFAULT_DEV_SERVER = 'http://localhost:5173';

    /**
     * Render Vite script and stylesheet tags for one or more entrypoints.
     *
     * Development output includes the Vite client and one module script tag per
     * entrypoint, using the dev server URL. Production output reads the Vite
     * manifest and emits stylesheet tags followed by module script tags for the
     * hashed build assets.
     *
     * Supported options, with snake_case and camelCase aliases:
     * - environment: Environment|string
     *   Current application environment. Development values are "dev",
     *   "development", and "local". Environment::Development is also accepted.
     *   When omitted, the value is resolved from config('application',
     *   'environment'), then APP_ENV, then defaults to production.
     *
     * - dev_server / devServer: string
     *   Vite dev server base URL used in development. When omitted, the value
     *   is resolved from VITE_DEV_SERVER, then VITE_DEV_SERVER_URL, then
     *   defaults to "http://localhost:5173".
     *
     * - public_directory / publicDirectory: string
     *   Absolute path to the public directory used to find the production
     *   manifest. When omitted, the value is resolved from VITE_PUBLIC_DIRECTORY,
     *   then VITE_PUBLIC_DIR, then defaults to getcwd() . "/public".
     *
     * - build_directory / buildDirectory: string
     *   Build directory relative to the public directory. When omitted, the
     *   value is resolved from VITE_BUILD_DIRECTORY, then VITE_BUILD_DIR, then
     *   defaults to "build".
     *
     * - manifest_path / manifestPath: string
     *   Explicit absolute path to the Vite manifest. When omitted, production
     *   checks "<public_directory>/<build_directory>/manifest.json" and then
     *   "<public_directory>/<build_directory>/.vite/manifest.json".
     *
     * - asset_url / assetUrl: string
     *   Public URL prefix used for production asset URLs. When omitted, the
     *   value is resolved from VITE_ASSET_URL, then defaults to
     *   "/" . build_directory.
     *
     * Example:
     *   Vite::render('resources/js/app.js', [
     *       'environment' => 'production',
     *       'public_directory' => __DIR__ . '/../public',
     *       'build_directory' => 'build',
     *   ]);
     *
     * @param string|string[]       $entry
     * @param array<string, mixed>  $options
     */
    public static function render(string|array $entry = self::DEFAULT_ENTRY, array $options = []): string
    {
        $entries = self::normalizeEntries($entry);
        if ($entries === []) {
            return '';
        }

        if (self::isDevelopment($options)) {
            return self::renderDevelopment($entries, $options);
        }

        return self::renderProduction($entries, $options);
    }

    /**
     * @param string[]             $entries
     * @param array<string, mixed> $options
     */
    private static function renderDevelopment(array $entries, array $options): string
    {
        $devServer = self::stringOption($options, 'dev_server', 'devServer')
            ?? self::firstEnvironmentValue(['VITE_DEV_SERVER', 'VITE_DEV_SERVER_URL'])
            ?? self::DEFAULT_DEV_SERVER;

        $devServer = rtrim($devServer, '/');

        $tags = [
            self::scriptTag(self::joinUrl($devServer, '@vite/client')),
        ];

        foreach ($entries as $entry) {
            $tags[] = self::scriptTag(self::joinUrl($devServer, $entry));
        }

        return implode("\n", $tags);
    }

    /**
     * @param string[]             $entries
     * @param array<string, mixed> $options
     */
    private static function renderProduction(array $entries, array $options): string
    {
        [$manifestPath, $checkedPaths] = self::resolveManifestPath($options);
        if ($manifestPath === null) {
            throw ViteException::manifestNotFound($checkedPaths);
        }

        $manifest = self::readManifest($manifestPath);
        $assetUrl = self::assetUrlBase($options);

        $tags = [];
        $loadedCss = [];
        $loadedScripts = [];

        foreach ($entries as $entry) {
            $chunk = self::findChunk($manifest, $entry, $manifestPath);

            foreach (self::collectCss($manifest, $chunk) as $cssFile) {
                $url = self::joinUrl($assetUrl, $cssFile);
                if (isset($loadedCss[$url])) {
                    continue;
                }
                $loadedCss[$url] = true;
                $tags[] = self::stylesheetTag($url);
            }

            $file = $chunk['file'] ?? null;
            if (!is_string($file) || $file === '') {
                throw ViteException::entryMissingFile($entry, $manifestPath);
            }

            $url = self::joinUrl($assetUrl, $file);
            if (isset($loadedScripts[$url])) {
                continue;
            }
            $loadedScripts[$url] = true;
            $tags[] = self::scriptTag($url);
        }

        return implode("\n", $tags);
    }

    /**
     * @param string|array<mixed> $entry
     * @return string[]
     */
    private static function normalizeEntries(string|array $entry): array
    {
        $entries = is_array($entry) ? $entry : [$entry];
        $normalized = [];

        foreach ($entries as $value) {
            if (!is_string($value)) {
                continue;
            }

            $value = trim($value);
            if ($value === '') {
                continue;
            }

            $normalized[] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function isDevelopment(array $options): bool
    {
        $environment = self::option($options, 'environment', 'environment');

        if ($environment === null && function_exists('config')) {
            $environment = \config('application', 'environment');
        }

        if ($environment === null && function_exists('env')) {
            $environment = \env('APP_ENV');
        }

        if ($environment === null) {
            $environment = self::firstEnvironmentValue(['APP_ENV']) ?? Environment::Production;
        }

        if ($environment instanceof Environment) {
            return $environment === Environment::Development;
        }

        return in_array(strtolower(trim((string) $environment)), ['dev', 'development', 'local'], true);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{0: string|null, 1: string[]}
     */
    private static function resolveManifestPath(array $options): array
    {
        $explicitManifest = self::stringOption($options, 'manifest_path', 'manifestPath');
        if ($explicitManifest !== null) {
            return is_file($explicitManifest)
                ? [$explicitManifest, [$explicitManifest]]
                : [null, [$explicitManifest]];
        }

        $publicDirectory = self::publicDirectory($options);
        $buildDirectory = self::buildDirectory($options);
        $buildPath = rtrim($publicDirectory, '/\\') . '/' . $buildDirectory;

        $paths = [
            $buildPath . '/manifest.json',
            $buildPath . '/.vite/manifest.json',
        ];

        foreach ($paths as $path) {
            if (is_file($path)) {
                return [$path, $paths];
            }
        }

        return [null, $paths];
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function publicDirectory(array $options): string
    {
        $explicit = self::stringOption($options, 'public_directory', 'publicDirectory')
            ?? self::firstEnvironmentValue(['VITE_PUBLIC_DIRECTORY', 'VITE_PUBLIC_DIR']);

        if ($explicit !== null) {
            return rtrim($explicit, '/\\');
        }

        $cwd = rtrim((string) getcwd(), '/\\');

        return basename($cwd) === 'public'
            ? $cwd
            : $cwd . '/public';
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function buildDirectory(array $options): string
    {
        $directory = self::stringOption($options, 'build_directory', 'buildDirectory')
            ?? self::firstEnvironmentValue(['VITE_BUILD_DIRECTORY', 'VITE_BUILD_DIR'])
            ?? self::DEFAULT_BUILD_DIRECTORY;

        return trim($directory, '/\\');
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function assetUrlBase(array $options): string
    {
        $assetUrl = self::stringOption($options, 'asset_url', 'assetUrl')
            ?? self::firstEnvironmentValue(['VITE_ASSET_URL'])
            ?? '/' . self::buildDirectory($options);

        return rtrim($assetUrl, '/');
    }

    /**
     * @return array<string, mixed>
     */
    private static function readManifest(string $manifestPath): array
    {
        $contents = @file_get_contents($manifestPath);
        if ($contents === false) {
            throw ViteException::invalidManifest($manifestPath);
        }

        try {
            $manifest = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw ViteException::invalidManifest($manifestPath, $exception);
        }

        if (!is_array($manifest)) {
            throw ViteException::invalidManifest($manifestPath);
        }

        return $manifest;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private static function findChunk(array $manifest, string $entry, string $manifestPath): array
    {
        $chunk = $manifest[$entry] ?? null;
        if (is_array($chunk)) {
            return $chunk;
        }

        foreach ($manifest as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            if (($candidate['src'] ?? null) === $entry) {
                return $candidate;
            }
        }

        throw ViteException::entryNotFound($entry, $manifestPath);
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $chunk
     * @param array<string, bool>  $seenImports
     * @return string[]
     */
    private static function collectCss(array $manifest, array $chunk, array $seenImports = []): array
    {
        $css = [];
        $imports = $chunk['imports'] ?? [];

        if (is_array($imports)) {
            foreach ($imports as $import) {
                if (!is_string($import) || isset($seenImports[$import]) || !is_array($manifest[$import] ?? null)) {
                    continue;
                }

                $seenImports[$import] = true;
                $css = array_merge($css, self::collectCss($manifest, $manifest[$import], $seenImports));
            }
        }

        $chunkCss = $chunk['css'] ?? [];
        if (is_array($chunkCss)) {
            foreach ($chunkCss as $file) {
                if (is_string($file) && $file !== '') {
                    $css[] = $file;
                }
            }
        }

        return array_values(array_unique($css));
    }

    private static function joinUrl(string $base, string $path): string
    {
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    private static function scriptTag(string $url): string
    {
        return '<script type="module" src="' . self::escape($url) . '"></script>';
    }

    private static function stylesheetTag(string $url): string
    {
        return '<link rel="stylesheet" href="' . self::escape($url) . '">';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function stringOption(array $options, string $snakeKey, string $camelKey): ?string
    {
        $value = self::option($options, $snakeKey, $camelKey);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function option(array $options, string $snakeKey, string $camelKey): mixed
    {
        if (array_key_exists($snakeKey, $options)) {
            return $options[$snakeKey];
        }

        if (array_key_exists($camelKey, $options)) {
            return $options[$camelKey];
        }

        return null;
    }

    /**
     * @param string[] $names
     */
    private static function firstEnvironmentValue(array $names): ?string
    {
        foreach ($names as $name) {
            if (function_exists('env')) {
                $value = \env($name);
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }

            $value = getenv($name);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
