<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Zephyrus\Controller\Controller;
use Zephyrus\Core\ApplicationBuilder;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Route;
use Zephyrus\Routing\Router;

final class ApplicationBootstrapLocalizationTest extends TestCase
{
    public function testBootstrapCanServeLocalizedEndpointUsingApplicationBuilder(): void
    {
        $router = (new Router())
            ->controller(ApplicationBootstrapLocalizedController::class);

        $app = ApplicationBuilder::create()
            ->withRouter($router)
            ->withJsonLocales(__DIR__ . '/../Fixtures/locales', defaultLocale: 'en')
            ->build();

        $response = $app->handle(Request::fromArray('GET', '/welcome/fr/alice'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Hello ALICE \/ alice \/ Alice', $response->body);
    }
}

final class ApplicationBootstrapLocalizedController extends Controller
{
    #[Route('/welcome/{locale}/{name}', 'GET')]
    public function show(string $locale, string $name): Response
    {
        $app = ApplicationBuilder::create()
            ->withJsonLocales(__DIR__ . '/../Fixtures/locales', defaultLocale: 'en')
            ->build();

        return $this->json([
            'message' => $app->trans('messages.pipe_text', ['name' => $name], $locale),
        ]);
    }
}
