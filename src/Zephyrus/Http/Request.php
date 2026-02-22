<?php

declare(strict_types=1);

namespace Zephyrus\Http;

final readonly class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $parsedBody
     * @param array<string, string> $headers
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public string $method,
        public string $uri,
        public array $query = [],
        public array $parsedBody = [],
        public array $headers = [],
        public array $attributes = [],
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $parsedBody
     * @param array<string, string> $headers
     * @param array<string, mixed> $attributes
     */
    public static function fromArray(
        string $method,
        string $uri,
        array $query = [],
        array $parsedBody = [],
        array $headers = [],
        array $attributes = [],
    ): self {
        return new self(
            method: strtoupper($method),
            uri: $uri,
            query: $query,
            parsedBody: $parsedBody,
            headers: self::normalizeHeaders($headers),
            attributes: $attributes,
        );
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->parsedBody[$key] ?? $default;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function path(): string
    {
        return (string) parse_url($this->uri, PHP_URL_PATH);
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $attributes = $this->attributes;
        $attributes[$key] = $value;

        return new self(
            method: $this->method,
            uri: $this->uri,
            query: $this->query,
            parsedBody: $this->parsedBody,
            headers: $this->headers,
            attributes: $attributes,
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function withAttributes(array $attributes): self
    {
        return new self(
            method: $this->method,
            uri: $this->uri,
            query: $this->query,
            parsedBody: $this->parsedBody,
            headers: $this->headers,
            attributes: $attributes,
        );
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }

        return $normalized;
    }
}
