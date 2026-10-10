<?php

declare(strict_types=1);

namespace Zephyrus\Http;

/**
 * Immutable value object wrapping the parsed request body data and the raw body string.
 */
final readonly class RequestBody
{
    /**
     * @param array<string, mixed> $data Parsed body parameters (form or JSON).
     * @param string $raw Raw body content (from php://input).
     * @param bool $malformed True when a JSON body could not be decoded into an array.
     */
    public function __construct(
        private array $data = [],
        private string $raw = '',
        private bool $malformed = false,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    public function isEmpty(): bool
    {
        return $this->data === [];
    }

    /**
     * Check this before validating: a malformed JSON body reads as empty.
     */
    public function isMalformed(): bool
    {
        return $this->malformed;
    }

    /**
     * Raw body content as received, e.g. for webhook signature verification.
     */
    public function raw(): string
    {
        return $this->raw;
    }
}
