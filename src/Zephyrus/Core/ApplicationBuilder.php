<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use Zephyrus\Container\ContainerInterface;
use Zephyrus\Event\EventDispatcher;
use Zephyrus\Http\MiddlewareInterface;
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
