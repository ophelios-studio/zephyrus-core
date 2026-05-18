<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Inertia;

use PHPUnit\Framework\TestCase;
use Zephyrus\Controller\Controller;
use Zephyrus\Core\App;
use Zephyrus\Core\ApplicationBuilder;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Inertia\Inertia;
use Zephyrus\Inertia\InertiaRenderer;
use Zephyrus\Rendering\RenderException;
use Zephyrus\Rendering\RenderResponses;
use Zephyrus\Routing\Router;

final class InertiaTest extends TestCase
{
    private string $rootView;

    protected function setUp(): void
    {
        $this->rootView = __DIR__ . '/fixtures/app.php';
    }

    protected function tearDown(): void
    {
        App::reset();
    }

    public function testInitialVisitReturnsHtmlShell(): void
    {
        $inertia = new InertiaRenderer($this->rootView, 'abc123');
        $request = Request::fromArray('GET', 'https://example.test/users?page=2');

        $response = $inertia->render($request, 'Users/Index', ['users' => [
            ['name' => 'Alice']
        ]]);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->status);
        self::assertSame('text/html; charset=utf-8', $response->headers['content-type']);
        self::assertSame('X-Inertia', $response->headers['vary']);
        self::assertStringContainsString('id="app"', $response->body);
        self::assertStringContainsString('Users/Index', $response->body);
        self::assertStringContainsString('abc123', $response->body);
        self::assertStringContainsString('/users?page=2', $response->body);
    }

    public function testInertiaVisitReturnsJsonPage(): void
    {
        $inertia = new InertiaRenderer($this->rootView, 'abc123');
        $request = Request::fromArray('GET', 'https://example.test/users', headers: [
            'X-Inertia' => 'true',
        ]);

        $response = $inertia->render($request, 'Users/Index', ['users' => []]);
        $payload = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->headers['content-type']);
        self::assertSame('true', $response->headers['x-inertia']);
        self::assertSame('X-Inertia', $response->headers['vary']);
        self::assertSame('Users/Index', $payload['component']);
        self::assertSame([
            'errors' => [],
            'users' => [],
        ], $payload['props']);
        self::assertSame('/users', $payload['url']);
        self::assertSame('abc123', $payload['version']);
    }

    public function testSharedPropsAreMergedIntoPageProps(): void
    {
        $inertia = new InertiaRenderer($this->rootView);
        $inertia->share('auth', ['user' => ['id' => 123]]);
        $request = Request::fromArray('GET', 'https://example.test/dashboard', headers: [
            'X-Inertia' => 'true',
        ]);

        $response = $inertia->render($request, 'Dashboard', ['stats' => ['visits' => 10]]);
        $payload = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([
            'errors' => [],
            'auth' => ['user' => ['id' => 123]],
            'stats' => ['visits' => 10],
        ], $payload['props']);
    }

    public function testPartialReloadIncludesOnlyRequestedPropsForSameComponent(): void
    {
        $inertia = new InertiaRenderer($this->rootView);
        $inertia->share('appName', 'Zephyrus');
        $request = Request::fromArray('GET', 'https://example.test/dashboard', headers: [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Data' => 'stats',
        ]);

        $response = $inertia->render($request, 'Dashboard', [
            'stats' => ['visits' => 10],
            'users' => [['name' => 'Alice']],
        ]);
        $payload = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(['stats', 'errors'], array_keys($payload['props']));
        self::assertSame(['visits' => 10], $payload['props']['stats']);
        self::assertSame([], $payload['props']['errors']);
    }

    public function testPartialReloadExceptExcludesRequestedPropsForSameComponent(): void
    {
        $inertia = new InertiaRenderer($this->rootView);
        $request = Request::fromArray('GET', 'https://example.test/dashboard', headers: [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Except' => 'users',
        ]);

        $response = $inertia->render($request, 'Dashboard', [
            'stats' => ['visits' => 10],
            'users' => [['name' => 'Alice']],
        ]);
        $payload = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('errors', $payload['props']);
        self::assertArrayHasKey('stats', $payload['props']);
        self::assertArrayNotHasKey('users', $payload['props']);
    }

    public function testVersionConflictReturnsInertiaLocationResponse(): void
    {
        $inertia = new InertiaRenderer($this->rootView, 'current-build');
        $request = Request::fromArray('GET', 'https://example.test/users?page=2', headers: [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => 'old-build',
        ]);

        $response = $inertia->render($request, 'Users/Index');

        self::assertSame(409, $response->status);
        self::assertSame('https://example.test/users?page=2', $response->headers['x-inertia-location']);
    }

    public function testInertiaLocationUsesConflictResponse(): void
    {
        $inertia = new InertiaRenderer($this->rootView);
        $request = Request::fromArray('GET', 'https://example.test/dashboard', headers: [
            'X-Inertia' => 'true',
        ]);

        $response = $inertia->location($request, 'https://example.test/login');

        self::assertSame(409, $response->status);
        self::assertSame('https://example.test/login', $response->headers['x-inertia-location']);
    }

    public function testMissingRootViewThrowsRenderException(): void
    {
        $inertia = new InertiaRenderer(__DIR__ . '/fixtures/missing.php');
        $request = Request::fromArray('GET', 'https://example.test/users');

        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('Inertia root view is not readable');
        $inertia->render($request, 'Users/Index');
    }

    public function testFacadeRendersUsingConfiguredRendererAndCurrentRequest(): void
    {
        Inertia::configure($this->rootView, 'abc123');
        App::setRequest(Request::fromArray('GET', 'https://example.test/users', headers: [
            'X-Inertia' => 'true',
        ]));

        $response = Inertia::render('Users/Index', ['users' => []]);
        $payload = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('Users/Index', $payload['component']);
        self::assertSame([
            'errors' => [],
            'users' => [],
        ], $payload['props']);
        self::assertSame('/users', $payload['url']);
        self::assertSame('abc123', $payload['version']);
    }

    public function testFacadeThrowsWhenRendererIsMissing(): void
    {
        App::setRequest(Request::fromArray('GET', 'https://example.test/users'));

        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('No Inertia renderer has been configured');
        Inertia::render('Users/Index');
    }

    public function testFacadeThrowsWhenCurrentRequestIsMissing(): void
    {
        Inertia::configure($this->rootView);

        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('No current request is available');
        Inertia::render('Users/Index');
    }

    public function testApplicationBuilderMakesInertiaAvailableInControllers(): void
    {
        $router = (new Router())
            ->get('/dashboard', InertiaFixtureController::class . '@dashboard')
            ->get('/settings', InertiaFixtureController::class . '@settings')
            ->put('/profile', InertiaFixtureController::class . '@update');

        $app = ApplicationBuilder::create()
            ->withRouter($router)
            ->withInertia($this->rootView, 'build-1', ['appName' => 'Zephyrus'])
            ->build();

        $response = $app->handle(Request::fromArray('GET', 'https://example.test/dashboard', headers: [
            'X-Inertia' => 'true',
        ]));
        $payload = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('Dashboard', $payload['component']);
        self::assertSame([
            'errors' => [],
            'appName' => 'Zephyrus',
            'stats' => ['visits' => 10],
        ], $payload['props']);
        self::assertSame('build-1', $payload['version']);

        $shortcutResponse = $app->handle(Request::fromArray('GET', 'https://example.test/settings', headers: [
            'X-Inertia' => 'true',
        ]));
        $shortcutPayload = json_decode($shortcutResponse->body, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('Settings', $shortcutPayload['component']);

        $redirectResponse = $app->handle(Request::fromArray('PUT', 'https://example.test/profile', headers: [
            'X-Inertia' => 'true',
        ]));

        self::assertSame(303, $redirectResponse->status);
        self::assertSame('X-Inertia', $redirectResponse->headers['vary']);
    }
}

final class InertiaFixtureController extends Controller
{
    use RenderResponses;
    
    public function dashboard(): Response
    {
        return Inertia::render('Dashboard', [
            'stats' => ['visits' => 10],
        ]);
    }

    public function settings(): Response
    {
        return $this->inertia('Settings');
    }
}
