<?php

declare(strict_types=1);

namespace Zephyrus\Http;

/**
 * One Server-Sent Event in the text/event-stream format.
 *
 * Only `data` is required. `event` names the type, `id` sets the last event ID and `retry` the reconnection delay in
 * milliseconds. Each line break in `data` (LF, CR or CRLF) starts a new `data:` line.
 *
 * ```php
 * $event = new SseEvent(data: '{"count":1}', event: 'tick', id: '1');
 * ```
 */
final readonly class SseEvent
{
    /**
     * @throws \InvalidArgumentException When $event or $id contains a line break or a NUL byte.
     */
    public function __construct(
        public string $data,
        public ?string $event = null,
        public ?string $id = null,
        public ?int $retry = null,
    ) {
        // Raw line breaks in event or id would forge extra fields on the wire.
        self::assertSingleLine($event, 'event');
        self::assertSingleLine($id, 'id');
    }

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
     * Serialises the event: retry, id, event, one line per data line, then a blank line.
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

        // A lone CR also ends a line in event-stream, so it is normalised to LF.
        $data = str_replace(["\r\n", "\r"], "\n", $this->data);

        foreach (explode("\n", $data) as $line) {
            $lines[] = "data: {$line}";
        }

        return implode("\n", $lines) . "\n\n";
    }
}
