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

    /**
     * Proves that the full app bootstrap + Accept-Language header path resolves
     * the locale correctly via transFromRequest(), with no explicit locale arg.
     */
    public function testTransFromRequestResolvesLocaleFromAcceptLanguageHeader(): void
    {
        $app = ApplicationBuilder::create()
            ->withJsonLocales(__DIR__ . '/../Fixtures/locales', defaultLocale: 'en')
            ->withSupportedLocales(['en', 'fr'])
            ->build();

        // Browser-style header: fr-CA preferred; regional fallback → fr (in supported list)
        $request = Request::fromArray(
            'GET',
            '/',
            headers: ['accept-language' => 'fr-CA,fr;q=0.9,en-US;q=0.8,en;q=0.7'],
        );

        self::assertSame('Bonjour', $app->transFromRequest('messages.plain', request: $request));
    }

    public function testTransFromRequestFallsToDefaultWhenNoSupportedLocaleMatches(): void
    {
        $app = ApplicationBuilder::create()
            ->withJsonLocales(__DIR__ . '/../Fixtures/locales', defaultLocale: 'en')
            ->withSupportedLocales(['en', 'fr'])
            ->build();

        $request = Request::fromArray(
            'GET',
            '/',
            headers: ['accept-language' => 'de, es'],
        );

        self::assertSame('Hello', $app->transFromRequest('messages.plain', request: $request));
    }

    public function testTransFromRequestExplicitRequestedLocaleOverridesHeader(): void
    {
        $app = ApplicationBuilder::create()
            ->withJsonLocales(__DIR__ . '/../Fixtures/locales', defaultLocale: 'en')
            ->withSupportedLocales(['en', 'fr'])
            ->build();

        // Header says en, but cookie/URL locale override says fr
        $request = Request::fromArray(
            'GET',
            '/',
            headers: ['accept-language' => 'en'],
        );

        self::assertSame('Bonjour', $app->transFromRequest(
            'messages.plain',
            request:         $request,
            requestedLocale: 'fr',
        ));
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
