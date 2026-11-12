<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use Zephyrus\Core\HttpKernel;
use Zephyrus\Core\KernelBuilder;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Router;
use Zephyrus\Security\SecureHeadersConfig;
use Zephyrus\Security\SecureHeadersMiddleware;
use Zephyrus\Tests\Support\CapturedErrorLog;
use Zephyrus\Tests\Support\UnhandledExceptionController;

final class HttpKernelUnhandledExceptionTest extends TestCase
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

    /**
     * @return array<string, array{0: KernelBuilder}>
     */
    public static function unansweredExceptionProvider(): array
    {
        return [
            'no handler'          => [self::builder()],
            'a declining handler' => [self::builder()->withExceptionHandler(RuntimeException::class, static fn (): ?Response => null)],
        ];
    }

    /**
     * @return array<string, array{0: KernelBuilder, 1: string, 2: int}>
     */
    public static function answeredExceptionProvider(): array
    {
        $answering = static fn (): Response => Response::text('Unavailable', 503);

        return [
            'a handler answers'          => [self::builder()->withExceptionHandler(RuntimeException::class, $answering), '/boom', 503],
            'a built-in mapping answers' => [self::builder(), '/missing', 404],
        ];
    }

    #[DataProvider('unansweredExceptionProvider')]
    public function testAnExceptionNothingAnswersIsWrittenToTheErrorLog(KernelBuilder $builder): void
    {
        $response = $builder->build()->handle(Request::fromArray('GET', '/boom'));

        self::assertSame(500, $response->status);
        self::assertStringContainsString(RuntimeException::class . ': ' . UnhandledExceptionController::MESSAGE, $this->capturedErrorLog());
    }

    #[DataProvider('answeredExceptionProvider')]
    public function testAnAnsweredExceptionIsNotLogged(KernelBuilder $builder, string $path, int $status): void
    {
        $response = $builder->build()->handle(Request::fromArray('GET', $path));

        self::assertSame($status, $response->status);
        self::assertSame('', $this->capturedErrorLog());
    }

    #[DataProvider('unansweredExceptionProvider')]
    public function testWithUnhandledExceptionsRethrownAnExceptionNothingAnswersLeavesHandle(KernelBuilder $builder): void
    {
        $kernel = $builder->withUnhandledExceptionsRethrown()->build();

        $thrown = self::thrownBy($kernel);

        self::assertSame([RuntimeException::class, UnhandledExceptionController::MESSAGE], [get_debug_type($thrown), $thrown?->getMessage()]);
    }

    #[DataProvider('answeredExceptionProvider')]
    public function testWithUnhandledExceptionsRethrownAnAnsweredExceptionKeepsItsResponse(KernelBuilder $builder, string $path, int $status): void
    {
        $response = $builder->withUnhandledExceptionsRethrown()->build()->handle(Request::fromArray('GET', $path));

        self::assertSame($status, $response->status);
    }

    public function testAnExceptionAHandlerRethrowsIsAnsweredWithAGeneric500(): void
    {
        $kernel = self::builder()
            ->withExceptionHandler(RuntimeException::class, static fn (Throwable $e) => throw $e)
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/boom'));

        self::assertSame(500, $response->status);
        self::assertStringNotContainsString(UnhandledExceptionController::MESSAGE, $response->body);
    }

    public function testAnExceptionAHandlerRethrowsIsWrittenToTheErrorLog(): void
    {
        $kernel = self::builder()
            ->withExceptionHandler(RuntimeException::class, static fn (Throwable $e) => throw $e)
            ->build();

        $kernel->handle(Request::fromArray('GET', '/boom'));

        self::assertStringContainsString(RuntimeException::class . ': ' . UnhandledExceptionController::MESSAGE, $this->capturedErrorLog());
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

    public function testAFailingHandlerIsWrittenToTheErrorLogWithTheExceptionItWasAnswering(): void
    {
        $kernel = self::failingHandlerBuilder()->build();

        $response = $kernel->handle(Request::fromArray('GET', '/boom'));

        self::assertSame(500, $response->status);
        self::assertStringContainsString(LogicException::class . ': the handler broke', $this->capturedErrorLog());
        self::assertStringContainsString(RuntimeException::class . ': ' . UnhandledExceptionController::MESSAGE, $this->capturedErrorLog());
    }

    public function testWithUnhandledExceptionsRethrownAFailingHandlerLeavesHandle(): void
    {
        $kernel = self::failingHandlerBuilder()->withUnhandledExceptionsRethrown()->build();

        $thrown = self::thrownBy($kernel);

        self::assertSame([LogicException::class, 'the handler broke'], [get_debug_type($thrown), $thrown?->getMessage()]);
    }

    private static function failingHandlerBuilder(): KernelBuilder
    {
        return self::builder()->withExceptionHandler(
            RuntimeException::class,
            static fn (): Response => throw new LogicException('the handler broke'),
        );
    }

    private static function thrownBy(HttpKernel $kernel): ?Throwable
    {
        try {
            $kernel->handle(Request::fromArray('GET', '/boom'));
        } catch (Throwable $thrown) {
            return $thrown;
        }

        return null;
    }

    private static function builder(): KernelBuilder
    {
        return KernelBuilder::create()
            ->withRouter((new Router())->get('/boom', UnhandledExceptionController::class . '@boom'));
    }
}

