<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Throwable;
use Zephyrus\Core\App;
use Zephyrus\Core\Application;
use Zephyrus\Core\ApplicationBuilder;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Core\KernelBuilder;
use Zephyrus\Http\Request;
use Zephyrus\Routing\Router;
use Zephyrus\Tests\Support\CapturedErrorLog;
use Zephyrus\Tests\Support\UnhandledExceptionController;

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
        self::assertStringNotContainsString(UnhandledExceptionController::MESSAGE, $response->body);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testADebugBootPassesARethrownExceptionToTheDebuggerRenderingForTheClient(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $application = self::servedOverHttp(self::rethrowingBuilder(debug: true))->build();

        $thrown = self::thrownBy($application);

        self::assertSame([RuntimeException::class, UnhandledExceptionController::MESSAGE], [get_debug_type($thrown), $thrown?->getMessage()]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testADebugBootPassesAnExceptionNoHandlerAnswersToTheDebuggerRenderingForTheClient(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $application = self::servedOverHttp(self::builder(debug: true))->build();

        $thrown = self::thrownBy($application);

        self::assertSame([RuntimeException::class, UnhandledExceptionController::MESSAGE], [get_debug_type($thrown), $thrown?->getMessage()]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testADebugBootAnswersARethrownExceptionForAClientTheDebuggerDoesNotRenderFor(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.77';
        $application = self::servedOverHttp(self::rethrowingBuilder(debug: true))->build();

        $response = $application->handle(Request::fromArray('GET', '/boom'));

        self::assertSame(500, $response->status);
        self::assertStringContainsString(RuntimeException::class . ': ' . UnhandledExceptionController::MESSAGE, $this->capturedErrorLog());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testADebugBootInTheCommandLinePassesAnUnhandledExceptionOn(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        $application = self::builder(debug: true)->build();

        $thrown = self::thrownBy($application);

        self::assertSame([RuntimeException::class, UnhandledExceptionController::MESSAGE], [get_debug_type($thrown), $thrown?->getMessage()]);
    }

    public function testABuildWithoutDebugKeepsUnhandledExceptionsRethrownByTheKernelBuilder(): void
    {
        $builder = new ApplicationBuilder(KernelBuilder::create()->withUnhandledExceptionsRethrown());
        $application = $builder
            ->withConfiguration(self::configuration(debug: false))
            ->withRouter(self::router())
            ->build();

        $thrown = self::thrownBy($application);

        self::assertSame([RuntimeException::class, UnhandledExceptionController::MESSAGE], [get_debug_type($thrown), $thrown?->getMessage()]);
    }

    private static function thrownBy(Application $application): ?Throwable
    {
        try {
            $application->handle(Request::fromArray('GET', '/boom'));
        } catch (Throwable $thrown) {
            return $thrown;
        }

        return null;
    }

    private static function rethrowingBuilder(bool $debug): ApplicationBuilder
    {
        return self::builder($debug)->withExceptionHandler(Throwable::class, static fn (Throwable $e) => throw $e);
    }

    private static function builder(bool $debug): ApplicationBuilder
    {
        return ApplicationBuilder::fromConfiguration(self::configuration($debug))->withRouter(self::router());
    }

    /** Builds as a web server SAPI would, where only a client the debugger admits gets the exception. */
    private static function servedOverHttp(ApplicationBuilder $builder): ApplicationBuilder
    {
        (new ReflectionProperty(ApplicationBuilder::class, 'serverApi'))->setValue($builder, 'fpm-fcgi');

        return $builder;
    }

    private static function configuration(bool $debug): Configuration
    {
        return Configuration::fromArray(['application' => ['environment' => 'development', 'debug' => $debug]]);
    }

    private static function router(): Router
    {
        return (new Router())->get('/boom', UnhandledExceptionController::class . '@boom');
    }
}
