<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use Zephyrus\Validation\ErrorBag;
use Zephyrus\Validation\FormValidator;
use Zephyrus\Validation\Rules;
use Zephyrus\Validation\ValidationException;

final class FormValidatorTest extends TestCase
{
    public function testEmptyFormValidatorAlwaysPasses(): void
    {
        $form = new FormValidator();
        $bag = $form->validate(['email' => 'anything']);
        self::assertFalse($bag->hasErrors());
    }

    public function testValidPayloadProducesEmptyBag(): void
    {
        $form = new FormValidator([
            'email' => [Rules::required(), Rules::email()],
            'age'   => [Rules::required(), Rules::integer()],
        ]);

        $bag = $form->validate(['email' => 'user@example.com', 'age' => '25']);
        self::assertFalse($bag->hasErrors());
    }

    public function testMissingFieldTreatedAsNull(): void
    {
        $form = new FormValidator([
            'email' => [Rules::required()],
        ]);

        $bag = $form->validate([]); // no 'email' key
        self::assertTrue($bag->hasErrors());
        self::assertSame(['This field is required.'], $bag->errorsFor('email'));
    }

    public function testMultipleFieldErrors(): void
    {
        $form = new FormValidator([
            'email' => [Rules::required(), Rules::email()],
            'name'  => [Rules::required(), Rules::minLength(2)],
        ]);

        $bag = $form->validate(['email' => 'bad', 'name' => 'a']);
        self::assertTrue($bag->hasErrors());
        self::assertTrue($bag->hasErrorsFor('email'));
        self::assertTrue($bag->hasErrorsFor('name'));
        // bad email: required passes, email fails
        self::assertCount(1, $bag->errorsFor('email'));
        // 'a': required passes, minLength fails
        self::assertCount(1, $bag->errorsFor('name'));
    }

    public function testWithFieldImmutable(): void
    {
        $form1 = new FormValidator([
            'email' => [Rules::required()],
        ]);
        $form2 = $form1->withField('name', [Rules::required()]);

        self::assertNotSame($form1, $form2);
        self::assertCount(1, $form1->fields());
        self::assertCount(2, $form2->fields());
    }

    public function testWithFieldOverridesExisting(): void
    {
        $form = new FormValidator([
            'age' => [Rules::required()],
        ]);
        $form2 = $form->withField('age', [Rules::required(), Rules::integer()]);

        self::assertCount(2, $form2->fields()['age']);
    }

    public function testWithFieldsIsImmutableAndMergesRules(): void
    {
        $form1 = new FormValidator([
            'email' => [Rules::required()],
        ]);

        $form2 = $form1->withFields([
            'name' => [Rules::required(), Rules::minLength(2)],
            'age'  => [Rules::integer(), Rules::min(18)],
        ]);

        self::assertNotSame($form1, $form2);
        self::assertCount(1, $form1->fields());
        self::assertCount(3, $form2->fields());
        self::assertArrayHasKey('email', $form2->fields());
        self::assertArrayHasKey('name', $form2->fields());
        self::assertArrayHasKey('age', $form2->fields());
    }

    public function testWithFieldsOverridesExistingKeys(): void
    {
        $form = new FormValidator([
            'age' => [Rules::required()],
        ]);

        $form2 = $form->withFields([
            'age' => [Rules::required(), Rules::integer()],
        ]);

        self::assertCount(1, $form->fields()['age']);
        self::assertCount(2, $form2->fields()['age']);
    }

    public function testWithFieldsIntegratesIntoValidationFlow(): void
    {
        $form = (new FormValidator())
            ->withFields([
                'email' => [Rules::required(), Rules::email()],
                'age'   => [Rules::required(), Rules::integer(), Rules::min(18)],
            ]);

        self::assertFalse($form->validate(['email' => 'user@example.com', 'age' => '21'])->hasErrors());

        $bag = $form->validate(['email' => 'bad', 'age' => '15']);
        self::assertTrue($bag->hasErrorsFor('email'));
        self::assertTrue($bag->hasErrorsFor('age'));
    }

    public function testExtraFieldsInDataAreIgnored(): void
    {
        $form = new FormValidator([
            'email' => [Rules::required()],
        ]);

        $bag = $form->validate([
            'email' => 'user@example.com',
            'extra' => 'ignored',
        ]);
        self::assertFalse($bag->hasErrors());
    }

    public function testBagReturnTypeIsErrorBag(): void
    {
        $form = new FormValidator();
        $bag = $form->validate([]);
        self::assertInstanceOf(ErrorBag::class, $bag);
    }

    public function testAllFieldErrorsCollected(): void
    {
        $form = new FormValidator([
            'email'    => [Rules::required(), Rules::email()],
            'password' => [Rules::required(), Rules::minLength(8)],
            'age'      => [Rules::required(), Rules::integer(), Rules::min(18)],
        ]);

        $bag = $form->validate(['email' => '', 'password' => 'abc', 'age' => '15']);
        self::assertTrue($bag->hasErrorsFor('email'));
        self::assertTrue($bag->hasErrorsFor('password'));
        self::assertTrue($bag->hasErrorsFor('age'));
        self::assertCount(3, $bag->failingFields());
    }

