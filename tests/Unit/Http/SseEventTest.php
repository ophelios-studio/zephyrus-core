<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Zephyrus\Http\SseEvent;

final class SseEventTest extends TestCase
{
    // ------------------------------------------------------------------
    // format() — wire representation
    // ------------------------------------------------------------------

    public function test_data_only(): void
    {
        $event = new SseEvent(data: 'hello');
        self::assertSame("data: hello\n\n", $event->format());
    }

    public function test_data_with_event_type(): void
    {
        $event = new SseEvent(data: 'payload', event: 'tick');
        self::assertSame("event: tick\ndata: payload\n\n", $event->format());
    }

    public function test_data_with_id(): void
    {
        $event = new SseEvent(data: 'payload', id: '42');
        self::assertSame("id: 42\ndata: payload\n\n", $event->format());
    }

    public function test_data_with_retry(): void
    {
        $event = new SseEvent(data: 'payload', retry: 3000);
        self::assertSame("retry: 3000\ndata: payload\n\n", $event->format());
    }

    public function test_all_fields_field_order(): void
    {
        $event = new SseEvent(data: 'body', event: 'update', id: '7', retry: 1500);
        $expected = "retry: 1500\nid: 7\nevent: update\ndata: body\n\n";
        self::assertSame($expected, $event->format());
    }

    public function test_multiline_data_produces_multiple_data_lines(): void
    {
        $event = new SseEvent(data: "line1\nline2\nline3");
        $expected = "data: line1\ndata: line2\ndata: line3\n\n";
        self::assertSame($expected, $event->format());
    }

    public function test_multiline_data_with_event(): void
    {
        $event = new SseEvent(data: "a\nb", event: 'msg', id: '1');
        $expected = "id: 1\nevent: msg\ndata: a\ndata: b\n\n";
        self::assertSame($expected, $event->format());
    }

    public function test_empty_data(): void
    {
        $event = new SseEvent(data: '');
        self::assertSame("data: \n\n", $event->format());
    }

    // ------------------------------------------------------------------
    // Constructor properties are public readonly
    // ------------------------------------------------------------------

    public function test_properties_accessible(): void
    {
        $event = new SseEvent(data: 'x', event: 'e', id: '9', retry: 500);
        self::assertSame('x', $event->data);
        self::assertSame('e', $event->event);
        self::assertSame('9', $event->id);
        self::assertSame(500, $event->retry);
    }

    public function test_optional_properties_default_to_null(): void
    {
        $event = new SseEvent(data: 'x');
        self::assertNull($event->event);
        self::assertNull($event->id);
        self::assertNull($event->retry);
    }

    public function test_format_ends_with_double_newline(): void
    {
        $event = new SseEvent(data: 'test', event: 'e', id: '1', retry: 100);
        self::assertStringEndsWith("\n\n", $event->format());
    }
}
