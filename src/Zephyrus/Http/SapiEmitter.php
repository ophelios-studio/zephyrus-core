<?php

declare(strict_types=1);

namespace Zephyrus\Http;

/**
 * Emits a {@see Response} to the PHP SAPI: status line, all headers, then body.
 *
 * The body is written in chunks of {@see $chunkSize} bytes so that large
 * payloads (file downloads, generated reports, etc.) do not require the full
 * body to be held in memory before the first byte reaches the client.
 *
 * Header emission is skipped silently when headers have already been sent
 * (e.g. because a prior echo flushed the output buffer in a legacy context).
 *
 * ## Entry-point example
 *
 * ```php
 * $kernel  = KernelBuilder::create()->withRouter($router)->build();
 * $emitter = new SapiEmitter();          // default 8 KiB chunk size
 *
 * $request  = Request::fromGlobals();
 * $response = $kernel->handle($request);
 * $emitter->emit($response);
 * ```
 *
 * ## Custom chunk size
 *
 * ```php
 * $emitter = new SapiEmitter(chunkSize: 4096);   // 4 KiB chunks
 * ```
 */
final class SapiEmitter implements EmitterInterface
{
    private const int DEFAULT_CHUNK_SIZE = 8192;

    public function __construct(
        private readonly int $chunkSize = self::DEFAULT_CHUNK_SIZE,
    ) {
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
            header($line);
        }
    }

    /**
     * Streams the body in fixed-size chunks. Empty bodies produce no output.
     */
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
