<?php

declare(strict_types=1);

namespace Zephyrus\Http;

/**
 * Streams Server-Sent Events from a \Generator to the SAPI, flushing after each event.
 *
 * Output buffering must be off first: call ob_end_clean() or otherwise ensure no buffer sits between PHP and the client.
 * Sends Content-Type text/event-stream, Cache-Control no-cache and X-Accel-Buffering no, unless headers were already sent.
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
 * while (ob_get_level() > 0) {
 *     ob_end_clean();
 * }
 * (new SseEmitter())->emit(ticker());
 * ```
 */
final class SseEmitter
{
    /**
     * Streams every event yielded by $source, flushing after each one.
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
