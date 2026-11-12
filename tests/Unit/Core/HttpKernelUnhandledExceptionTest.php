<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use Zephyrus\Core\KernelBuilder;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Router;
use Zephyrus\Security\SecureHeadersConfig;
use Zephyrus\Security\SecureHeadersMiddleware;
use Zephyrus\Tests\Support\CapturedErrorLog;

final class HttpKernelUnhandledExceptionTest extends TestCase
{
    use CapturedErrorLog;

    private const string MESSAGE = UnhandledExceptionController::MESSAGE;

    protected function setUp(): void
    {
        $this->captureErrorLog();
    }

    protected function tearDown(): void
    {
        $this->releaseErrorLog();
    }

    public function testAnExceptionAHandlerRethrowsIsAnsweredWithAGeneric500(): void
    {
        $kernel = self::builder()
            ->withExceptionHandler(RuntimeException::class, static fn (Throwable $e) => throw $e)
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/boom'));

        self::assertSame(500, $response->status);
        self::assertStringNotContainsString(self::MESSAGE, $response->body);
    }

    public function testAnExceptionAHandlerRethrowsIsWrittenToTheErrorLog(): void
    {
        $kernel = self::builder()
            ->withExceptionHandler(RuntimeException::class, static fn (Throwable $e) => throw $e)
            ->build();

        $kernel->handle(Request::fromArray('GET', '/boom'));

        self::assertStringContainsString(RuntimeException::class . ': ' . self::MESSAGE, $this->capturedErrorLog());
    }

    public function testTheGeneric500OfARethrownExceptionCarriesTheGlobalDecorations(): void
    {
        $kernel = self::builder()
            ->withMiddleware(new SecureHeadersMiddleware(SecureHeadersConfig::defaults()))
            ->withExceptionHandler(RuntimeException::class, static fn (Throwable $e) => throw $e)
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/boom'));

        self::assertSame('SAMEORIGIN', $response->headers['x-frame-options']);
    }

    private static function builder(): KernelBuilder
    {
        return KernelBuilder::create()
            ->withRouter((new Router())->get('/boom', UnhandledExceptionController::class . '@boom'));
    }
}

final class UnhandledExceptionController
{
    public const string MESSAGE = 'connection refused for db.example.test';

    public function boom(): Response
    {
        throw new RuntimeException(self::MESSAGE);
    }
}
