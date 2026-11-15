<?php

declare(strict_types=1);

namespace Zephyrus\Validation;

final class Rule
{
    public function __construct(
        private readonly \Closure $test,
        private readonly string $errorMessage,
        private readonly ?string $tag = null,
    ) {}

    /**
     * Builds a rule from a closure that returns true when the value is valid.
     * Mark the closure's parameter #[\SensitiveParameter] when it receives a secret.
     */
    public static function of(\Closure $test, string $errorMessage, ?string $tag = null): self
    {
        return new self($test, $errorMessage, $tag);
    }

    public function test(#[\SensitiveParameter] mixed $value): bool
    {
        return ($this->test)($value);
    }

    public function errorMessage(): string
    {
        return $this->errorMessage;
    }

    public function tag(): ?string
    {
        return $this->tag;
    }
}
