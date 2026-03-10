<?php

declare(strict_types=1);

namespace Zephyrus\Rendering;

use Zephyrus\Http\Response;

/**
 * Convenience methods for rendering templates in controllers.
 *
 * Use this trait in your Controller subclass to gain access to `render()`,
 * `renderWith()`, and `html()` methods that produce HTML Response objects.
 *
 * The trait requires a `RenderEngine` to be supplied. By default it uses
 * the engine set via `setRenderEngine()`, which the application builder
 * configures automatically from `RenderConfig`.
 *
 * Usage:
 *
 *   final class PageController extends Controller
 *   {
 *       use RenderResponses;
 *
 *       public function show(int $id): Response
 *       {
 *           return $this->render('pages/show', ['id' => $id]);
 *       }
 *   }
 */
trait RenderResponses
{
    private ?RenderEngine $renderEngine = null;

    /**
     * Set the rendering engine for this controller.
     *
     * Typically called by the framework during controller instantiation.
     */
    public function setRenderEngine(RenderEngine $engine): void
    {
        $this->renderEngine = $engine;
    }

    /**
     * Render a template using the configured engine and return an HTML response.
     *
     * @param string              $page   Template identifier (e.g. 'users/show').
     * @param array<string,mixed> $args   Template variables.
     * @param int                 $status HTTP status code (default 200).
     */
    protected function render(string $page, array $args = [], int $status = 200): Response
    {
        return Response::html(
            $this->getRenderEngine()->render($page, $args),
            $status,
        );
    }

    /**
     * Render a template using a specific engine (ignoring the default one).
     *
     * Useful for one-off rendering with a different engine (e.g. rendering a
     * plain PHP template in a controller that normally uses Latte).
     *
     * @param RenderEngine        $engine The engine to use.
     * @param string              $page   Template identifier.
     * @param array<string,mixed> $args   Template variables.
     * @param int                 $status HTTP status code.
     */
    protected function renderWith(RenderEngine $engine, string $page, array $args = [], int $status = 200): Response
    {
        return Response::html(
            $engine->render($page, $args),
            $status,
        );
    }

    /**
     * Return a raw HTML response.
     */
    protected function html(string $content, int $status = 200): Response
    {
        return Response::html($content, $status);
    }

    /**
     * Get the configured render engine.
     *
     * @throws RenderException if no engine has been configured.
     */
    private function getRenderEngine(): RenderEngine
    {
        if ($this->renderEngine === null) {
            throw RenderException::engineError(
                'No render engine has been configured. Call setRenderEngine() '
                . 'or ensure the application builder has configured rendering.',
            );
        }

        return $this->renderEngine;
    }
}
