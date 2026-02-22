<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use Zephyrus\Exceptions\ZephyrusException;
use Zephyrus\Validation\ErrorBag;
use Zephyrus\Validation\ValidationException;

final class ValidationExceptionTest extends TestCase
{
    public function testExtendsZephyrusException(): void
    {
        $e = ValidationException::fromErrorBag(new ErrorBag());

        self::assertInstanceOf(ZephyrusException::class, $e);
    }

    public function testCarriesErrorBag(): void
    {
        $bag = new ErrorBag();
        $bag->add('email', 'Invalid');

        $e = ValidationException::fromErrorBag($bag, 'Bad payload');

        self::assertSame('Bad payload', $e->getMessage());
        self::assertSame($bag, $e->errors());
        self::assertTrue($e->errors()->hasErrorsFor('email'));
    }
}
