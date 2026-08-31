<?php

declare(strict_types=1);

namespace Zephyrus\Http;

/**
 * Represents a single Server-Sent Event (SSE) in the text/event-stream protocol.
 *
 * An SSE event is a sequence of field lines followed by a blank line that flushes
 * the event to the client.  The only required field is `data`; all others are optional:
 *
 * - `event`  — the event type name (clients use this with addEventListener()).
 * - `id`     — sets the event source's "last event ID" on the client.
 * - `retry`  — tells the browser how many ms to wait before reconnecting.
 *
 * Multi-line `data` is handled transparently: each embedded newline produces an
 * additional `data:` line so the wire format remains valid.
 *
 * ## Usage
 *
 * ```php
 * $event = new SseEvent(data: 'hello world');
 * $typed = new SseEvent(data: '{"count":1}', event: 'tick', id: '1');
 * ```
 */
final readonly class SseEvent
{
    public function __construct(
        public string $data,
        public ?string $event = null,
        public ?string $id = null,
        public ?int $retry = null,
    ) {
        // `data` is the only field format() was ever careful with. `id` and
        // `event` were interpolated raw, and in text/event-stream a newline
        // ENDS a field: an id of "1\nevent: admin" emitted an extra field of
        // the sender's choosing, so any value flowing from outside input into
        // either one could forge whole SSE fields on the wire. A carriage
        // return terminates a line just as a newline does, and a NUL is not
        // valid in the protocol at all.
        self::assertSingleLine($event, 'event');
        self::assertSingleLine($id, 'id');
    }

    /**
     * @throws \InvalidArgumentException When $value would break out of its field.
     */
    private static function assertSingleLine(?string $value, string $field): void
    {
        if ($value === null) {
            return;
        }

        if (strpbrk($value, "\n\r\0") !== false) {
            throw new \InvalidArgumentException(sprintf(
                'SSE field "%s" must not contain a newline, a carriage return or a NUL byte.',
                $field,
            ));
        }
    }

    /**
     * Serialises the event to its wire representation.
     *
     * Field order follows the recommendation in the SSE spec:
     * retry → id → event → data (one or more lines).
     * A trailing blank line terminates the event.
     *
     * @return non-empty-string
     */
    public function format(): string
    {
        $lines = [];

        if ($this->retry !== null) {
            $lines[] = "retry: {$this->retry}";
        }

        if ($this->id !== null) {
            $lines[] = "id: {$this->id}";
        }

        if ($this->event !== null) {
            $lines[] = "event: {$this->event}";
        }

        // Multi-line data: each embedded newline becomes a separate "data:"
        // line. CRLF and a lone CR are line terminators in event-stream too, so
        // they are normalised first rather than being emitted inside a field.
        $data = str_replace(["\r\n", "\r"], "\n", $this->data);

        foreach (explode("\n", $data) as $line) {
            $lines[] = "data: {$line}";
        }

        return implode("\n", $lines) . "\n\n";
    }
}
