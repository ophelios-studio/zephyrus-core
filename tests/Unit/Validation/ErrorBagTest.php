<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use Zephyrus\Validation\ErrorBag;

final class ErrorBagTest extends TestCase
{
    public function testEmptyBagHasNoErrors(): void
    {
        $bag = new ErrorBag();
        self::assertFalse($bag->hasErrors());
        self::assertSame([], $bag->toArray());
        self::assertSame([], $bag->allMessages());
        self::assertSame([], $bag->failingFields());
    }

    public function testAddSingleError(): void
    {
        $bag = new ErrorBag();
        $bag->add('email', 'Must be a valid email.');
        self::assertTrue($bag->hasErrors());
        self::assertTrue($bag->hasErrorsFor('email'));
        self::assertFalse($bag->hasErrorsFor('name'));
    }

    public function testErrorsForField(): void
    {
        $bag = new ErrorBag();
        $bag->add('email', 'Required.');
        $bag->add('email', 'Invalid format.');
        self::assertSame(['Required.', 'Invalid format.'], $bag->errorsFor('email'));
    }

    public function testErrorsForMissingFieldReturnsEmpty(): void
    {
        $bag = new ErrorBag();
        self::assertSame([], $bag->errorsFor('nope'));
    }

    public function testFirstForField(): void
    {
        $bag = new ErrorBag();
        $bag->add('name', 'Required.');
        $bag->add('name', 'Too short.');
        self::assertSame('Required.', $bag->firstFor('name'));
    }

    public function testFirstForMissingFieldReturnsNull(): void
    {
        $bag = new ErrorBag();
        self::assertNull($bag->firstFor('missing'));
    }

    public function testFailingFieldsListsAllFields(): void
    {
        $bag = new ErrorBag();
        $bag->add('email', 'Required.');
        $bag->add('age', 'Must be a number.');
        self::assertSame(['email', 'age'], $bag->failingFields());
    }

    public function testAllMessagesFlattened(): void
    {
        $bag = new ErrorBag();
        $bag->add('email', 'Required.');
        $bag->add('email', 'Invalid format.');
        $bag->add('name', 'Too short.');
        self::assertSame(['Required.', 'Invalid format.', 'Too short.'], $bag->allMessages());
    }

    public function testToArrayStructuredOutput(): void
    {
        $bag = new ErrorBag();
        $bag->add('email', 'Required.');
        $bag->add('name', 'Too short.');
        self::assertSame([
            'email' => ['Required.'],
            'name'  => ['Too short.'],
        ], $bag->toArray());
    }

    public function testMultipleErrorsSameField(): void
    {
        $bag = new ErrorBag();
        $bag->add('password', 'Too short.');
        $bag->add('password', 'Must contain a digit.');
        $bag->add('password', 'Must contain a symbol.');
        self::assertCount(3, $bag->errorsFor('password'));
    }
}
