<?php

declare(strict_types=1);

namespace Zephyrus\Http;

/**
 * Emits a {@see Response} to the PHP SAPI: status line, all headers, then body.
 *
 * The body is written in chunks of $chunkSize bytes (default 8192, must be positive).
 * Headers are skipped when they have already been sent. A header replaces one PHP already queued under the same
 * name, except Set-Cookie: each cookie line is added, so the session cookie and setcookie() calls survive.
 *
 * ```php
 * (new SapiEmitter(chunkSize: 4096))->emit($kernel->handle(Request::fromGlobals()));
 * ```
 */
final class SapiEmitter implements EmitterInterface
{
    private const int DEFAULT_CHUNK_SIZE = 8192;

    private const string SET_COOKIE_PREFIX = 'set-cookie:';

    /**
     * @throws \InvalidArgumentException When $chunkSize is not positive.
     */
    public function __construct(
        private readonly int $chunkSize = self::DEFAULT_CHUNK_SIZE,
    ) {
        if ($chunkSize < 1) {
            throw new \InvalidArgumentException(sprintf('The chunk size must be positive, %d given.', $chunkSize));
        }
    }

    public function emit(Response $response): void
    {
        $this->emitHeaders($response);
        $this->emitBody($response->body);
    }

    private function emitHeaders(Response $response): void
    {
        if (headers_sent()) { // @codeCoverageIgnore
            return;           // @codeCoverageIgnore
        }

        header($response->toStatusLine(), true, $response->status);

        foreach ($response->toHeaderLines() as $line) {
            header($line, !self::isSetCookie($line));
        }
    }

    private static function isSetCookie(string $line): bool
    {
        return stripos($line, self::SET_COOKIE_PREFIX) === 0;
    }

    private function emitBody(string $body): void
    {
        if ($body === '') {
            return;
        }

        $length = strlen($body);
        $offset = 0;

        while ($offset < $length) {
            echo substr($body, $offset, $this->chunkSize);
            $offset += $this->chunkSize;
        }
    }
}
