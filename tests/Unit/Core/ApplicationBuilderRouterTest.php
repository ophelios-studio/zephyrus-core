<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\App;
use Zephyrus\Core\ApplicationBuilder;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Http\Request;
use Zephyrus\Routing\Router;
use Zephyrus\Tests\Fixtures\ConfiguredControllers\GreetingController;
use Zephyrus\Tests\Unit\Core\Cache\CacheDirectory;

final class ApplicationBuilderRouterTest extends TestCase
{
    private CacheDirectory $root;
    private Configuration $configuration;

    protected function setUp(): void
    {
        $this->root = new CacheDirectory();
        $this->configuration = Configuration::fromArray([
            'application' => ['environment' => 'development', 'debug' => false],
            'router' => [
                'controllers' => [
                    'Zephyrus\Tests\Fixtures\ConfiguredControllers' => (string) realpath(
                        __DIR__ . '/../../Fixtures/ConfiguredControllers',
                    ),
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->root->remove();
        App::reset();
    }

    public function testBuildServesTheControllersOfTheRouterSection(): void
    {
        $application = ApplicationBuilder::create()
            ->withConfiguration($this->configuration, basePath: $this->root->path)
            ->build();

        $response = $application->handle(Request::fromArray('GET', '/greeting'));

        self::assertSame('hello', $response->body);
    }

    public function testARouterDerivedFromTheConfiguredOneIsAccepted(): void
    {
        $router = Router::fromConfiguration($this->configuration, $this->root->path)
            ->get('/welcome', GreetingController::class . '@greet');

        $application = ApplicationBuilder::create()
            ->withConfiguration($this->configuration, basePath: $this->root->path)
            ->withRouter($router)
            ->build();

        self::assertSame('hello', $application->handle(Request::fromArray('GET', '/welcome'))->body);
    }

    public function testARouterTheConfigurationDidNotBuildIsRefused(): void
    {
        $builder = ApplicationBuilder::create()
            ->withConfiguration($this->configuration, basePath: $this->root->path)
            ->withRouter((new Router())->get('/greeting', GreetingController::class . '@greet'));

        try {
            $builder->build();
            self::fail('expected a refusal');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                'Configuration declares router.controllers, but withRouter() received a router that '
                . 'Router::fromConfiguration() did not build, so the routes cache would never be read. Remove the '
                . 'withRouter() call to let build() discover the controllers, or start the router passed to it from '
                . 'Router::fromConfiguration($configuration, basePath: <application root>).',
                $exception->getMessage(),
            );
        }
    }

    public function testAConfigurationFileIsBuiltWithItsDirectoryAsTheApplicationRoot(): void
    {
        symlink((string) realpath(__DIR__ . '/../../Fixtures/ConfiguredControllers'), $this->root->path . '/controllers');
        $file = $this->root->file(
            'config.yml',
            "application:\n  environment: development\n  debug: false\nrouter:\n  controllers:\n"
            . "    Zephyrus\\Tests\\Fixtures\\ConfiguredControllers: controllers\n",
        );

        $application = ApplicationBuilder::buildFromConfigurationFile($file);

        self::assertSame('hello', $application->handle(Request::fromArray('GET', '/greeting'))->body);
    }

    public function testTheRouterSectionNeedsTheApplicationRoot(): void
    {
        $builder = ApplicationBuilder::create()->withConfiguration($this->configuration);

        try {
            $builder->build();
            self::fail('expected a refusal');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                'Configuration declares router.controllers, but the builder received no basePath to resolve its paths '
                . 'against: build it with ApplicationBuilder::create()->withConfiguration($configuration, basePath: '
                . '<application root>).',
                $exception->getMessage(),
            );
        }
    }

    public function testWithConfigurationFileTakesItsDirectoryAsTheApplicationRoot(): void
    {
        $file = $this->routerFileWithControllersLink();

        $application = ApplicationBuilder::create()->withConfigurationFile($file)->build();

        self::assertSame('hello', $application->handle(Request::fromArray('GET', '/greeting'))->body);
    }

    public function testFromConfigurationFilesTakesTheDirectoryOfTheFirstFileAsTheApplicationRoot(): void
    {
        $first = $this->routerFileWithControllersLink();
        mkdir($this->root->path . '/overrides');
        $second = $this->root->file('overrides/local.yml', "application:\n  debug: false\n");

        $application = ApplicationBuilder::fromConfigurationFiles([$first, $second])->build();

        self::assertSame('hello', $application->handle(Request::fromArray('GET', '/greeting'))->body);
    }

    public function testOptionalConfigurationFilesTakeTheDirectoryOfTheFirstFileThatExists(): void
    {
        $existing = $this->routerFileWithControllersLink();

        $application = ApplicationBuilder::buildFromOptionalConfigurationFiles(
            [$this->root->path . '/nowhere/config.yml', $existing],
        );

        self::assertSame('hello', $application->handle(Request::fromArray('GET', '/greeting'))->body);
    }

    public function testAnOptionalPathWithANulByteIsSkippedWhenAnotherFileExists(): void
    {
        $existing = $this->routerFileWithControllersLink();

        $application = ApplicationBuilder::buildFromOptionalConfigurationFiles(["/x\0y/config.yml", $existing]);

        self::assertSame('hello', $application->handle(Request::fromArray('GET', '/greeting'))->body);
    }

    public function testAnExistingDirectoryWithoutTheFirstFileIsNotTheRoot(): void
    {
        $existing = $this->routerFileWithControllersLink();
        mkdir($this->root->path . '/foreign');

        $application = ApplicationBuilder::buildFromOptionalConfigurationFiles(
            [$this->root->path . '/foreign/config.yml', $existing],
        );

        self::assertSame('hello', $application->handle(Request::fromArray('GET', '/greeting'))->body);
    }

    public static function trailingCharacterProvider(): array
    {
        return [
            'trailing newline' => ["\n"],
            'trailing NUL' => ["\0"],
        ];
    }

    #[DataProvider('trailingCharacterProvider')]
    public function testAPathTheLoaderTrimsGivesItsDirectoryAsRoot(string $trailing): void
    {
        $existing = $this->routerFileWithControllersLink();
        mkdir($this->root->path . '/other');
        $other = $this->root->file('other/local.yml', "application:\n  debug: false\n");

        $application = ApplicationBuilder::buildFromOptionalConfigurationFiles([$existing . $trailing, $other]);

        self::assertSame('hello', $application->handle(Request::fromArray('GET', '/greeting'))->body);
    }

    public function testARelativeLocalePathOfAFileEntryIsResolvedAgainstTheDirectoryOfTheFile(): void
    {
        symlink((string) realpath(__DIR__ . '/../../Fixtures/locales'), $this->root->path . '/lang');
        $file = $this->root->file(
            'config.yml',
            "application:\n  environment: development\n  debug: false\n"
            . "localization:\n  locale: en\n  supported_locales: [en, fr]\n  locale_path: lang\n",
        );

        $application = ApplicationBuilder::create()->withConfigurationFile($file)->build();

        self::assertSame('Bonjour', $application->transFromRequest(
            'messages.plain',
            request: Request::fromArray('GET', '/', headers: ['accept-language' => 'fr-CA,fr;q=0.9,en;q=0.8']),
        ));
    }

    public function testASingleFileEntryWithAnUntrimmedRelativePathTakesTheDirectoryItLoadsAsRoot(): void
    {
        $contents = "application:\n  environment: development\n  debug: false\nrouter:\n  controllers:\n"
            . "    Zephyrus\\Tests\\Fixtures\\ConfiguredControllers: controllers\n";
        $this->root->file(' sub/config.yml', $contents);
        symlink((string) realpath(__DIR__ . '/../../Fixtures/ConfiguredControllers'), $this->root->path . '/ sub/controllers');
        $this->root->file('sub/config.yml', $contents);

        $previous = (string) getcwd();
        chdir($this->root->path);
        try {
            $fromFile = ApplicationBuilder::fromConfigurationFile(' sub/config.yml')->build();
            $withFile = ApplicationBuilder::create()->withConfigurationFile(' sub/config.yml')->build();
        } finally {
            chdir($previous);
        }

        self::assertSame('hello', $fromFile->handle(Request::fromArray('GET', '/greeting'))->body);
        self::assertSame('hello', $withFile->handle(Request::fromArray('GET', '/greeting'))->body);
    }

    private function routerFileWithControllersLink(): string
    {
        symlink((string) realpath(__DIR__ . '/../../Fixtures/ConfiguredControllers'), $this->root->path . '/controllers');

        return $this->root->file(
            'config.yml',
            "application:\n  environment: development\n  debug: false\nrouter:\n  controllers:\n"
            . "    Zephyrus\\Tests\\Fixtures\\ConfiguredControllers: controllers\n",
        );
    }
}
