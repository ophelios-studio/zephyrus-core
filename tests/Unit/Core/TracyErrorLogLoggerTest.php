<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tracy\ILogger;
use Zephyrus\Core\TracyErrorLogLogger;
use Zephyrus\Tests\Support\CapturedErrorLog;

final class TracyErrorLogLoggerTest extends TestCase
{
    use CapturedErrorLog;

    protected function setUp(): void
    {
        $this->captureErrorLog();
    }

    protected function tearDown(): void
    {
        $this->releaseErrorLog();
    }

    public function testAStringValueCannotForgeALogLine(): void
    {
        (new TracyErrorLogLogger())->log("first\r\nforged line", ILogger::ERROR);

        self::assertCount(1, array_filter(explode("\n", $this->capturedErrorLog())));
        self::assertStringContainsString('first\r\nforged line', $this->capturedErrorLog());
    }

    public function testAThrowableWhoseStringConversionThrowsIsStillLogged(): void
    {
        $exception = new UnprintableException('broken');

        (new TracyErrorLogLogger())->log($exception, ILogger::EXCEPTION);

        self::assertStringContainsString(
            'Zephyrus: debugger exception: ' . $exception::class . ': broken',
            $this->capturedErrorLog(),
        );
    }

    public function testAThrowableIsLoggedWithItsTrace(): void
    {
        (new TracyErrorLogLogger())->log(new RuntimeException('boom'), ILogger::EXCEPTION);

        self::assertStringContainsString(RuntimeException::class . ': boom', $this->capturedErrorLog());
    }
}

final class UnprintableException extends RuntimeException
{
    public function __toString(): string
    {
        throw new RuntimeException('conversion failed');
    }
}
