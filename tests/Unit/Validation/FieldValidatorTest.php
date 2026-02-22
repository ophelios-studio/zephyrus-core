<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use Zephyrus\Validation\FieldValidator;
use Zephyrus\Validation\Rule;
use Zephyrus\Validation\Rules;

final class FieldValidatorTest extends TestCase
{
    public function testEmptyRuleSetAlwaysPasses(): void
    {
        $v = FieldValidator::withRules();
        self::assertSame([], $v->validate('anything'));
        self::assertSame([], $v->validate(null));
    }

    public function testSingleRulePasses(): void
    {
        $v = FieldValidator::withRules(Rules::required());
        self::assertSame([], $v->validate('hello'));
    }

    public function testSingleRuleFails(): void
    {
        $v = FieldValidator::withRules(Rules::required());
        $errors = $v->validate('');
        self::assertCount(1, $errors);
        self::assertSame('This field is required.', $errors[0]);
    }

    public function testMultipleRulesAllFail(): void
    {
        $v = FieldValidator::withRules(
            Rules::required(),
            Rules::email(),
        );
        // empty string fails both
        $errors = $v->validate('');
        self::assertCount(2, $errors);
    }

    public function testMultipleRulesPartialFail(): void
    {
        $v = FieldValidator::withRules(
            Rules::required(),
            Rules::email(),
        );
        // non-empty but invalid email: required passes, email fails
        $errors = $v->validate('not-an-email');
        self::assertCount(1, $errors);
        self::assertSame('Must be a valid email address.', $errors[0]);
    }

    public function testAddRuleReturnsCopy(): void
    {
        $original = FieldValidator::withRules(Rules::required());
        $extended = $original->addRule(Rules::email());

        self::assertCount(1, $original->rules());
        self::assertCount(2, $extended->rules());
    }

    public function testAddRuleImmutable(): void
    {
        $v1 = FieldValidator::withRules(Rules::required());
        $v2 = $v1->addRule(Rules::minLength(5));

        self::assertNotSame($v1, $v2);
        self::assertCount(1, $v1->rules());
        self::assertCount(2, $v2->rules());
    }

    public function testCustomRuleViaRuleOf(): void
    {
        $rule = Rule::of(fn (mixed $v) => $v === 'secret', 'Wrong password.');
        $v = FieldValidator::withRules($rule);

        self::assertSame([], $v->validate('secret'));
        self::assertSame(['Wrong password.'], $v->validate('wrong'));
    }

    public function testValidateNullAgainstRequired(): void
    {
        $v = FieldValidator::withRules(Rules::required());
        $errors = $v->validate(null);
        self::assertSame(['This field is required.'], $errors);
    }

    public function testChainedAddRuleBuildsCorrectly(): void
    {
        $v = FieldValidator::withRules(Rules::required())
            ->addRule(Rules::minLength(3))
            ->addRule(Rules::maxLength(10));

        self::assertCount(3, $v->rules());
        self::assertSame([], $v->validate('hello'));
        self::assertSame(['Must be at least 3 characters.'], $v->validate('ab'));
    }
}
