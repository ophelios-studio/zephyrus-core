<?php

declare(strict_types=1);

namespace Zephyrus\Inertia;

use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Rendering\RenderException;

/**
 * Builds concrete Inertia responses from a request, component, and props.
 *
 * Most application code should call the static {@see Inertia} facade instead.
 * This service contains the request-aware rendering behavior behind that API.
 */
final class InertiaRenderer
{
    public const string HEADER_INERTIA = 'X-Inertia';
    public const string HEADER_LOCATION = 'X-Inertia-Location';
    public const string HEADER_VERSION = 'X-Inertia-Version';
    public const string HEADER_PARTIAL_COMPONENT = 'X-Inertia-Partial-Component';
    public const string HEADER_PARTIAL_DATA = 'X-Inertia-Partial-Data';
    public const string HEADER_PARTIAL_EXCEPT = 'X-Inertia-Partial-Except';

    /**
     * Create an Inertia response renderer.
     *
     * The root view is the server-rendered PHP shell that receives a `$page`
     * variable and mounts the frontend application, not the component
     * directory containing `.vue`, `.jsx`, or `.tsx` files.
     *
     * @param string $rootView Absolute path to the Inertia root PHP view.
     * @param string|null $version Optional asset version sent with every page payload.
     * @param array<string, mixed> $shared Props included with every rendered page.
     */
    public function __construct(
        private string $rootView,
        private ?string $version = null,
        private array $shared = [],
    ) {}

    /**
     * Register shared props that should be included with every Inertia page.
     *
     * Pass an associative array to merge several props at once, or a string key
     * with a value to register a single prop.
     *
     * @param array<string, mixed>|string $key Shared prop map or prop name.
     * @param mixed $value Value used when `$key` is a string.
     */
    public function share(array|string $key, mixed $value = null): self
    {
        if (is_array($key)) {
            $this->shared = array_merge($this->shared, $key);
            return $this;
        }

        $this->shared[$key] = $value;
        return $this;
    }

    /**
     * Set the asset version sent with each Inertia page payload.
     *
     * Clients use this value to detect stale frontend assets. Passing null
     * disables versioning for subsequent responses built by this instance.
     */
    public function version(?string $version): self
    {
        $this->version = $version;
        return $this;
    }

    /**
     * Return the asset version sent with each page payload.
     */
    public function getVersion(): ?string
    {
        return $this->version;
    }

    /**
     * Build an Inertia response for a component and its page props.
     *
     * Regular browser visits receive the HTML root view with the page payload
     * embedded in it. Requests containing `X-Inertia: true` receive the JSON
     * page payload expected by the Inertia client.
     *
     * @param Request $request Current framework request.
     * @param string $component Frontend component name, e.g. `Users/Index`.
     * @param array<string, mixed> $props Props specific to this component.
     *
     * @throws RenderException If the root view cannot be read or rendered.
     */
    public function render(Request $request, string $component, array $props = []): Response
    {
        if ($this->hasVersionConflict($request)) {
            return $this->location($request, $request->uri()->full());
        }

        $pageProps = $this->resolvePageProps($request, $component, $props);

        $page = [
            'component' => $component,
            'props' => $pageProps,
            'url' => $this->url($request),
            'version' => $this->version,
        ];

        if ($this->isInertiaRequest($request)) {
            return Response::json($page)
                ->withHeader(self::HEADER_INERTIA, 'true')
                ->withHeader('Vary', self::HEADER_INERTIA);
        }

        return Response::html($this->renderRootView($page))
            ->withHeader('Vary', self::HEADER_INERTIA);
    }

    /**
     * Build an Inertia-aware redirect to an external location.
     *
     * Inertia requests receive a `409 Conflict` response with the
     * `X-Inertia-Location` header. Non-Inertia requests receive a normal
     * framework redirect response.
     */
    public function location(Request $request, string $url): Response
    {
        if ($this->isInertiaRequest($request)) {
            return (new Response(status: 409))
                ->withHeader(self::HEADER_LOCATION, $url)
                ->withHeader('Vary', self::HEADER_INERTIA);
        }

        return Response::redirect($url);
    }

    /**
     * Determine whether the request is an Inertia client visit.
     *
     * The Inertia client marks JSON visits with `X-Inertia: true`; initial
     * browser visits do not include this header and should receive HTML.
     */
    public function isInertiaRequest(Request $request): bool
    {
        return strtolower(trim($request->headers()->get(self::HEADER_INERTIA, '') ?? '')) === 'true';
    }

    /**
     * Determine whether an Inertia GET request has stale frontend assets.
     */
    public function hasVersionConflict(Request $request): bool
    {
        if (!$this->isInertiaRequest($request) || !$request->isMethod('GET') || $this->version === null) {
            return false;
        }

        $requestVersion = trim($request->headers()->get(self::HEADER_VERSION, '') ?? '');

        return $requestVersion !== '' && $requestVersion !== $this->version;
    }

    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    private function resolvePageProps(Request $request, string $component, array $props): array
    {
        $pageProps = array_merge(['errors' => (object) []], $this->shared, $props);

        return $this->filterPartialProps($request, $component, $pageProps);
    }

    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    private function filterPartialProps(Request $request, string $component, array $props): array
    {
        $partialComponent = $request->headers()->get(self::HEADER_PARTIAL_COMPONENT);
        if ($partialComponent !== $component) {
            return $props;
        }

        $except = self::csvHeader($request, self::HEADER_PARTIAL_EXCEPT);
        if ($except !== []) {
            foreach ($except as $key) {
                if ($key !== 'errors') {
                    unset($props[$key]);
                }
            }

            return $props;
        }

        $only = self::csvHeader($request, self::HEADER_PARTIAL_DATA);
        if ($only === []) {
            return $props;
        }

        $filtered = [];
        foreach ($only as $key) {
            if (array_key_exists($key, $props)) {
                $filtered[$key] = $props[$key];
            }
        }

        if (array_key_exists('errors', $props)) {
            $filtered['errors'] = $props['errors'];
        }

        return $filtered;
    }

    /**
     * @return string[]
     */
    private static function csvHeader(Request $request, string $name): array
    {
        $value = $request->headers()->get($name);
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn(string $entry): string => trim($entry), explode(',', $value)),
            static fn(string $entry): bool => $entry !== '',
        ));
    }

    /**
     * Resolve the URL value stored in the Inertia page payload.
     *
     * Inertia expects a path-relative URL including the query string, not the
     * full scheme and host.
     */
    private function url(Request $request): string
    {
        $uri = $request->uri();
        $query = $uri->queryString();

        return $query === ''
            ? $uri->path()
            : $uri->path() . '?' . $query;
    }

    /**
     * Render the configured root view with the Inertia page payload.
     *
     * The included PHP view receives a `$page` variable. Output buffering keeps
     * the rendered shell as a string and is cleaned up if rendering throws.
     *
     * @param array<string, mixed> $page Inertia page payload.
     *
     * @throws RenderException If the root view cannot be read or rendered.
     */
    private function renderRootView(array $page): string
    {
        if (!is_file($this->rootView) || !is_readable($this->rootView)) {
            throw RenderException::engineError(sprintf(
                'Inertia root view is not readable: %s',
                $this->rootView,
            ));
        }

        $level = ob_get_level();
        ob_start();

        try {
            InertiaView::setPage($page);

            (static function (string $__path__, array $__data__): void {
                extract($__data__, EXTR_SKIP);
                include $__path__;
            })($this->rootView, ['page' => $page]);

            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw RenderException::renderFailed('inertia root view', $e);
        } finally {
            InertiaView::clearPage();
        }
    }
}
