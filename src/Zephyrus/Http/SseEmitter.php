<?php

declare(strict_types=1);

namespace Zephyrus\Http;

/**
 * Streams Server-Sent Events from a PHP {@see \Generator} to the SAPI.
 *
 * Unlike {@see SapiEmitter}, which buffers a complete {@see Response} body,
 * `SseEmitter` never holds the full stream in memory.  It sends the mandatory
 * SSE headers, then iterates the generator: each yielded {@see SseEvent} is
 * formatted, echoed, and flushed before the next event is produced.
 *
 * ## Setup — disable buffering first
 *
 * SSE requires the PHP output buffer and any upstream proxy buffer to be
 * cleared.  Call `ob_end_clean()` (or otherwise ensure no buffering layer sits
 * between PHP and the client) before invoking `emit()`.
 *
 * ## Usage
 *
 * ```php
 * function ticker(): \Generator
 * {
 *     for ($i = 1; $i <= 5; $i++) {
 *         yield new SseEvent(data: (string) $i, event: 'tick', id: (string) $i);
 *         sleep(1);
 *     }
 * }
 *
 * ob_end_clean();
 * (new SseEmitter())->emit(ticker());
 * ```
 *
 * ## Header emission
 *
 * The emitter sets three headers when headers have not yet been sent:
 *
 * | Header              | Value             | Purpose                              |
 * |---------------------|-------------------|--------------------------------------|
 * | Content-Type        | text/event-stream | Signals SSE to the browser           |
 * | Cache-Control       | no-cache          | Prevents intermediate caching        |
 * | X-Accel-Buffering   | no                | Disables nginx proxy buffering       |
 *
 * If headers were already sent the emitter skips header emission and proceeds
 * directly to the event loop.
 */
final class SseEmitter
{
    /**
     * Sends SSE headers (if not yet sent), then streams every event yielded by
     * `$source` to the client, flushing after each one.
     *
     * @param \Generator<mixed, SseEvent, mixed, mixed> $source
     */
    public function emit(\Generator $source): void
    {
        $this->sendHeaders();

        foreach ($source as $event) {
            echo $event->format();
            flush();
        }
    }

    private function sendHeaders(): void
    {
        if (headers_sent()) { // @codeCoverageIgnore
            return;           // @codeCoverageIgnore
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
    }
}
