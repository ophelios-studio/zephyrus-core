<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use InvalidArgumentException;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

use function is_string;
use function preg_match;
use function strlen;
use function trim;

/**
 * Refuses a request whose body exceeds a byte budget, with 413.
 *
 * ## Why this class exists
 *
 * `security.maxBodySize` has been a parsed, type-validated, range-checked and
 * unit-tested configuration key with NO CONSUMER ANYWHERE. An application could
 * declare a 2 MB limit and post 5 MB, because there was not even a middleware
 * to wire by hand. Deleting a documented key is a breaking change for five
 * production applications, so the key keeps its meaning and this is the thing
 * that gives it one.
 *
 * ## It is OPT-IN, and deliberately so
 *
 * Nothing registers it for you. A framework that started registering
 * middlewares off a config file would hand an application that already
 * registers its own a SECOND copy of it, which is a worse outage than the
 * silence it replaces. ApplicationBuilder::build() therefore refuses to boot
 * when the key is declared and this middleware is absent, and says so; it never
 * registers anything itself.
 *
 *   $builder->withMiddleware(new MaxBodySizeMiddleware($config->security->maxBodySize));
 *
 * ## What it measures
 *
 * Content-Length when the request declares one, because that is the only figure
 * available for a multipart upload: PHP has already drained the body into
 * $_FILES by the time any middleware runs, so php://input is empty and the raw
 * body measures zero. When no Content-Length is present the raw body is
 * measured directly.
 *
 * This is a BUDGET, not a defence against a caller who lies about the length
 * and streams more. The web server's own limit (client_max_body_size,
 * LimitRequestBody) is the one that stops the bytes arriving; PHP's
 * post_max_size is the one that stops them being parsed. Set those too.
 */
final class MaxBodySizeMiddleware implements MiddlewareInterface
{
    /**
     * @param int $maxBytes Maximum accepted body size; 0 means unlimited,
     *                      matching SecurityConfig::$maxBodySize.
     * @throws InvalidArgumentException When $maxBytes is negative.
     */
    public function __construct(
        private readonly int $maxBytes,
        private readonly string $message = 'Request body too large.',
    ) {
        if ($maxBytes < 0) {
            throw new InvalidArgumentException('Maximum body size must be 0 (unlimited) or a positive byte count.');
        }
    }

    public function process(Request $request, callable $next): Response
    {
        if ($this->maxBytes === 0) {
            /** @var Response */
            return $next($request);
        }

        $size = $this->bodySize($request);

        if ($size !== null && $size > $this->maxBytes) {
            return Response::json(['error' => $this->message], 413);
        }

        /** @var Response */
        return $next($request);
    }

    /**
     * The size to judge, or null when the request declares none and carries no
     * raw body to measure.
     */
    private function bodySize(Request $request): ?int
    {
        $declared = $request->headers()->get('content-length');

        if (is_string($declared)) {
            $declared = trim($declared);
            if (preg_match('/^\d+$/D', $declared) === 1) {
                return (int) $declared;
            }
        }

        $raw = $request->body()->raw();

        return $raw === '' ? null : strlen($raw);
    }
}
