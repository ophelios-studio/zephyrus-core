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
     * @param bool $malformed True when a JSON body could not be decoded into an array, or arrived incomplete.
     * @param ?int $readLimit The limit Request::fromGlobals() read the body with (0 for none), null otherwise.
     * @param bool $truncated True when the body went over $readLimit and was not read whole.
     */
    public function __construct(
        private array $data = [],
        private string $raw = '',
        private bool $malformed = false,
        private ?int $readLimit = null,
        private bool $truncated = false,
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
     * Check this before validating: a malformed JSON body, a truncated one, or one Request::fromGlobals() received
     * incomplete (shorter than its Content-Length, or with an upload PHP ended without its boundary) reads as empty.
     */
    public function isMalformed(): bool
    {
        return $this->malformed || $this->truncated;
    }

    /**
     * Whether the body went over the read limit of Request::fromGlobals(), so it was cut or not read at all.
     * Its fields are empty; files PHP received stay listed. Refused before any controller (413 by default).
     */
    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    /**
     * The limit Request::fromGlobals() read the body with: 0 for none, null for a body it did not build.
     */
    public function readLimit(): ?int
    {
        return $this->readLimit;
    }

    /**
     * Raw body content as received, e.g. for webhook signature verification.
     */
    public function raw(): string
    {
        return $this->raw;
    }
}