    public function testBetweenRuleIntegration(): void
    {
        $form = new FormValidator([
            'score' => [
                Rules::required(),
                Rules::integer(),
                Rules::between(0, 100),
            ],
        ]);

        self::assertFalse($form->validate(['score' => '50'])->hasErrors());
        self::assertTrue($form->validate(['score' => '101'])->hasErrors());
    }

    public function testInRuleIntegration(): void
    {
        $form = new FormValidator([
            'role' => [
                Rules::required(),
                Rules::in(['admin', 'editor', 'viewer']),
            ],
        ]);

        self::assertFalse($form->validate(['role' => 'editor'])->hasErrors());
        $bag = $form->validate(['role' => 'superuser']);
        self::assertTrue($bag->hasErrors());
        self::assertSame(
            'Must be one of: admin, editor, viewer.',
            $bag->firstFor('role'),
        );
    }

    // ---- dot-path nested payload ----

    public function testDotPathFieldResolvesNestedValue(): void
    {
        $form = new FormValidator([
            'user.name' => [Rules::required()],
        ]);

        self::assertFalse($form->validate(['user' => ['name' => 'Alice']])->hasErrors());

        $bag = $form->validate(['user' => ['name' => '']]);
        self::assertTrue($bag->hasErrors());
        self::assertSame(['This field is required.'], $bag->errorsFor('user.name'));
    }

    public function testDotPathMissingParentKeyTreatedAsNull(): void
    {
        $form = new FormValidator([
            'user.name' => [Rules::required()],
        ]);

        // 'user' key is entirely absent — resolves to null
        $bag = $form->validate([]);
        self::assertTrue($bag->hasErrorsFor('user.name'));
    }

    public function testDotPathMissingLeafKeyTreatedAsNull(): void
    {
        $form = new FormValidator([
            'user.email' => [Rules::required()],
        ]);

        // parent key exists but leaf 'email' is absent
        $bag = $form->validate(['user' => ['name' => 'Alice']]);
        self::assertTrue($bag->hasErrorsFor('user.email'));
    }

    public function testDotPathDeepNesting(): void
    {
        $form = new FormValidator([
            'billing.address.city' => [Rules::required(), Rules::minLength(2)],
        ]);

        self::assertFalse($form->validate([
            'billing' => ['address' => ['city' => 'Paris']],
        ])->hasErrors());

        $bag = $form->validate([
            'billing' => ['address' => ['city' => 'A']], // single char: passes required, fails minLength(2)
        ]);
        self::assertFalse($bag->hasErrorsFor('billing.address.city') === false);
        self::assertSame(['Must be at least 2 characters.'], $bag->errorsFor('billing.address.city'));
    }

    public function testDotPathIntermediateNodeIsScalarReturnsNull(): void
    {
        // 'user' is a string, not an array — should yield null for user.name
        $form = new FormValidator([
            'user.name' => [Rules::required()],
        ]);

        $bag = $form->validate(['user' => 'not-an-array']);
        self::assertTrue($bag->hasErrorsFor('user.name'));
    }

    public function testWithNestedMergesSubValidatorWithPrefix(): void
    {
        $addressValidator = (new FormValidator())
            ->withField('city', [Rules::required()])
            ->withField('zip',  [Rules::required()]);

        $form = (new FormValidator())
            ->withField('name', [Rules::required()])
            ->withNested('address', $addressValidator);

        self::assertCount(3, $form->fields());
        self::assertArrayHasKey('name',         $form->fields());
        self::assertArrayHasKey('address.city', $form->fields());
        self::assertArrayHasKey('address.zip',  $form->fields());
    }

    public function testWithNestedValidatesCorrectly(): void
    {
        $addressValidator = (new FormValidator())
            ->withField('city', [Rules::required()])
            ->withField('zip',  [Rules::required(), Rules::minLength(5)]);

        $form = (new FormValidator())
            ->withField('name', [Rules::required()])
            ->withNested('address', $addressValidator);

        $bag = $form->validate([
            'name'    => 'Alice',
            'address' => ['city' => '', 'zip' => '123'],
        ]);

        self::assertFalse($bag->hasErrorsFor('name'));
        self::assertTrue($bag->hasErrorsFor('address.city'));
        self::assertTrue($bag->hasErrorsFor('address.zip'));
        self::assertSame(['This field is required.'], $bag->errorsFor('address.city'));
        self::assertSame(['Must be at least 5 characters.'], $bag->errorsFor('address.zip'));
    }

