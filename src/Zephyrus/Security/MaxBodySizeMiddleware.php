<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use Closure;
use InvalidArgumentException;
use Zephyrus\Http\BodySize;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

use function max;
use function min;
use function str_contains;
use function strtolower;

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
 * The measure is the largest of a valid declared Content-Length, the raw body and the parsed-body measure, so a false
 * length cannot hide a raw body or an uploaded file. PHP parses a multipart body into $_FILES and $_POST itself and its
 * raw body is then empty: an empty raw body means the keys and values of the text fields count, whatever the
 * Content-Type, and the uploaded files always count. A urlencoded or JSON body keeps its raw body, measured as is.
 * Without a Content-Length, a body PHP parsed is measured on what PHP kept (a repeated name keeps its last value).
 * post_max_size still bounds what PHP reads into memory. A total past PHP_INT_MAX counts as PHP_INT_MAX.
 * Request::fromGlobals() measures a body the same way and truncates one over its read limit (2 MB unless given one),
 * which this middleware refuses whatever its budget, even 0: pass it the same limit,
 * Request::fromGlobals(maxBodySize: ...).
 * Register it before CsrfMiddleware: a body too large reads as empty, so a token sent in the form is missing and the
 * request gets the CSRF 403, not the 413.
 * This is a budget, not a defence: a client that lies about its length is stopped by the web
 * server limit (client_max_body_size, LimitRequestBody) and by post_max_size, which must be set too.
 */
final class MaxBodySizeMiddleware implements MiddlewareInterface
{
    private const string DEFAULT_MESSAGE = 'Request body too large.';

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
        private readonly string $message = self::DEFAULT_MESSAGE,
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
        if ($request->body()->isTruncated()) {
            return $this->refuse($request, $this->truncatedBodyRefusal($request));
        }

        if ($this->maxBytes === 0) {
            /** @var Response */
            return $next($request);
        }

        $bytes = self::bodyBytes($request);

        if ($bytes > $this->maxBytes) {
            return $this->refuse($request, new BodyTooLarge($bytes, $this->maxBytes));
        }

        /** @var Response */
        return $next($request);
    }

    /**
     * Refuses a truncated body at the limit that cut it, the smaller positive one of this budget and the read limit,
     * with a size past both limits.
     */
    private function truncatedBodyRefusal(Request $request): BodyTooLarge
    {
        $readLimit = $request->body()->readLimit() ?? 0;
        $limit = match (true) {
            $this->maxBytes === 0 => $readLimit,
            $readLimit === 0      => $this->maxBytes,
            default               => min($this->maxBytes, $readLimit),
        };

        return new BodyTooLarge(
            max(self::bodyBytes($request), self::pastLimit($limit), self::pastLimit($readLimit)),
            $limit,
        );
    }

    private static function pastLimit(int $limit): int
    {
        return $limit < PHP_INT_MAX ? $limit + 1 : $limit;
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

        return self::defaultRefusal($request, $this->message);
    }

    /**
     * The 413 without onFailure: text/plain when Accept lists text/html, JSON {"error": ...} otherwise.
     *
     * @internal Shared with HttpKernel, which answers a truncated body this way when no instance is mounted.
     */
    public static function defaultRefusal(Request $request, string $message = self::DEFAULT_MESSAGE): Response
    {
        if (str_contains(strtolower($request->headers()->get('Accept') ?? ''), 'text/html')) {
            return Response::text($message, 413);
        }

        return Response::json(['error' => $message], 413);
    }

    private static function bodyBytes(Request $request): int
    {
        return BodySize::measure(
            $request->headers()->get('content-length'),
            $request->body()->raw(),
            $request->body()->all(),
            $request->files,
        );
    }
}
