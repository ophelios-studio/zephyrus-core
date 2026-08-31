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

    public function testFromArraySupportsPerPageAliases(): void
    {
        $snake = PaginationRequest::fromArray(['page' => 2, 'per_page' => 10]);
        $camel = PaginationRequest::fromArray(['page' => 2, 'perPage' => 11]);
        $snakeSize = PaginationRequest::fromArray(['page' => 2, 'page_size' => 12]);
        $camelSize = PaginationRequest::fromArray(['page' => 2, 'pageSize' => 13]);
        $limit = PaginationRequest::fromArray(['page' => 2, 'limit' => 14]);

        self::assertSame(2, $snake->page);
        self::assertSame(10, $snake->perPage);
        self::assertSame(2, $camel->page);
        self::assertSame(11, $camel->perPage);
        self::assertSame(2, $snakeSize->page);
        self::assertSame(12, $snakeSize->perPage);
        self::assertSame(2, $camelSize->page);
        self::assertSame(13, $camelSize->perPage);
        self::assertSame(2, $limit->page);
        self::assertSame(14, $limit->perPage);
    }

    public function testFromArrayProvidesDefaultValues(): void
    {
        $request = PaginationRequest::fromArray([]);

        self::assertSame(1, $request->page);
        self::assertSame(25, $request->perPage);
    }

    public function testFromArrayDerivesPageFromOffsetWhenPageMissing(): void
    {
        $request = PaginationRequest::fromArray(['offset' => 40, 'per_page' => 20]);

        self::assertSame(3, $request->page);
        self::assertSame(20, $request->perPage);
    }

    public function testFromArrayPrefersExplicitPageOverOffset(): void
    {
        $request = PaginationRequest::fromArray(['page' => 4, 'offset' => 0, 'per_page' => 20]);

        self::assertSame(4, $request->page);
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

    public function testFromQueryUsesBoundsAndSupportsPageSizeAlias(): void
    {
        $clamped = PaginationRequest::fromQuery(['page' => 2, 'per_page' => 999], 25, 100);
        $alias = PaginationRequest::fromQuery(['page' => 3, 'page_size' => 40], 25, 100);
        $limit = PaginationRequest::fromQuery(['page' => 4, 'limit' => 35], 25, 100);

        self::assertSame(2, $clamped->page);
        self::assertSame(100, $clamped->perPage);
        self::assertSame(3, $alias->page);
        self::assertSame(40, $alias->perPage);
        self::assertSame(4, $limit->page);
        self::assertSame(35, $limit->perPage);
    }

    public function testFromQueryClampsInvalidPageToMinimumOne(): void
    {
        $request = PaginationRequest::fromQuery(['page' => -4, 'per_page' => 15], 25, 100);

        self::assertSame(1, $request->page);
        self::assertSame(15, $request->perPage);
    }

    public function testFromQueryDerivesPageFromOffsetWhenPageMissing(): void
    {
        $request = PaginationRequest::fromQuery(['offset' => 75, 'limit' => 25], 25, 100);

        self::assertSame(4, $request->page);
        self::assertSame(25, $request->perPage);
    }

    public function testFromQueryPrefersPerPageOverLimitAlias(): void
    {
        $request = PaginationRequest::fromQuery(['page' => 2, 'per_page' => 20, 'limit' => 5], 25, 100);

        self::assertSame(2, $request->page);
        self::assertSame(20, $request->perPage);
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

    // ── overflow guard (finding 1) ───────────────────────────────────────────

    /**
     * REGRESSION. offset() computed ($page - 1) * $perPage with no guard, so a
     * 17-digit page overflowed int to float and the `: int` return type raised a
     * raw TypeError instead of the DatabaseException this class contracts on.
     * Every caller catching DatabaseException therefore 500ed instead.
     */
    public function testOffsetRejectsAPageThatWouldOverflowInsteadOfRaisingATypeError(): void
    {
        $request = new PaginationRequest(page: 99999999999999999, perPage: 100);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Page is too large');

        $request->offset();
    }

    public function testOffsetStillComputesAtTheLargestRepresentablePage(): void
    {
        $perPage = 100;
        $request = new PaginationRequest(page: intdiv(PHP_INT_MAX, $perPage), perPage: $perPage);

        self::assertSame((intdiv(PHP_INT_MAX, $perPage) - 1) * $perPage, $request->offset());
    }

    /**
     * The documented-safe path must not even reach the throw above:
     * ?page=99999999999999999&per_page=100 is a plain HTTP request.
     */
    public function testFromQueryClampsAPageThatWouldOverflowTheOffset(): void
    {
        $request = PaginationRequest::fromQuery(
            ['page' => 99999999999999999, 'per_page' => 100],
            25,
            100,
        );

        self::assertSame(100, $request->perPage);
        self::assertSame(intdiv(PHP_INT_MAX, 100), $request->page);
        self::assertIsInt($request->offset());
    }

    public function testFromQueryLeavesAnOrdinaryPageAlone(): void
    {
        $request = PaginationRequest::fromQuery(['page' => 5000, 'per_page' => 50], 25, 100);

        self::assertSame(5000, $request->page);
        self::assertSame(249950, $request->offset());
    }

    // ── fromArray is the pre-validated sibling (finding 2) ───────────────────

    /**
     * TRAP-PINNING TEST. fromArray() reads the SAME HTTP parameter names as
     * fromQuery() but applies NO ceiling of its own, so handing it $_GET
     * unfiltered yields `LIMIT 100000000`. That is by design: fromArray() takes
     * PRE-VALIDATED input. The optional $maxPerPage argument is what makes it
     * safe on untrusted input, and fromQuery() applies one by default.
     *
     * The first assertion pins the trap on purpose. Do not "fix" it by making the
     * ceiling mandatory without checking every consumer repository first.
     */
    public function testFromArrayDoesNotClampPerPageUnlessGivenACeiling(): void
    {
        $unbounded = PaginationRequest::fromArray(['page' => 1, 'per_page' => 100000000]);
        self::assertSame(100000000, $unbounded->perPage, 'fromArray takes PRE-VALIDATED input only');

        $bounded = PaginationRequest::fromArray(['page' => 1, 'per_page' => 100000000], maxPerPage: 100);
        self::assertSame(100, $bounded->perPage);

        $fromQuery = PaginationRequest::fromQuery(['page' => 1, 'per_page' => 100000000]);
        self::assertSame(100, $fromQuery->perPage, 'fromQuery is the untrusted-input sibling and clamps by default');
    }

    public function testFromArrayCeilingRejectsANonPositiveValue(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Max per-page must be >= 1');

        PaginationRequest::fromArray(['per_page' => 10], maxPerPage: 0);
    }
}
