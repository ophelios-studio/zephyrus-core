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
use Zephyrus\Inertia\InertiaMiddleware;
use Zephyrus\Inertia\InertiaRenderer;
use Zephyrus\Inertia\InertiaView;
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

    public function testSharedPropsCanBeMergedFromArrayAndOverriddenByPageProps(): void
    {
        $inertia = new InertiaRenderer($this->rootView);
        $inertia->share([
            'auth' => ['user' => ['id' => 123]],
            'theme' => 'light',
        ]);
        $request = Request::fromArray('GET', 'https://example.test/dashboard', headers: [
            'X-Inertia' => 'true',
        ]);

        $response = $inertia->render($request, 'Dashboard', [
            'theme' => 'dark',
        ]);
        $payload = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([
            'errors' => [],
            'auth' => ['user' => ['id' => 123]],
            'theme' => 'dark',
        ], $payload['props']);
    }

    public function testVersionCanBeChangedAndDisabled(): void
    {
        $inertia = new InertiaRenderer($this->rootView, 'old');

        self::assertSame($inertia, $inertia->version('new'));
        self::assertSame('new', $inertia->getVersion());

        $inertia->version(null);

        self::assertNull($inertia->getVersion());
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

    public function testPartialReloadTrimsCsvHeaderEntries(): void
    {
        $inertia = new InertiaRenderer($this->rootView);
        $request = Request::fromArray('GET', 'https://example.test/dashboard', headers: [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Data' => ' stats, , users ',
        ]);

        $response = $inertia->render($request, 'Dashboard', [
            'stats' => ['visits' => 10],
            'users' => [['name' => 'Alice']],
            'metrics' => ['bounce' => 20],
        ]);
        $payload = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(['stats', 'users', 'errors'], array_keys($payload['props']));
    }

    public function testPartialReloadIgnoresHeadersForDifferentComponent(): void
    {
        $inertia = new InertiaRenderer($this->rootView);
        $request = Request::fromArray('GET', 'https://example.test/dashboard', headers: [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'Settings',
            'X-Inertia-Partial-Data' => 'stats',
        ]);

        $response = $inertia->render($request, 'Dashboard', [
            'stats' => ['visits' => 10],
            'users' => [['name' => 'Alice']],
        ]);
        $payload = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(['errors', 'stats', 'users'], array_keys($payload['props']));
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

    public function testPartialReloadExceptNeverRemovesErrors(): void
    {
        $inertia = new InertiaRenderer($this->rootView);
        $request = Request::fromArray('GET', 'https://example.test/dashboard', headers: [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Except' => 'errors, users',
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

    public function testHasVersionConflictReturnsFalseForFreshOrIneligibleRequests(): void
    {
        $inertia = new InertiaRenderer($this->rootView, 'current-build');

        self::assertFalse($inertia->hasVersionConflict(Request::fromArray('GET', 'https://example.test/users')));
        self::assertFalse($inertia->hasVersionConflict(Request::fromArray('POST', 'https://example.test/users', headers: [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => 'old-build',
        ])));
        self::assertFalse($inertia->hasVersionConflict(Request::fromArray('GET', 'https://example.test/users', headers: [
            'X-Inertia' => 'true',
        ])));
        self::assertFalse($inertia->hasVersionConflict(Request::fromArray('GET', 'https://example.test/users', headers: [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => 'current-build',
        ])));

        $unversioned = new InertiaRenderer($this->rootView);
        self::assertFalse($unversioned->hasVersionConflict(Request::fromArray('GET', 'https://example.test/users', headers: [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => 'old-build',
        ])));
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

    public function testRegularLocationUsesRedirectResponse(): void
    {
        $inertia = new InertiaRenderer($this->rootView);
        $request = Request::fromArray('GET', 'https://example.test/dashboard');

        $response = $inertia->location($request, 'https://example.test/login');

        self::assertSame(302, $response->status);
        self::assertSame('https://example.test/login', $response->headers['location']);
        self::assertArrayNotHasKey('x-inertia-location', $response->headers);
    }

    public function testIsInertiaRequestIsCaseInsensitiveAndTrimmed(): void
    {
        $inertia = new InertiaRenderer($this->rootView);

        self::assertTrue($inertia->isInertiaRequest(Request::fromArray('GET', 'https://example.test/users', headers: [
            'X-Inertia' => ' TRUE ',
        ])));
        self::assertFalse($inertia->isInertiaRequest(Request::fromArray('GET', 'https://example.test/users')));
    }

    public function testRootViewRenderFailureCleansOutputBufferAndPageState(): void
    {
        $tempDir = sys_get_temp_dir() . '/zephyrus_inertia_view_test_' . uniqid();
        mkdir($tempDir, 0755, true);
        $view = $tempDir . '/broken.php';
        file_put_contents($view, '<?php echo "partial"; throw new RuntimeException("broken view");');
        $level = ob_get_level();

        try {
            $inertia = new InertiaRenderer($view);
            $request = Request::fromArray('GET', 'https://example.test/users');

            try {
                $inertia->render($request, 'Users/Index');
                self::fail('Expected RenderException was not thrown.');
            } catch (RenderException $exception) {
                self::assertStringContainsString('broken view', $exception->getMessage());
            }

            self::assertSame($level, ob_get_level());
            self::assertSame('<div id="app"></div>', InertiaView::app());
        } finally {
            @unlink($view);
            @rmdir($tempDir);
        }
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

    public function testFacadeReturnsRendererConfiguredByInstance(): void
    {
        $renderer = new InertiaRenderer($this->rootView, 'abc123');

        Inertia::setRenderer($renderer);

        self::assertSame($renderer, Inertia::renderer());
    }

    public function testFacadeShareVersionLocationAndRequestDetection(): void
    {
        Inertia::configure($this->rootView);
        Inertia::share([
            'auth' => ['user' => ['id' => 123]],
        ]);
        Inertia::share('flash', 'Saved');
        Inertia::version('asset-v2');
        App::setRequest(Request::fromArray('GET', 'https://example.test/dashboard', headers: [
            'X-Inertia' => 'true',
        ]));

        self::assertTrue(Inertia::isInertiaRequest());
        self::assertSame('asset-v2', Inertia::renderer()->getVersion());

        $response = Inertia::render('Dashboard');
        $payload = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([
            'errors' => [],
            'auth' => ['user' => ['id' => 123]],
            'flash' => 'Saved',
        ], $payload['props']);

        $location = Inertia::location('https://example.test/login');
        self::assertSame(409, $location->status);
        self::assertSame('https://example.test/login', $location->headers['x-inertia-location']);
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

    public function testMiddlewareAddsInertiaVaryHeaderToNormalResponse(): void
    {
        $middleware = new InertiaMiddleware(new InertiaRenderer($this->rootView));
        $request = Request::fromArray('GET', 'https://example.test/dashboard');

        $response = $middleware->process($request, static fn (): Response => Response::text('ok'));

        self::assertSame(200, $response->status);
        self::assertSame('ok', $response->body);
        self::assertSame('X-Inertia', $response->headers['vary']);
    }

    public function testMiddlewareAppendsInertiaVaryHeader(): void
    {
        $middleware = new InertiaMiddleware(new InertiaRenderer($this->rootView));
        $request = Request::fromArray('GET', 'https://example.test/dashboard');

        $response = $middleware->process($request, static fn (): Response => Response::text('ok')->withHeader('Vary', 'Accept-Encoding'));

        self::assertSame('Accept-Encoding, X-Inertia', $response->headers['vary']);
    }

    public function testMiddlewareDoesNotDuplicateInertiaVaryHeader(): void
    {
        $middleware = new InertiaMiddleware(new InertiaRenderer($this->rootView));
        $request = Request::fromArray('GET', 'https://example.test/dashboard');

        $response = $middleware->process($request, static fn (): Response => Response::text('ok')->withHeader('Vary', 'Accept-Encoding, x-inertia'));

        self::assertSame('Accept-Encoding, x-inertia', $response->headers['vary']);
    }

    public function testMiddlewareVersionConflictReturnsLocationWithoutCallingNext(): void
    {
        $middleware = new InertiaMiddleware(new InertiaRenderer($this->rootView, 'current-build'));
        $request = Request::fromArray('GET', 'https://example.test/dashboard?tab=stats', headers: [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => 'old-build',
        ]);
        $called = false;

        $response = $middleware->process($request, function () use (&$called): Response {
            $called = true;
            return Response::text('unexpected');
        });

        self::assertFalse($called);
        self::assertSame(409, $response->status);
        self::assertSame('https://example.test/dashboard?tab=stats', $response->headers['x-inertia-location']);
        self::assertSame('X-Inertia', $response->headers['vary']);
    }

    public function testMiddlewareConvertsInertiaMutationRedirectToSeeOther(): void
    {
        $middleware = new InertiaMiddleware(new InertiaRenderer($this->rootView));
        $request = Request::fromArray('PATCH', 'https://example.test/profile', headers: [
            'X-Inertia' => 'true',
        ]);

        $response = $middleware->process($request, static fn (): Response => Response::redirect('/profile'));

        self::assertSame(303, $response->status);
        self::assertSame('/profile', $response->headers['location']);
        self::assertSame('X-Inertia', $response->headers['vary']);
    }

    public function testMiddlewareKeepsNonMutationOrNonInertiaRedirectStatus(): void
    {
        $middleware = new InertiaMiddleware(new InertiaRenderer($this->rootView));

        $getResponse = $middleware->process(
            Request::fromArray('GET', 'https://example.test/profile', headers: ['X-Inertia' => 'true']),
            static fn (): Response => Response::redirect('/profile'),
        );
        $putResponse = $middleware->process(
            Request::fromArray('PUT', 'https://example.test/profile'),
            static fn (): Response => Response::redirect('/profile'),
        );

        self::assertSame(302, $getResponse->status);
        self::assertSame(302, $putResponse->status);
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

    public function update(): Response
    {
        return Response::redirect('/profile');
    }
}