    public function testWithNestedIsImmutable(): void
    {
        $addressValidator = (new FormValidator())
            ->withField('city', [Rules::required()]);

        $form1 = new FormValidator();
        $form2 = $form1->withNested('address', $addressValidator);

        self::assertNotSame($form1, $form2);
        self::assertCount(0, $form1->fields());
        self::assertCount(1, $form2->fields());
    }

    public function testWithNestedFullValidPayloadPasses(): void
    {
        $contactValidator = (new FormValidator())
            ->withField('email', [Rules::required(), Rules::email()])
            ->withField('phone', [Rules::required()]);

        $form = (new FormValidator())
            ->withField('username', [Rules::required()])
            ->withNested('contact', $contactValidator);

        $bag = $form->validate([
            'username' => 'bob',
            'contact'  => ['email' => 'bob@example.com', 'phone' => '555-0100'],
        ]);

        self::assertFalse($bag->hasErrors());
    }

    public function testValidateOrFailReturnsBagWhenValid(): void
    {
        $form = new FormValidator([
            'email' => [Rules::required(), Rules::email()],
        ]);

        $bag = $form->validateOrFail(['email' => 'ok@example.com']);

        self::assertInstanceOf(ErrorBag::class, $bag);
        self::assertFalse($bag->hasErrors());
    }

    public function testValidateOrFailThrowsValidationExceptionWhenInvalid(): void
    {
        $form = new FormValidator([
            'email' => [Rules::required(), Rules::email()],
        ]);

        try {
            $form->validateOrFail(['email' => 'not-an-email']);
            self::fail('Expected ValidationException to be thrown.');
        } catch (ValidationException $e) {
            self::assertSame('Validation failed.', $e->getMessage());
            self::assertTrue($e->errors()->hasErrorsFor('email'));
            self::assertSame('Must be a valid email address.', $e->errors()->firstFor('email'));
        }
    }

    // ---- optional fields (no required() rule = optional) ----

    public function testOptionalFieldAbsentFromPayloadProducesNoError(): void
    {
        $form = new FormValidator([
            'name'    => [Rules::required()],
            'website' => [Rules::url()],
        ]);

        // website omitted entirely → no error (no required rule = optional)
        $bag = $form->validate(['name' => 'Alice']);
        self::assertFalse($bag->hasErrors());
    }

    public function testOptionalFieldEmptyStringProducesNoError(): void
    {
        $form = new FormValidator([
            'name'    => [Rules::required()],
            'website' => [Rules::url()],
        ]);

        // website present as empty string → skipped (optional)
        $bag = $form->validate(['name' => 'Alice', 'website' => '']);
        self::assertFalse($bag->hasErrors());
    }

    public function testOptionalFieldWithValidValuePassesRules(): void
    {
        $form = new FormValidator([
            'website' => [Rules::url()],
        ]);

        $bag = $form->validate(['website' => 'https://example.com']);
        self::assertFalse($bag->hasErrors());
    }

    public function testOptionalFieldWithInvalidValueFailsRules(): void
    {
        $form = new FormValidator([
            'website' => [Rules::url()],
        ]);

        $bag = $form->validate(['website' => 'not-a-url']);
        self::assertTrue($bag->hasErrorsFor('website'));
    }

    public function testOptionalNestedFieldSkippedWhenAbsent(): void
    {
        $profileValidator = (new FormValidator())
            ->withField('bio', [Rules::maxLength(200)]);

        $form = (new FormValidator())
            ->withField('name', [Rules::required()])
            ->withNested('profile', $profileValidator);

        // bio absent → no error (optional)
        $bag = $form->validate(['name' => 'Alice', 'profile' => []]);
        self::assertFalse($bag->hasErrors());

        // bio too long → error
        $bag = $form->validate(['name' => 'Alice', 'profile' => ['bio' => str_repeat('x', 201)]]);
        self::assertTrue($bag->hasErrorsFor('profile.bio'));
    }

    // ---- tag-based optional detection ----

    public function testFieldWithRequiredTagIsAlwaysValidated(): void
    {
        $form = new FormValidator([
            'email' => [Rules::required(), Rules::email()],
        ]);

        // null value → required rule fails
        $bag = $form->validate([]);
        self::assertTrue($bag->hasErrorsFor('email'));
    }

    public function testFieldWithoutRequiredTagSkipsNullValues(): void
    {
        $form = new FormValidator([
            'email' => [Rules::email()],
        ]);

        // null value → skipped since no required tag
        $bag = $form->validate([]);
        self::assertFalse($bag->hasErrors());
    }

    public function testFieldWithoutRequiredTagValidatesPresentValues(): void
    {
        $form = new FormValidator([
            'email' => [Rules::email()],
        ]);

        // present but invalid → fails
        $bag = $form->validate(['email' => 'not-valid']);
        self::assertTrue($bag->hasErrorsFor('email'));

        // present and valid → passes
        $bag = $form->validate(['email' => 'a@b.com']);
        self::assertFalse($bag->hasErrors());
    }
}
