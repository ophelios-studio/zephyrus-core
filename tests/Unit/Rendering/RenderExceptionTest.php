<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Rendering;

use PHPUnit\Framework\TestCase;
use Zephyrus\Exceptions\ZephyrusRuntimeException;
use Zephyrus\Rendering\RenderException;

final class RenderExceptionTest extends TestCase
{
    public function testExtendsZephyrusRuntimeException(): void
    {
        $exception = new RenderException('test');
        self::assertInstanceOf(ZephyrusRuntimeException::class, $exception);
    }

    public function testTemplateNotFound(): void
    {
        $exception = RenderException::templateNotFound('users/show', '/app/views/users/show.latte');

        self::assertStringContainsString('users/show', $exception->getMessage());
        self::assertStringContainsString('/app/views/users/show.latte', $exception->getMessage());
    }

    public function testRenderFailed(): void
    {
        $previous = new \RuntimeException('Syntax error on line 5');
        $exception = RenderException::renderFailed('page', $previous);

        self::assertStringContainsString('page', $exception->getMessage());
        self::assertStringContainsString('Syntax error on line 5', $exception->getMessage());
        self::assertSame($previous, $exception->getPrevious());
    }

    public function testEngineError(): void
    {
        $exception = RenderException::engineError('Cache directory not writable');

        self::assertSame('Cache directory not writable', $exception->getMessage());
    }

    public function testEngineErrorWithPrevious(): void
    {
        $previous = new \RuntimeException('Permission denied');
        $exception = RenderException::engineError('Failed to create cache', $previous);

        self::assertSame('Failed to create cache', $exception->getMessage());
        self::assertSame($previous, $exception->getPrevious());
    }
}
