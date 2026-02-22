<?php

declare(strict_types=1);

namespace Zephyrus\Validation;

final class FormValidator
{
    /** @var array<string, FieldValidator> */
    private array $fields;

    /**
     * @param array<string, FieldValidator> $fields
     */
    public function __construct(array $fields = [])
    {
        $this->fields = $fields;
    }

    /**
     * Adds or replaces the FieldValidator for the given field name (immutable).
     */
    public function withField(string $name, FieldValidator $validator): self
    {
        $clone = clone $this;
        $clone->fields[$name] = $validator;
        return $clone;
    }

    /**
     * Validates $data against all registered field validators.
     *
     * Missing fields are validated as null, allowing required-rule to catch
     * absent keys without the caller needing to pre-fill defaults.
     *
     * @param array<string, mixed> $data
     */
    public function validate(array $data): ErrorBag
    {
        $bag = new ErrorBag();
        foreach ($this->fields as $field => $validator) {
            $value = $data[$field] ?? null;
            foreach ($validator->validate($value) as $message) {
                $bag->add($field, $message);
            }
        }
        return $bag;
    }

    /**
     * @return array<string, FieldValidator>
     */
    public function fields(): array
    {
        return $this->fields;
    }
}
