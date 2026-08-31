<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use InvalidArgumentException;

use function array_key_exists;
use function array_values;
use function in_array;
use function implode;
use function is_array;
use function is_string;
use function preg_match;
use function preg_split;
use function strtolower;
use function trim;

/**
 * Immutable builder for Content-Security-Policy header values.
 *
 * Example:
 *   ContentSecurityPolicy::create()
 *     ->withDirective('default-src', ["'self'"])
 *     ->appendValue('img-src', 'https://images.example.com')
 *     ->withDirective('upgrade-insecure-requests')
 *     ->toHeaderValue();
 */
final readonly class ContentSecurityPolicy
{
    /** @var array<string, list<string>> */
    private array $directives;

    /**
     * @param array<string, list<string>> $directives
     */
    private function __construct(array $directives = [])
    {
        $this->directives = $directives;
    }

    public static function create(): self
    {
        return new self();
    }

    public function isEmpty(): bool
    {
        return $this->directives === [];
    }

    public function hasDirective(string $name): bool
    {
        return array_key_exists(self::normalizeDirectiveName($name), $this->directives);
    }

    public function withDirective(string $name, string|array $values = []): self
    {
        $normalizedName = self::normalizeDirectiveName($name);
        $normalizedValues = self::normalizeDirectiveValues($values);

        $updated = $this->directives;
        $updated[$normalizedName] = $normalizedValues;

        return new self($updated);
    }

    public function appendValue(string $name, string $value): self
    {
        $normalizedName = self::normalizeDirectiveName($name);
        $normalizedValue = self::normalizeDirectiveValue($value);

        $updated = $this->directives;
        $existing = $updated[$normalizedName] ?? [];
        if (!in_array($normalizedValue, $existing, true)) {
            $existing[] = $normalizedValue;
        }
        $updated[$normalizedName] = $existing;

        return new self($updated);
    }

    public function appendNonce(string $name, string $nonce): self
    {
        return $this->appendValue($name, self::formatNonceSource($nonce));
    }

    public function appendHash(string $name, string $algorithm, string $hash): self
    {
        return $this->appendValue($name, self::formatHashSource($algorithm, $hash));
    }

    public function withoutDirective(string $name): self
    {
        $normalizedName = self::normalizeDirectiveName($name);
        if (!array_key_exists($normalizedName, $this->directives)) {
            return $this;
        }

        $updated = $this->directives;
        unset($updated[$normalizedName]);

        return new self($updated);
    }

    /** @return array<string, list<string>> */
    public function toArray(): array
    {
        return $this->directives;
    }

    public function toHeaderValue(): string
    {
        if ($this->directives === []) {
            return '';
        }

        $parts = [];
        foreach ($this->directives as $name => $values) {
            $parts[] = $values === [] ? $name : $name . ' ' . implode(' ', $values);
        }

        return implode('; ', $parts);
    }

    public function __toString(): string
    {
        return $this->toHeaderValue();
    }

    private static function formatNonceSource(string $nonce): string
    {
        return "'nonce-" . self::normalizeBase64Token($nonce) . "'";
    }

    private static function formatHashSource(string $algorithm, string $hash): string
    {
        $normalizedAlgorithm = strtolower(trim($algorithm));
        if (!in_array($normalizedAlgorithm, ['sha256', 'sha384', 'sha512'], true)) {
            throw new InvalidArgumentException('CSP hash algorithm must be sha256, sha384, or sha512.');
        }

        return "'" . $normalizedAlgorithm . '-' . self::normalizeBase64Token($hash) . "'";
    }

    private static function normalizeBase64Token(string $value): string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            throw new InvalidArgumentException('CSP nonce/hash value cannot be empty.');
        }

        if (preg_match('/^[A-Za-z0-9+\/_-]+={0,2}$/', $normalized) !== 1) {
            throw new InvalidArgumentException('CSP nonce/hash value must be valid base64 content.');
        }

        return $normalized;
    }

    private static function normalizeDirectiveName(string $name): string
    {
        $normalized = strtolower(trim($name));
        if ($normalized === '' || preg_match('/^[a-z][a-z0-9-]*$/', $normalized) !== 1) {
            throw new InvalidArgumentException('Invalid CSP directive name.');
        }

        return $normalized;
    }

    /** @return list<string> */
    private static function normalizeDirectiveValues(string|array $values): array
    {
        if (is_string($values)) {
            $values = trim($values);
            if ($values === '') {
                return [];
            }

            // The separator guard runs on the RAW string, before the split.
            // preg_split('/\s+/') consumes CR and LF as delimiters, so a value
            // checked only after splitting could never contain one: the same
            // "a\r\nb" threw as an array and was quietly accepted as a string.
            self::assertNoSeparators($values);

            // Splitting a whitespace-separated source list is deliberate and is
            // the documented way to write a directive, so it stays.
            $values = preg_split('/\s+/', $values) ?: [];
        }

        if (!is_array($values)) {
            throw new InvalidArgumentException('CSP directive values must be a string or array.');
        }

        $normalized = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException('CSP directive values must be strings.');
            }

            $normalizedValue = self::normalizeDirectiveValue($value);
            if (!in_array($normalizedValue, $normalized, true)) {
                $normalized[] = $normalizedValue;
            }
        }

        return array_values($normalized);
    }

    /**
     * A directive VALUE is exactly one source expression.
     *
     * Rejecting ";" and CRLF was never enough, because a plain space is also a
     * separator in a CSP directive: "https://cdn.tenant.example 'unsafe-inline'"
     * passed as one value emitted two source expressions, so a caller allowed to
     * add a host could switch inline script on. Interior whitespace of any kind
     * is refused here; callers that legitimately want several sources pass an
     * array, or the whitespace-separated string form of withDirective().
     */
    private static function normalizeDirectiveValue(string $value): string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            throw new InvalidArgumentException('CSP directive values cannot be empty.');
        }

        self::assertNoSeparators($normalized);

        if (preg_match('/\s/', $normalized) === 1) {
            throw new InvalidArgumentException(
                'A CSP directive value must be a single source expression and cannot contain whitespace.',
            );
        }

        return $normalized;
    }

    private static function assertNoSeparators(string $value): void
    {
        if (preg_match('/[;\r\n]/', $value) === 1) {
            throw new InvalidArgumentException('CSP directive values cannot contain separators.');
        }
    }
}
