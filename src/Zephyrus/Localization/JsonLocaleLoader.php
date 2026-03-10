<?php

declare(strict_types=1);

namespace Zephyrus\Localization;

use JsonException;

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

        if ($path === null) {
            return [];
        }

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

        $flat = [];
        $this->flatten($decoded, '', $flat);

        return $flat;
    }

    private function resolvePath(string $locale): ?string
    {
        $base = rtrim($this->basePath, DIRECTORY_SEPARATOR);
        $extension = ltrim($this->extension, '.');

        $candidates = [$locale];
        if (str_contains($locale, '-')) {
            $candidates[] = str_replace('-', '_', $locale);
        }

        foreach (array_values(array_unique($candidates)) as $candidate) {
            $path = $base . DIRECTORY_SEPARATOR . $candidate . '.' . $extension;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
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
