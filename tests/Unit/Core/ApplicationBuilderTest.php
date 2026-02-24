<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Application;
use Zephyrus\Core\ApplicationBuilder;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Localization\LocaleLoaderInterface;
use Zephyrus\Routing\Router;

final class ApplicationBuilderTest extends TestCase
{
    public function testBuildReturnsApplication(): void
    {
        $app = ApplicationBuilder::create()->build();

        self::assertInstanceOf(Application::class, $app);
    }

    public function testHandleDelegatesToKernel(): void
    {
        $router = (new Router())->get('/health', ApplicationBuilderFixtureController::class . '@health');

        $app = ApplicationBuilder::create()
            ->withRouter($router)
            ->build();

        $response = $app->handle(Request::fromArray('GET', '/health'));

        self::assertSame(200, $response->status);
        self::assertSame('ok', $response->body);
    }

    public function testWithJsonLocalesWiresTranslator(): void
    {
        $app = ApplicationBuilder::create()
            ->withJsonLocales(__DIR__ . '/../../Fixtures/locales', defaultLocale: 'en')
            ->build();

        self::assertSame('Bonjour', $app->trans('messages.plain', locale: 'fr'));
        self::assertSame('Welcome Bob', $app->trans('messages.welcome', ['name' => 'Bob'], 'fr'));
    }

    public function testWithLocaleLoaderOverridesDefaultTranslatorSource(): void
    {
        $loader = new class implements LocaleLoaderInterface {
            public function load(string $locale): array
            {
                return ['greet' => $locale === 'es' ? 'Hola {name}' : 'Hello {name}'];
            }
        };

        $app = ApplicationBuilder::create()
            ->withLocaleLoader($loader, defaultLocale: 'en')
            ->build();

        self::assertSame('Hola Alice', $app->trans('greet', ['name' => 'Alice'], 'es'));
    }

    public function testWithMiddlewarePassesThroughToKernelBuilder(): void
    {
        $router = (new Router())->get('/health', ApplicationBuilderFixtureController::class . '@health');

        $app = ApplicationBuilder::create()
            ->withRouter($router)
            ->withMiddleware(new class implements MiddlewareInterface {
                public function process(Request $request, callable $next): Response
                {
                    return $next($request)->withHeader('X-App', 'yes');
                }
            })
            ->build();

        $response = $app->handle(Request::fromArray('GET', '/health'));

        self::assertSame('yes', $response->headers['X-App']);
    }
}

final class ApplicationBuilderFixtureController
{
    public function health(): Response
    {
        return Response::text('ok');
    }
}
