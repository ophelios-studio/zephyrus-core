<?php

declare(strict_types=1);

namespace Zephyrus\Http;

/**
 * Immutable, case-insensitive HTTP header collection.
 *
 * All header names are stored in lowercase for consistent lookups.
 */
final readonly class HeaderBag
{
    /**
     * @param array<string, string> $headers All keys must be lowercase.
     */
    public function __construct(private array $headers = [])
    {
    }

    public function get(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function has(string $name): bool
    {
        return array_key_exists(strtolower($name), $this->headers);
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->headers;
    }

    /**
     * Extract a Bearer token from the given header (defaults to Authorization).
     *
     * Returns null when the header is missing, empty, or does not contain
     * a token after the prefix.
     */
    public function bearerToken(string $headerName = 'Authorization', string $prefix = 'Bearer '): ?string
    {
        $value = $this->get($headerName);
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $normalizedPrefix = trim($prefix);
        if ($normalizedPrefix === '') {
            return $trimmed;
        }

        if (strncasecmp($trimmed, $normalizedPrefix, strlen($normalizedPrefix)) === 0) {
            $token = trim(substr($trimmed, strlen($normalizedPrefix)));
            return $token === '' ? null : $token;
        }

        return $trimmed;
    }

    public function contentType(): ?string
    {
        return $this->get('content-type');
    }

    public function isJson(): bool
    {
        $contentType = strtolower(trim($this->get('content-type') ?? ''));

        if ($contentType === '') {
            return false;
        }

        $mediaType = trim(strtok($contentType, ';') ?: '');

        return $mediaType === 'application/json' || str_ends_with($mediaType, '+json');
    }
}
