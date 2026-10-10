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
 * Refuses, with a 413 JSON response, a request whose body exceeds a byte budget.
 *
 * Opt-in by design: a registration driven by config would duplicate the copy an application
 * already adds. ApplicationBuilder::build() refuses to boot when security.maxBodySize is positive
 * and either no global instance of this middleware is registered or one has a looser limit (larger, or 0),
 * unless the key is acknowledged with withAcknowledgedSecurityKeys(['maxBodySize']).
 *
 *   $builder->withMiddleware(new MaxBodySizeMiddleware($config->security->maxBodySize));
 *
 * The measure is a valid declared Content-Length, otherwise the raw body. Multipart uploads are
 * already parsed into $_FILES, so their raw body is empty and only Content-Length counts.
 * This is a budget, not a defence: a client that lies about its length is stopped by the web
 * server limit (client_max_body_size, LimitRequestBody) and by post_max_size, which must be set too.
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

    /** The maximum accepted body size in bytes; 0 means unlimited. */
    public function maxBytes(): int
    {
        return $this->maxBytes;
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

    /** Null when there is neither a valid declared length nor a raw body. */
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
