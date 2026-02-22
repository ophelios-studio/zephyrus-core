<?php

declare(strict_types=1);

namespace Zephyrus\Validation;

final class Rule
{
    public function __construct(
        private readonly \Closure $test,
        private readonly string $errorMessage,
    ) {}

    public static function of(\Closure $test, string $errorMessage): self
    {
        return new self($test, $errorMessage);
    }

    public function test(mixed $value): bool
    {
        return ($this->test)($value);
    }

    public function errorMessage(): string
    {
        return $this->errorMessage;
    }
}
