<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use Zephyrus\Validation\Rule;

final class RuleTest extends TestCase
{
    public function testPassingRule(): void
    {
        $rule = Rule::of(fn (mixed $v) => $v === 'ok', 'Not ok.');
        self::assertTrue($rule->test('ok'));
    }

    public function testFailingRule(): void
    {
        $rule = Rule::of(fn (mixed $v) => $v === 'ok', 'Not ok.');
        self::assertFalse($rule->test('fail'));
    }

    public function testErrorMessageReturned(): void
    {
        $rule = Rule::of(fn (mixed $v) => false, 'Custom error message.');
        self::assertSame('Custom error message.', $rule->errorMessage());
    }

    public function testNullValueFails(): void
    {
        $rule = Rule::of(fn (mixed $v) => $v !== null, 'Must not be null.');
        self::assertFalse($rule->test(null));
    }

    public function testRulePassesWithNumericValue(): void
    {
        $rule = Rule::of(fn (mixed $v) => is_numeric($v), 'Must be numeric.');
        self::assertTrue($rule->test(42));
        self::assertTrue($rule->test('3.14'));
        self::assertFalse($rule->test('abc'));
    }
}
