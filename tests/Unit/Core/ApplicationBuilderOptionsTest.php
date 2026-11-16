<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\App;
use Zephyrus\Core\ApplicationBuilder;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Localization\LocaleLoaderInterface;
use Zephyrus\Rendering\RenderEngine;
use Zephyrus\Routing\Router;
use Zephyrus\Security\CsrfConfig;
use Zephyrus\Security\CsrfMiddleware;
use Zephyrus\Security\CsrfTokenManagerInterface;
use Zephyrus\Security\ForceHttpsMiddleware;

final class ApplicationBuilderOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        App::reset();
    }

    protected function tearDown(): void
    {
        App::reset();

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: \Closure(ApplicationBuilder): ApplicationBuilder}>
     */
    public static function builderOptions(): array
    {
        return [
            'router' => [static fn (ApplicationBuilder $b): ApplicationBuilder => $b->withRouter(new Router())],
            'global middleware' => [static fn (ApplicationBuilder $b): ApplicationBuilder => $b->withMiddleware(self::middleware())],
            'named middleware' => [static fn (ApplicationBuilder $b): ApplicationBuilder => $b->registerMiddleware('named', self::middleware())],
            'controller factory' => [static fn (ApplicationBuilder $b): ApplicationBuilder => $b->withControllerFactory(static fn (string $class): object => new $class())],
            'render engine' => [static fn (ApplicationBuilder $b): ApplicationBuilder => $b->withRenderEngine(new class implements RenderEngine {
                public function render(string $page, array $args = []): string
                {
                    return '';
                }

                public function exists(string $page): bool
                {
                    return false;
                }
            })],
            'exception handler' => [static fn (ApplicationBuilder $b): ApplicationBuilder => $b->withExceptionHandler(\RuntimeException::class, static fn (): Response => Response::text('x'))],
            'locale loader' => [static fn (ApplicationBuilder $b): ApplicationBuilder => $b->withLocaleLoader(self::emptyLoader())],
            'supported locales' => [static fn (ApplicationBuilder $b): ApplicationBuilder => $b->withSupportedLocales(['en'])],
            'acknowledged security keys' => [static fn (ApplicationBuilder $b): ApplicationBuilder => $b->withAcknowledgedSecurityKeys(['csrf'])],
            'debug client allowlist' => [static fn (ApplicationBuilder $b): ApplicationBuilder => $b->withDebugClientAllowlist('203.0.113.7')],
            'security wiring check' => [static fn (ApplicationBuilder $b): ApplicationBuilder => $b->withoutSecurityWiringCheck()],
        ];
    }

    /**
     * @param \Closure(ApplicationBuilder): ApplicationBuilder $apply
     */
    #[DataProvider('builderOptions')]
    public function testAnOptionReturnsANewBuilderInsteadOfChangingTheOriginal(\Closure $apply): void
    {
        $original = ApplicationBuilder::create();

        $modified = $apply($original);

        self::assertNotSame($original, $modified);
    }

    public function testOptionalConfigurationFilesCanBeLoadedThroughThePublicStaticFactory(): void
    {
        $builder = ApplicationBuilder::fromOptionalConfigurationFiles(['/nonexistent/zephyrus-app.php']);

        self::assertInstanceOf(ApplicationBuilder::class, $builder);
    }

    public function testNoJsonLocaleLayersBuildAnApplicationWithoutTranslations(): void
    {
        $app = ApplicationBuilder::create()->withJsonLocaleLayers([])->build();

        self::assertSame('missing.key', $app->trans('missing.key'));
    }

    public function testBuildingWithoutAConfigurationRaisesNoWarning(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $app = ApplicationBuilder::create()->build();
        } finally {
            restore_error_handler();
        }

        self::assertSame('missing.key', $app->trans('missing.key'));
    }

    public function testAcknowledgedSecurityKeysAccumulateAcrossCalls(): void
    {
        $app = ApplicationBuilder::create()
            ->withConfigurationArray(['security' => ['force_https' => true, 'csrf' => ['enabled' => true]]])
            ->withAcknowledgedSecurityKeys(['forceHttps'])
            ->withAcknowledgedSecurityKeys(['csrf'])
            ->build();

        self::assertSame('missing.key', $app->trans('missing.key'));
    }

    public function testAForceHttpsMiddlewareMountedOnlyOnARouteIsNamedInTheRefusal(): void
    {
        try {
            $ignored = ApplicationBuilder::create()
                ->withConfigurationArray(['security' => ['force_https' => true]])
                ->registerMiddleware('https', new ForceHttpsMiddleware())
                ->build();
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            self::assertSame(
                "Configuration declares security settings that this application does not enforce as declared:\n"
                . "  - security.forceHttps is not enforced as declared: mount " . ForceHttpsMiddleware::class
                . " with withMiddleware(); it is registered only under route name 'https'\n\n"
                . 'Apply the fix given for each setting, or acknowledge the gap explicitly with '
                . 'ApplicationBuilder::withAcknowledgedSecurityKeys([...]) when the protection is provided '
                . 'elsewhere (a reverse proxy, a wrapping middleware, the web server).',
                $e->getMessage(),
            );
        }
    }

    public function testADeclaredCsrfExceptionListedTwiceIsNamedOnceInTheRefusal(): void
    {
        $tokens = new class implements CsrfTokenManagerInterface {
            public function getToken(): string
            {
                return 'token';
            }

            public function isTokenValid(string $token): bool
            {
                return $token === 'token';
            }
        };

        try {
            $ignored = ApplicationBuilder::create()
                ->withConfigurationArray(['security' => ['csrf' => ['enabled' => true, 'exceptions' => ['/a', '/a']]]])
                ->withMiddleware(new CsrfMiddleware($tokens, CsrfConfig::defaults()))
                ->build();
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            self::assertSame(
                "Configuration declares security settings that this application does not enforce as declared:\n"
                . '  - security.csrf is not enforced as declared: the global ' . CsrfMiddleware::class
                . ' does not exclude "/a", which security.csrf.exceptions lists; remove it from '
                . "security.csrf.exceptions, or build the middleware with CsrfConfig::fromSecurityConfig()\n\n"
                . 'Apply the fix given for each setting, or acknowledge the gap explicitly with '
                . 'ApplicationBuilder::withAcknowledgedSecurityKeys([...]) when the protection is provided '
                . 'elsewhere (a reverse proxy, a wrapping middleware, the web server).',
                $e->getMessage(),
            );
        }
    }

    private static function middleware(): MiddlewareInterface
    {
        return new class implements MiddlewareInterface {
            public function process(Request $request, callable $next): Response
            {
                return $next($request);
            }
        };
    }

    private static function emptyLoader(): LocaleLoaderInterface
    {
        return new class implements LocaleLoaderInterface {
            public function load(string $locale): array
            {
                return [];
            }
        };
    }
}
