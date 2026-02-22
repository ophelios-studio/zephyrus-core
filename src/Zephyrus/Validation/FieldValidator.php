<?php

declare(strict_types=1);

namespace Zephyrus\Validation;

final class FieldValidator
{
    /** @var Rule[] */
    private array $rules;

    public static function withRules(Rule ...$rules): self
    {
        return new self(array_values($rules));
    }

    /**
     * Returns a new FieldValidator with the given rule appended (immutable).
     */
    public function addRule(Rule $rule): self
    {
        return new self([...$this->rules, $rule]);
    }

    /**
     * Validates the given value against all rules.
     *
     * @return string[] List of error messages; empty means all rules passed.
     */
    public function validate(mixed $value): array
    {
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

    /** @param Rule[] $rules */
    private function __construct(array $rules = [])
    {
        $this->rules = $rules;
    }
}
