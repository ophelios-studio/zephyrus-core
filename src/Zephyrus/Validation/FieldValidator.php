<?php

declare(strict_types=1);

namespace Zephyrus\Validation;

final class FieldValidator
{
    /** @var Rule[] */
    private array $rules;

    private bool $optional;

    public static function withRules(Rule ...$rules): self
    {
        return new self(array_values($rules), false);
    }

    /**
     * Creates a FieldValidator whose rules are only applied when a value is
     * actually present (i.e. not null and not an empty string).  When the
     * value is absent the validator always returns no errors, allowing the
     * field to be omitted from the submitted payload entirely.
     */
    public static function optional(Rule ...$rules): self
    {
        return new self(array_values($rules), true);
    }

    /**
     * Returns a new FieldValidator with the given rule appended (immutable).
     * The optional flag is preserved on the returned copy.
     */
    public function addRule(Rule $rule): self
    {
        return new self([...$this->rules, $rule], $this->optional);
    }

    /**
     * Validates the given value against all rules.
     *
     * For optional validators, null and empty-string are treated as "absent"
     * and validation is skipped — the method returns an empty array.
     *
     * @return string[] List of error messages; empty means all rules passed.
     */
    public function validate(mixed $value): array
    {
        if ($this->optional && ($value === null || $value === '')) {
            return [];
        }

        $errors = [];
        foreach ($this->rules as $rule) {
            if (!$rule->test($value)) {
                $errors[] = $rule->errorMessage();
            }
        }
        return $errors;
    }

    /**
     * @return Rule[]
     */
    public function rules(): array
    {
        return $this->rules;
    }

    public function isOptional(): bool
    {
        return $this->optional;
    }

    /** @param Rule[] $rules */
    private function __construct(array $rules, bool $optional)
    {
        $this->rules    = $rules;
        $this->optional = $optional;
    }
}
