<?php

declare(strict_types=1);

namespace Zephyrus\Rendering;

use Zephyrus\FileSystem\SafePath;

/**
 * Asset manager for cache-busted URL generation and file embedding.
 *
 * Generates versioned URLs by appending a content-hash query parameter
 * to asset paths. This ensures browsers fetch fresh copies when assets
 * change, while allowing infinite caching for unchanged assets.
 *
 * Usage:
 *
 *   $asset = new Asset('/var/www/public');
 *   $asset->url('/css/app.css');  // "/css/app.css?v=a3f2b1c"
 *   $asset->embed('/img/logo.svg'); // "<svg>...</svg>"
 *
 * The hash is computed once per request and cached in memory.
 *
 * ## Path safety
 * `embed()` returns raw file bytes and is exposed to every template through the
 * global `embed()` helper, so the requested path is treated as untrusted. A path
 * containing a `..` segment or a null byte is refused, and the resolved file
 * must still sit under the configured public directory once `realpath()` has
 * collapsed symbolic links. `exists()` therefore reports false for such a path
 * rather than acting as a file-existence oracle for the whole disk.
 */
final class Asset
{
    private string $publicDirectory;
    private string $hashAlgo;

    /** @var array<string, string> In-memory hash cache. */
    private array $hashCache = [];

    /**
     * @param string $publicDirectory Absolute path to the public assets directory.
     * @param string $hashAlgo        Hash algorithm for cache-busting (default md5 for speed).
     */
    public function __construct(string $publicDirectory, string $hashAlgo = 'md5')
    {
        $this->publicDirectory = rtrim($publicDirectory, '/\\');
        $this->hashAlgo = $hashAlgo;
    }

    /**
     * Generate a cache-busted URL for an asset.
     *
     * @param string $path The URL path relative to the public directory (e.g. '/css/app.css').
     * @return string The path with a ?v=hash query parameter, or the original path if the file is missing.
     */
    public function url(string $path): string
    {
        $hash = $this->computeHash($path);
        if ($hash === null) {
            return $path;
        }

        $separator = str_contains($path, '?') ? '&' : '?';
        return $path . $separator . 'v=' . $hash;
    }

    /**
     * Embed an asset's content inline (e.g. SVGs, small CSS/JS snippets).
     *
     * @param string $path The URL path relative to the public directory.
     * @return string The file contents, or an empty string if the file is missing.
     */
    public function embed(string $path): string
    {
        $filePath = $this->resolve($path);
        if ($filePath === null) {
            return '';
        }

        $content = @file_get_contents($filePath);
        return $content !== false ? $content : '';
    }

    /**
     * Check whether an asset exists.
     */
    public function exists(string $path): bool
    {
        return $this->resolve($path) !== null;
    }

    /**
     * Resolve a URL path to an absolute filesystem path.
     *
     * @return string|null The absolute path, or null if the file doesn't exist.
     */
    private function resolve(string $path): ?string
    {
        // Strip query string and fragment for filesystem resolution.
        // parse_url() returns false on a severely malformed URL, in which case
        // the raw path is used and SafePath decides whether it is acceptable.
        $parsed = parse_url($path, PHP_URL_PATH);
        $cleanPath = is_string($parsed) ? $parsed : $path;
        $filePath = SafePath::within($this->publicDirectory, $cleanPath);

        return $filePath !== null && is_file($filePath) ? $filePath : null;
    }

    /**
     * Compute the content hash for cache-busting.
     *
     * Result is cached in memory for the duration of the request.
     */
    private function computeHash(string $path): ?string
    {
        if (isset($this->hashCache[$path])) {
            return $this->hashCache[$path];
        }

        $filePath = $this->resolve($path);
        if ($filePath === null) {
            return null;
        }

        $hash = @hash_file($this->hashAlgo, $filePath);
        if ($hash === false) {
            return null;
        }

        // Truncate to 8 chars for short URLs.
        $short = substr($hash, 0, 8);
        $this->hashCache[$path] = $short;

        return $short;
    }
}
