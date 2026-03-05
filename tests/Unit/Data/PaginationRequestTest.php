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

    public function testFromArrayWithBoundsClampsPerPageToMax(): void
    {
        $request = PaginationRequest::fromArrayWithBounds([
            'page' => 2,
            'per_page' => 999,
        ], defaultPerPage: 25, maxPerPage: 100);

        self::assertSame(2, $request->page);
        self::assertSame(100, $request->perPage);
    }

    public function testFromArrayWithBoundsClampsPerPageToMinimumOne(): void
    {
        $request = PaginationRequest::fromArrayWithBounds([
            'page' => 1,
            'per_page' => 0,
        ], defaultPerPage: 25, maxPerPage: 100);

        self::assertSame(1, $request->perPage);
    }

    public function testFromArrayWithBoundsClampsPageToMinimumOne(): void
    {
        $request = PaginationRequest::fromArrayWithBounds([
            'page' => 0,
            'per_page' => 20,
        ], defaultPerPage: 25, maxPerPage: 100);

        self::assertSame(1, $request->page);
        self::assertSame(20, $request->perPage);
    }

    public function testWithPageAndWithPerPageReturnNewInstances(): void
    {
        $request = new PaginationRequest(page: 2, perPage: 25);

        $changedPage = $request->withPage(3);
        $changedPerPage = $request->withPerPage(50);

        self::assertSame(2, $request->page);
        self::assertSame(25, $request->perPage);

        self::assertSame(3, $changedPage->page);
        self::assertSame(25, $changedPage->perPage);

        self::assertSame(2, $changedPerPage->page);
        self::assertSame(50, $changedPerPage->perPage);
    }

    public function testNextAndPreviousHelpers(): void
    {
        $request = new PaginationRequest(page: 3, perPage: 10);

        self::assertSame(4, $request->next()->page);
        self::assertSame(2, $request->previous()->page);
        self::assertSame(1, (new PaginationRequest(1, 10))->previous()->page);
    }

    public function testToArrayAndJsonSerializeShareSameEnvelope(): void
    {
        $request = new PaginationRequest(page: 2, perPage: 30);

        self::assertSame(['page' => 2, 'per_page' => 30], $request->toArray());
        self::assertSame($request->toArray(), $request->jsonSerialize());
    }

    public function testFromQueryUsesBoundsAndDefaults(): void
    {
        $request = PaginationRequest::fromQuery(['page' => 2, 'per_page' => 999], 25, 100);

        self::assertSame(2, $request->page);
        self::assertSame(100, $request->perPage);
    }

    public function testFromQueryClampsInvalidPageToMinimumOne(): void
    {
        $request = PaginationRequest::fromQuery(['page' => -4, 'per_page' => 15], 25, 100);

        self::assertSame(1, $request->page);
        self::assertSame(15, $request->perPage);
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

    public function testFromArrayWithBoundsThrowsWhenDefaultPerPageInvalid(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Default per-page must be >= 1');

        PaginationRequest::fromArrayWithBounds([], defaultPerPage: 0, maxPerPage: 10);
    }

    public function testFromArrayWithBoundsThrowsWhenMaxPerPageInvalid(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Max per-page must be >= 1');

        PaginationRequest::fromArrayWithBounds([], defaultPerPage: 10, maxPerPage: 0);
    }
}
