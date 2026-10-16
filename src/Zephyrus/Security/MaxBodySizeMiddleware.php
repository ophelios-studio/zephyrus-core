<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use Closure;
use InvalidArgumentException;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

use function is_string;
use function preg_match;
use function str_contains;
use function strlen;
use function strtolower;
use function trim;

/**
 * Refuses a request whose body exceeds a byte budget with a 413: text/plain when Accept lists text/html, JSON otherwise.
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
     * @param string $message Sent verbatim: as plain text to a browser, or as the JSON "error" otherwise.
     *                        Write it in your users' language.
     * @param (Closure(Request, BodyTooLarge): ?Response)|null $onFailure Answers a refusal; returning null keeps the
     *        default 413 response: text/plain when Accept lists text/html, JSON otherwise. It receives
     *        attacker-controlled input: only answer the refusal, never replay the request or act on the account.
     * @throws InvalidArgumentException When $maxBytes is negative.
     */
    public function __construct(
        private readonly int $maxBytes,
        private readonly string $message = 'Request body too large.',
        private readonly ?Closure $onFailure = null,
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

        $bytes = $this->bodyBytes($request);

        if ($bytes !== null && $bytes > $this->maxBytes) {
            return $this->refuse($request, new BodyTooLarge($bytes, $this->maxBytes));
        }

        /** @var Response */
        return $next($request);
    }

    /**
     * Answers a refusal: the $onFailure response if it returns one, otherwise a 413 as text/plain when Accept
     * lists text/html, or as JSON {"error": ...} for every other client.
     */
    private function refuse(Request $request, BodyTooLarge $refusal): Response
    {
        if ($this->onFailure !== null) {
            $response = ($this->onFailure)($request, $refusal);

            if ($response !== null) {
                return $response;
            }
        }

        if (str_contains(strtolower($request->headers()->get('Accept') ?? ''), 'text/html')) {
            return Response::text($this->message, 413);
        }

        return Response::json(['error' => $this->message], 413);
    }

    /** Null when there is neither a valid declared length nor a raw body. */
    private function bodyBytes(Request $request): ?int
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
