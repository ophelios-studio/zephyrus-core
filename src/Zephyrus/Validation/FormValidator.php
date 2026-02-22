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
     * Merges all fields from $sub prefixed with "$prefix." (immutable).
     *
     * Allows reusable sub-validators to be composed into a parent:
     *   $form->withNested('address', $addressValidator)
     * registers 'address.city', 'address.zip', etc.
     */
    public function withNested(string $prefix, self $sub): self
    {
        $clone = clone $this;
        foreach ($sub->fields as $name => $validator) {
            $clone->fields["{$prefix}.{$name}"] = $validator;
        }
        return $clone;
    }

    /**
     * Validates $data against all registered field validators.
     *
     * Field names containing "." are resolved as dot-paths into nested arrays
     * (e.g. "address.city" → $data['address']['city']).  Missing keys at any
     * depth are treated as null so that required-rule catches absent fields.
     *
     * @param array<string, mixed> $data
     */
    public function validate(array $data): ErrorBag
    {
        $bag = new ErrorBag();
        foreach ($this->fields as $field => $validator) {
            $value = str_contains($field, '.')
                ? $this->resolveDotPath($data, $field)
                : ($data[$field] ?? null);
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

    /**
     * Resolves a dot-notation path into a nested array.
     * Returns null if any key in the chain is missing or non-array.
     *
     * @param array<string, mixed> $data
     */
    private function resolveDotPath(array $data, string $path): mixed
    {
        $keys    = explode('.', $path);
        $current = $data;
        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }
        return $current;
    }
}
