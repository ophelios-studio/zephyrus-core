<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use Zephyrus\Container\ContainerInterface;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Core\Config\LocalizationConfig;
use Zephyrus\Event\EventDispatcher;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Localization\FallbackLocaleLoader;
use Zephyrus\Localization\JsonLocaleLoader;
use Zephyrus\Localization\LocaleLoaderInterface;
use Zephyrus\Localization\Translator;
use Zephyrus\Routing\Router;

final class ApplicationBuilder
{
    private KernelBuilder $kernelBuilder;

    private ?LocaleLoaderInterface $localeLoader = null;

    private string $defaultLocale = 'en';

    /** @var string[] */
    private array $supportedLocales = [];

    public function __construct(?KernelBuilder $kernelBuilder = null)
    {
        $this->kernelBuilder = $kernelBuilder ?? KernelBuilder::create();
    }

    public static function create(): self
    {
        return new self();
    }

    public static function fromConfiguration(Configuration $configuration): self
    {
        return self::create()->withConfiguration($configuration);
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public static function fromConfigurationArray(array $configuration): self
    {
        return self::create()->withConfigurationArray($configuration);
    }

    public static function fromConfigurationFile(string $path): self
    {
        return self::create()->withConfigurationFile($path);
    }

    public function withRouter(Router $router): self
    {
        $clone = clone $this;
        $clone->kernelBuilder = $this->kernelBuilder->withRouter($router);

        return $clone;
    }

    public function withMiddleware(MiddlewareInterface $middleware): self
    {
        $clone = clone $this;
        $clone->kernelBuilder = $this->kernelBuilder->withMiddleware($middleware);

        return $clone;
    }

    public function registerMiddleware(string $name, MiddlewareInterface $middleware): self
    {
        $clone = clone $this;
        $clone->kernelBuilder = $this->kernelBuilder->registerMiddleware($name, $middleware);

        return $clone;
    }

    public function withEventDispatcher(EventDispatcher $dispatcher): self
    {
        $clone = clone $this;
        $clone->kernelBuilder = $this->kernelBuilder->withEventDispatcher($dispatcher);

        return $clone;
    }

    /**
     * @param callable(class-string): object $factory
     */
    public function withControllerFactory(callable $factory): self
    {
        $clone = clone $this;
        $clone->kernelBuilder = $this->kernelBuilder->withControllerFactory($factory);

        return $clone;
    }

    public function withContainer(ContainerInterface $container): self
    {
        $clone = clone $this;
        $clone->kernelBuilder = $this->kernelBuilder->withContainer($container);

        return $clone;
    }

    public function withLocaleLoader(LocaleLoaderInterface $loader, string $defaultLocale = 'en'): self
    {
        $clone = clone $this;
        $clone->localeLoader = $loader;
        $clone->defaultLocale = $defaultLocale;

        return $clone;
    }

    public function withJsonLocales(string $basePath, string $defaultLocale = 'en', string $extension = 'json'): self
    {
        return $this->withLocaleLoader(new JsonLocaleLoader($basePath, $extension), $defaultLocale);
    }

    /**
     * Configure JSON translation catalogs from multiple directories merged in
     * last-wins order. Later paths override earlier paths for duplicate keys.
     *
     * @param string[] $basePaths
     */
    public function withJsonLocaleLayers(array $basePaths, string $defaultLocale = 'en', string $extension = 'json'): self
    {
        $loaders = array_values(array_map(
            static fn (string $basePath): JsonLocaleLoader => new JsonLocaleLoader($basePath, $extension),
            $basePaths,
        ));

        if ($loaders === []) {
            return $this->withLocaleLoader(new class implements LocaleLoaderInterface {
                public function load(string $locale): array
                {
                    return [];
                }
            }, $defaultLocale);
        }

        return $this->withFallbackLoaders($loaders, $defaultLocale);
    }

    /**
     * Configure translation from multiple locale loaders merged in last-wins
     * order. Later loaders override earlier loaders for the same key.
     *
     * @param LocaleLoaderInterface[] $loaders
     */
    public function withFallbackLoaders(array $loaders, string $defaultLocale = 'en'): self
    {
        return $this->withLocaleLoader(new FallbackLocaleLoader($loaders), $defaultLocale);
    }

    /**
     * Restrict locale resolution (in transFromRequest) to this explicit list.
     * If not set (empty), every normalized Accept-Language candidate is accepted.
     *
     * @param string[] $locales
     */
    public function withSupportedLocales(array $locales): self
    {
        $clone = clone $this;
        $clone->supportedLocales = $locales;

        return $clone;
    }

    public function withLocalizationConfig(LocalizationConfig $config): self
    {
        $builder = $this;

        if ($config->jsonLocalePaths !== []) {
            $builder = $builder->withJsonLocaleLayers(
                basePaths: $config->jsonLocalePaths,
                defaultLocale: $config->defaultLocale,
                extension: $config->jsonExtension,
            );
        } else {
            $builder = $builder->withLocaleLoader(new class implements LocaleLoaderInterface {
                public function load(string $locale): array
                {
                    return [];
                }
            }, $config->defaultLocale);
        }

        return $builder->withSupportedLocales($config->supportedLocales);
    }

    /**
     * Apply a full typed Configuration tree to application bootstrap.
     *
     * Current wiring scope:
     * - localization section
     */
    public function withConfiguration(Configuration $configuration): self
    {
        return $this->withLocalizationConfig($configuration->localization);
    }

    /**
     * Parse and apply a root configuration array in one call.
     *
     * @param array<string, mixed> $configuration
     */
    public function withConfigurationArray(array $configuration): self
    {
        return $this->withConfiguration(Configuration::fromArray($configuration));
    }

    /**
     * Load and apply a root configuration file.
     */
    public function withConfigurationFile(string $path): self
    {
        return $this->withConfiguration(Configuration::fromFile($path));
    }

    public function build(): Application
    {
        $loader = $this->localeLoader ?? new class implements LocaleLoaderInterface {
            public function load(string $locale): array
            {
                return [];
            }
        };

        return new Application(
            kernel:           $this->kernelBuilder->build(),
            translator:       new Translator($loader, $this->defaultLocale),
            defaultLocale:    $this->defaultLocale,
            supportedLocales: $this->supportedLocales,
        );
    }
}
