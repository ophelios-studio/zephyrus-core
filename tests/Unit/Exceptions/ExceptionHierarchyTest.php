<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Exceptions\ZephyrusException;
use Zephyrus\Exceptions\ZephyrusRuntimeException;
use Zephyrus\Routing\Exception\RouteNotFoundException;

final class ExceptionHierarchyTest extends TestCase
{
    public function testRuntimeExceptionsExtendZephyrusRuntimeException(): void
    {
        $exception = new RouteNotFoundException('missing');

        self::assertInstanceOf(ZephyrusRuntimeException::class, $exception);
        self::assertInstanceOf(ZephyrusException::class, $exception);
    }

    public function testConfigurationExceptionExtendsZephyrusException(): void
    {
        $exception = ConfigurationException::missingRequired('database', 'database');

        self::assertInstanceOf(ZephyrusException::class, $exception);
    }
}
