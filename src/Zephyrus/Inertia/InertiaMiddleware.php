<?php

declare(strict_types=1);

namespace Zephyrus\Inertia;

use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

/**
 * Applies the HTTP-level parts of the Inertia protocol.
 */
final readonly class InertiaMiddleware implements MiddlewareInterface
{
    public function __construct(private InertiaRenderer $inertia)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        if ($this->inertia->hasVersionConflict($request)) {
            return $this->withInertiaVary($this->inertia->location($request, $request->uri()->full()));
        }

        $response = $this->withInertiaVary($next($request));

        if (
            $this->inertia->isInertiaRequest($request)
            && $response->status === 302
            && in_array($request->method, ['PUT', 'PATCH', 'DELETE'], true)
        ) {
            return $response->withStatus(303);
        }

        return $response;
    }

    private function withInertiaVary(Response $response): Response
    {
        $vary = $response->headers['vary'] ?? '';
        $values = array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', $vary),
        )));

        foreach ($values as $value) {
            if (strcasecmp($value, InertiaRenderer::HEADER_INERTIA) === 0) {
                return $response;
            }
        }

        $values[] = InertiaRenderer::HEADER_INERTIA;

        return $response->withHeader('Vary', implode(', ', $values));
    }
}
