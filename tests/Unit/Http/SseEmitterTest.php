<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Zephyrus\Http\SseEmitter;
use Zephyrus\Http\SseEvent;

final class SseEmitterTest extends TestCase
{
    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Runs emit() inside an output buffer and returns the captured output.
     *
     * @param \Generator<mixed, SseEvent, mixed, mixed> $generator
     */
    private function capture(\Generator $generator): string
    {
        ob_start();
        (new SseEmitter())->emit($generator);
        return (string) ob_get_clean();
    }

    /**
     * Yields SseEvents from a plain array.
     *
     * @param SseEvent[] $events
     * @return \Generator<int, SseEvent, mixed, void>
     */
    private function generatorFrom(array $events): \Generator
    {
        yield from $events;
    }

    // ------------------------------------------------------------------
    // Output content
    // ------------------------------------------------------------------

    public function test_emit_single_event(): void
    {
        $output = $this->capture($this->generatorFrom([
            new SseEvent(data: 'hello'),
        ]));

        self::assertSame("data: hello\n\n", $output);
    }

    public function test_emit_multiple_events_concatenates_output(): void
    {
        $output = $this->capture($this->generatorFrom([
            new SseEvent(data: 'one'),
            new SseEvent(data: 'two'),
            new SseEvent(data: 'three'),
        ]));

        $expected = "data: one\n\ndata: two\n\ndata: three\n\n";
        self::assertSame($expected, $output);
    }

    public function test_emit_typed_events(): void
    {
        $output = $this->capture($this->generatorFrom([
            new SseEvent(data: '{"n":1}', event: 'tick', id: '1'),
            new SseEvent(data: '{"n":2}', event: 'tick', id: '2'),
        ]));

        $expected = "id: 1\nevent: tick\ndata: {\"n\":1}\n\n"
                  . "id: 2\nevent: tick\ndata: {\"n\":2}\n\n";
        self::assertSame($expected, $output);
    }

    public function test_emit_empty_generator_produces_no_output(): void
    {
        $output = $this->capture($this->generatorFrom([]));
        self::assertSame('', $output);
    }

    public function test_emit_preserves_multiline_data(): void
    {
        $output = $this->capture($this->generatorFrom([
            new SseEvent(data: "line1\nline2"),
        ]));

        self::assertSame("data: line1\ndata: line2\n\n", $output);
    }

    public function test_emit_event_with_all_fields(): void
    {
        $output = $this->capture($this->generatorFrom([
            new SseEvent(data: 'body', event: 'update', id: '99', retry: 2000),
        ]));

        self::assertSame("retry: 2000\nid: 99\nevent: update\ndata: body\n\n", $output);
    }

    // ------------------------------------------------------------------
    // Generator is consumed exactly once
    // ------------------------------------------------------------------

    public function test_emit_uses_generator_values_in_order(): void
    {
        $sequence = [];
        $gen = (function () use (&$sequence): \Generator {
            $sequence[] = 'yielded-a';
            yield new SseEvent(data: 'a');
            $sequence[] = 'yielded-b';
            yield new SseEvent(data: 'b');
        })();

        $output = $this->capture($gen);

        self::assertSame(['yielded-a', 'yielded-b'], $sequence);
        self::assertSame("data: a\n\ndata: b\n\n", $output);
    }

    // ------------------------------------------------------------------
    // Mixed event and data-only events in one stream
    // ------------------------------------------------------------------

    public function test_emit_mixed_stream(): void
    {
        $output = $this->capture($this->generatorFrom([
            new SseEvent(data: 'ping'),
            new SseEvent(data: 'pong', event: 'pong'),
        ]));

        $expected = "data: ping\n\nevent: pong\ndata: pong\n\n";
        self::assertSame($expected, $output);
    }
}
