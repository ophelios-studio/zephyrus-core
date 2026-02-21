<?php

declare(strict_types=1);

namespace Zephyrus2\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Zephyrus2\Core\Kernel;

final class KernelTest extends TestCase
{
    public function testVersionReturnsDevSemverString(): void
    {
        $kernel = new Kernel();

        self::assertMatchesRegularExpression('/^\\d+\\.\\d+\\.\\d+(-[a-z0-9.]+)?$/i', $kernel->version());
    }
}
