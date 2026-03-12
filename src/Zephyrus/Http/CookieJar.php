<?php

declare(strict_types=1);

namespace Zephyrus\Http;

/**
 * Immutable, read-only collection of request cookies.
 */
final readonly class CookieJar
{
    /**
     * @param array<string, string> $cookies
     */
    public function __construct(private array $cookies = [])
    {
    }

    public function get(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->cookies);
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->cookies;
    }
}
