<?php

declare(strict_types=1);

namespace Zephyrus\Validation;

final class ErrorBag
{
    /** @var array<string, string[]> */
    private array $errors = [];

    public function add(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    public function merge(self $other): void
    {
        foreach ($other->toArray() as $field => $messages) {
            foreach ($messages as $message) {
                $this->add($field, $message);
            }
        }
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function hasErrorsFor(string $field): bool
    {
        return isset($this->errors[$field]) && $this->errors[$field] !== [];
    }

    /**
     * @return string[]
     */
    public function errorsFor(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    public function firstFor(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }

    /**
     * All field names that have at least one error.
     *
     * @return string[]
     */
    public function failingFields(): array
    {
        return array_keys($this->errors);
    }

    /**
     * Flat list of all error messages across all fields.
     *
     * @return string[]
     */
    public function allMessages(): array
    {
        return array_merge(...array_values($this->errors));
    }

    /**
     * Structured map: field => string[].
     *
     * @return array<string, string[]>
     */
    public function toArray(): array
    {
        return $this->errors;
    }
}
