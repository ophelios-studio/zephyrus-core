<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Data;

use PHPUnit\Framework\TestCase;
use Zephyrus\Data\PaginatedResult;

final class PaginatedResultTest extends TestCase
{
    public function testFromArrayHydratesTypedResult(): void
    {
        $result = PaginatedResult::fromArray([
            'items' => [
                (object) ['id' => 1, 'name' => 'Alice'],
                (object) ['id' => 2, 'name' => 'Bob'],
            ],
            'total' => 5,
            'page' => 2,
            'per_page' => 2,
            'total_pages' => 3,
            'has_previous' => true,
            'has_next' => true,
        ]);

        self::assertSame(5, $result->total);
        self::assertSame(2, $result->page);
        self::assertSame(2, $result->perPage);
        self::assertSame(3, $result->totalPages);
        self::assertTrue($result->hasPrevious);
        self::assertTrue($result->hasNext);
        self::assertFalse($result->isEmpty());
        self::assertSame(2, $result->itemCount());
    }

    public function testToArrayReturnsOriginalEnvelopeShape(): void
    {
        $result = new PaginatedResult(
            items: [(object) ['id' => 10, 'name' => 'X']],
            total: 1,
            page: 1,
            perPage: 10,
            totalPages: 1,
            hasPrevious: false,
            hasNext: false,
        );

        self::assertEquals([
            'items' => [(object) ['id' => 10, 'name' => 'X']],
            'total' => 1,
            'page' => 1,
            'per_page' => 10,
            'total_pages' => 1,
            'has_previous' => false,
            'has_next' => false,
        ], $result->toArray());
    }

    public function testIsEmptyAndItemCountForEmptyResult(): void
    {
        $result = new PaginatedResult([], 0, 1, 25, 1, false, false);

        self::assertTrue($result->isEmpty());
        self::assertSame(0, $result->itemCount());
        self::assertNull($result->firstItem());
        self::assertNull($result->lastItem());
    }

    public function testFirstAndLastItemForNonEmptyResult(): void
    {
        $result = new PaginatedResult(
            items: [
                (object) ['id' => 1, 'name' => 'Alice'],
                (object) ['id' => 2, 'name' => 'Bob'],
            ],
            total: 2,
            page: 1,
            perPage: 10,
            totalPages: 1,
            hasPrevious: false,
            hasNext: false,
        );

        self::assertSame('Alice', $result->firstItem()->name);
        self::assertSame('Bob', $result->lastItem()->name);
    }

    public function testMapItemsReturnsTransformedResult(): void
    {
        $result = new PaginatedResult(
            items: [
                (object) ['id' => 1, 'name' => 'alpha'],
                (object) ['id' => 2, 'name' => 'beta'],
            ],
            total: 2,
            page: 1,
            perPage: 10,
            totalPages: 1,
            hasPrevious: false,
            hasNext: false,
        );

        $mapped = $result->mapItems(static fn (\stdClass $row): \stdClass => (object) [
            ...(array) $row,
            'name' => strtoupper((string) $row->name),
        ]);

        self::assertSame('ALPHA', $mapped->items[0]->name);
        self::assertSame('BETA', $mapped->items[1]->name);
        self::assertSame(2, $mapped->total);
    }

    public function testJsonSerializeMatchesArrayEnvelope(): void
    {
        $result = new PaginatedResult(
            items: [(object) ['id' => 10, 'name' => 'X']],
            total: 1,
            page: 1,
            perPage: 10,
            totalPages: 1,
            hasPrevious: false,
            hasNext: false,
        );

        self::assertSame($result->toArray(), $result->jsonSerialize());
    }

    public function testPageNavigationHelpersExposeState(): void
    {
        $result = new PaginatedResult(
            items: [(object) ['id' => 10, 'name' => 'X']],
            total: 30,
            page: 2,
            perPage: 10,
            totalPages: 3,
            hasPrevious: true,
            hasNext: true,
        );

        self::assertFalse($result->isFirstPage());
        self::assertFalse($result->isLastPage());
        self::assertSame(1, $result->previousPageNumber());
        self::assertSame(3, $result->nextPageNumber());
    }

    public function testToPaginationRequestCreatesEquivalentRequest(): void
    {
        $result = new PaginatedResult(
            items: [(object) ['id' => 10, 'name' => 'X']],
            total: 1,
            page: 3,
            perPage: 15,
            totalPages: 3,
            hasPrevious: true,
            hasNext: false,
        );

        $request = $result->toPaginationRequest();

        self::assertSame(3, $request->page);
        self::assertSame(15, $request->perPage);
    }
}
