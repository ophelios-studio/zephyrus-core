<?php

declare(strict_types=1);

namespace Zephyrus\Validation;

final class FormValidator
{
    /** @var array<string, Rule[]> */
    private array $fields;

    /**
     * @param array<string, Rule[]> $fields
     */
    public function __construct(array $fields = [])
    {
        $this->fields = $fields;
    }

    /**
     * Adds or replaces the rules for the given field name (immutable).
     *
     * @param Rule[] $rules
     */
    public function withField(string $name, array $rules): self
    {
        $clone = clone $this;
        $clone->fields[$name] = $rules;
        return $clone;
    }

    /**
     * Adds or replaces multiple field rule sets (immutable).
     *
     * @param array<string, Rule[]> $fields
     */
    public function withFields(array $fields): self
    {
        $clone = clone $this;
        foreach ($fields as $name => $rules) {
            $clone->fields[$name] = $rules;
        }
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
        foreach ($sub->fields as $name => $rules) {
            $clone->fields["{$prefix}.{$name}"] = $rules;
        }
        return $clone;
    }

    /**
     * Validates $data against all registered field rules.
     *
     * Fields whose rule list does not contain a required() rule are treated
     * as optional: when the value is null or an empty string, all rules are
     * skipped and no errors are reported.
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
        foreach ($this->fields as $field => $rules) {
            $value = str_contains($field, '.')
                ? $this->resolveDotPath($data, $field)
                : ($data[$field] ?? null);

            if (!$this->hasRequiredRule($rules) && ($value === null || $value === '')) {
                continue;
            }

            foreach ($rules as $rule) {
                if (!$rule->test($value)) {
                    $bag->add($field, $rule->errorMessage());
                }
            }
        }
        return $bag;
    }

    /**
     * Validates the payload and throws ValidationException when any field fails.
     *
     * @param array<string, mixed> $data
     *
     * @throws ValidationException
     */
    public function validateOrFail(array $data): ErrorBag
    {
        $bag = $this->validate($data);

        if ($bag->hasErrors()) {
            throw ValidationException::fromErrorBag($bag);
        }

        return $bag;
    }

    /**
     * @return array<string, Rule[]>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * Check whether the given rule set contains a required() rule.
     *
     * @param Rule[] $rules
     */
    private function hasRequiredRule(array $rules): bool
    {
        foreach ($rules as $rule) {
            if ($rule->tag() === 'required') {
                return true;
            }
        }
        return false;
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
