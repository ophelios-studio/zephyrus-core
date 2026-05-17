<?php

declare(strict_types=1);

namespace Zephyrus\Inertia;

use Zephyrus\Core\App;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Rendering\RenderException;

/**
 * Laravel-style static facade for building Inertia responses.
 *
 * Controllers can call `Inertia::render('Users/Index', [...])` without
 * manually constructing an Inertia renderer. The renderer and current request
 * are stored in the application registry during bootstrap and dispatch.
 */
final class Inertia
{
    /**
     * Prevent instantiation.
     */
    private function __construct() {}

    /**
     * Configure the global Inertia renderer.
     *
     * Applications normally call this once during bootstrap. Prefer
     * `ApplicationBuilder::withInertia()` when using the framework builder.
     *
     * @param string $rootView Absolute path to the Inertia root PHP view.
     * @param string|null $version Optional asset version sent with every page payload.
     * @param array<string, mixed> $shared Props included with every rendered page.
     */
    public static function configure(
        string $rootView = __DIR__,
        ?string $version = null,
        array $shared = []
    ): void {
        App::setInertia(new InertiaRenderer($rootView, $version, $shared));
    }

    /**
     * Replace the global renderer with a pre-built instance.
     */
    public static function setRenderer(InertiaRenderer $renderer): void
    {
        App::setInertia($renderer);
    }

    /**
     * Return the configured global renderer.
     *
     * @throws RenderException If Inertia has not been configured.
     */
    public static function renderer(): InertiaRenderer
    {
        return App::getInertia()
            ?? throw RenderException::engineError(
                'No Inertia renderer has been configured. Call ApplicationBuilder::withInertia() '
                    . 'or Inertia::configure() during bootstrap.',
            );
    }

    /**
     * Register shared props that should be included with every Inertia page.
     *
     * @param array<string, mixed>|string $key Shared prop map or prop name.
     * @param mixed $value Value used when `$key` is a string.
     */
    public static function share(array|string $key, mixed $value = null): void
    {
        self::renderer()->share($key, $value);
    }

    /**
     * Set the asset version sent with each Inertia page payload.
     */
    public static function version(?string $version): void
    {
        self::renderer()->version($version);
    }

    /**
     * Build an Inertia response for a component and its page props.
     *
     * @param string $component Frontend component name, e.g. `Users/Index`.
     * @param array<string, mixed> $props Props specific to this component.
     *
     * @throws RenderException If no current request or renderer is available.
     * @throws RenderException If the root view cannot be read or rendered.
     */
    public static function render(string $component, array $props = []): Response
    {
        return self::renderer()->render(self::currentRequest(), $component, $props);
    }

    /**
     * Build an Inertia-aware redirect to an external location.
     */
    public static function location(string $url): Response
    {
        return self::renderer()->location(self::currentRequest(), $url);
    }

    /**
     * Determine whether the request is an Inertia client visit.
     */
    public static function isInertiaRequest(): bool
    {
        return self::renderer()->isInertiaRequest(self::currentRequest());
    }

    /**
     * Return the current request being handled by the framework.
     *
     * @throws RenderException If no request is currently being dispatched.
     */
    private static function currentRequest(): Request
    {
        return App::getRequest()
            ?? throw RenderException::engineError(
                'No current request is available for Inertia::render(). '
                    . 'Call it while handling a controller request.',
            );
    }
}
