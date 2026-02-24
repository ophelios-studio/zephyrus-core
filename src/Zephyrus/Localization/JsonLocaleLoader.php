<?php

declare(strict_types=1);

namespace Zephyrus\Localization;

use RuntimeException;

final class JsonLocaleLoader implements LocaleLoaderInterface
{
    public function __construct(
        private readonly string $basePath,
        private readonly string $extension = 'json'
    ) {
    }

    public function load(string $locale): array
    {
        $path = $this->resolvePath($locale);

        if (!is_file($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException(sprintf('Unable to read locale file "%s".', $path));
        }

        /** @var mixed $decoded */
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Locale file "%s" must decode to an object.', $path));
        }

        $flat = [];
        $this->flatten($decoded, '', $flat);

        return $flat;
    }

    private function resolvePath(string $locale): string
    {
        return rtrim($this->basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $locale . '.' . ltrim($this->extension, '.');
    }

    /**
     * @param array<mixed> $source
     * @param array<string, string> $result
     */
    private function flatten(array $source, string $prefix, array &$result): void
    {
        foreach ($source as $key => $value) {
            $segment = (string) $key;
            $fullKey = $prefix === '' ? $segment : $prefix . '.' . $segment;

            if (is_array($value)) {
                $this->flatten($value, $fullKey, $result);
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $result[$fullKey] = (string) $value;
            }
        }
    }
}
