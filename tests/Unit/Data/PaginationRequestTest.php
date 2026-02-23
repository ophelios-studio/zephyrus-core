<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Data;

use PHPUnit\Framework\TestCase;
use Zephyrus\Data\DatabaseException;
use Zephyrus\Data\PaginationRequest;

final class PaginationRequestTest extends TestCase
{
    public function testConstructComputesOffsetAndLimit(): void
    {
        $request = new PaginationRequest(page: 3, perPage: 25);

        self::assertSame(50, $request->offset());
        self::assertSame(25, $request->limit());
    }

    public function testFromArraySupportsSnakeAndCamelPerPageKeys(): void
    {
        $snake = PaginationRequest::fromArray(['page' => 2, 'per_page' => 10]);
        $camel = PaginationRequest::fromArray(['page' => 2, 'perPage' => 10]);

        self::assertSame(2, $snake->page);
        self::assertSame(10, $snake->perPage);
        self::assertSame(2, $camel->page);
        self::assertSame(10, $camel->perPage);
    }

    public function testFromArrayProvidesDefaultValues(): void
    {
        $request = PaginationRequest::fromArray([]);

        self::assertSame(1, $request->page);
        self::assertSame(25, $request->perPage);
    }

    public function testConstructThrowsWhenPageIsInvalid(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Page must be >= 1');

        new PaginationRequest(page: 0, perPage: 10);
    }

    public function testConstructThrowsWhenPerPageIsInvalid(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Per-page must be >= 1');

        new PaginationRequest(page: 1, perPage: 0);
    }
}
