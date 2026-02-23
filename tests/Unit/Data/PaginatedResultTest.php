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
                ['id' => 1, 'name' => 'Alice'],
                ['id' => 2, 'name' => 'Bob'],
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
            items: [['id' => 10, 'name' => 'X']],
            total: 1,
            page: 1,
            perPage: 10,
            totalPages: 1,
            hasPrevious: false,
            hasNext: false,
        );

        self::assertSame([
            'items' => [['id' => 10, 'name' => 'X']],
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
    }
}
