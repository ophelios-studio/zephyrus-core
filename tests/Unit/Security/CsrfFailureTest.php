<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Zephyrus\Security\CsrfFailure;

final class CsrfFailureTest extends TestCase
{
    public function testEachCaseHasAStableStringValueForLogs(): void
    {
        self::assertSame('token_missing', CsrfFailure::TokenMissing->value);
        self::assertSame('token_invalid', CsrfFailure::TokenInvalid->value);
    }
}
