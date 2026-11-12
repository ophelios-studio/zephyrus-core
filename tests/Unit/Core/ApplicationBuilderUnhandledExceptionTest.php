<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use Zephyrus\Core\App;
use Zephyrus\Core\ApplicationBuilder;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Router;
use Zephyrus\Tests\Support\CapturedErrorLog;

final class ApplicationBuilderUnhandledExceptionTest extends TestCase
{
    use CapturedErrorLog;

    private string $displayErrors;

    protected function setUp(): void
    {
        $this->displayErrors = (string) ini_get('display_errors');
        $this->captureErrorLog();
    }

    protected function tearDown(): void
    {
        $this->releaseErrorLog();
        ini_set('display_errors', $this->displayErrors);
        App::reset();
    }

    public function testADebugOffBootAnswersARethrownExceptionWithAGeneric500(): void
    {
        $application = self::rethrowingBuilder(debug: false)->build();

        $response = $application->handle(Request::fromArray('GET', '/boom'));

        self::assertSame(500, $response->status);
        self::assertStringNotContainsString(BootFailureController::MESSAGE, $response->body);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testADebugBootPassesARethrownExceptionToTheDebuggerRenderingForTheClient(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $application = self::rethrowingBuilder(debug: true)->build();

        try {
            $application->handle(Request::fromArray('GET', '/boom'));
            self::fail('expected the exception to leave handle()');
        } catch (RuntimeException $exception) {
            self::assertSame(BootFailureController::MESSAGE, $exception->getMessage());
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testADebugBootAnswersARethrownExceptionForAClientTheDebuggerDoesNotRenderFor(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.77';
        $application = self::rethrowingBuilder(debug: true)->build();

        $response = $application->handle(Request::fromArray('GET', '/boom'));

        self::assertSame(500, $response->status);
        self::assertStringContainsString(RuntimeException::class . ': ' . BootFailureController::MESSAGE, $this->capturedErrorLog());
    }

    private static function rethrowingBuilder(bool $debug): ApplicationBuilder
    {
        return ApplicationBuilder::fromConfiguration(Configuration::fromArray([
            'application' => ['environment' => 'development', 'debug' => $debug],
        ]))
            ->withRouter((new Router())->get('/boom', BootFailureController::class . '@boom'))
            ->withExceptionHandler(Throwable::class, static fn (Throwable $e) => throw $e);
    }
}

final class BootFailureController
{
    public const string MESSAGE = 'connection refused for db.example.test';

    public function boom(): Response
    {
        throw new RuntimeException(self::MESSAGE);
    }
}
