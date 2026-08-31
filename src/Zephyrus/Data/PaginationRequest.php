<?php

declare(strict_types=1);

namespace Zephyrus\Data;

/**
 * Immutable page/per-page pair for offset pagination.
 *
 * TWO FACTORIES, TWO CONTRACTS. They read the SAME request parameter names, so
 * picking the wrong one is silent:
 *
 *   - fromQuery() is the UNTRUSTED-INPUT sibling. It clamps per-page to
 *     $maxPerPage (100 by default) and clamps the page to a representable value.
 *     Hand it $_GET.
 *   - fromArray() takes PRE-VALIDATED input. Without the optional $maxPerPage
 *     ceiling it applies no bound at all, so ['per_page' => 100000000] really does
 *     become LIMIT 100000000. Pass $maxPerPage whenever the array came from a
 *     request.
 */
final class PaginationRequest implements \JsonSerializable
{
    public function __construct(
        public readonly int $page,
        public readonly int $perPage,
    ) {
        if ($page < 1) {
            throw DatabaseException::queryFailed('pagination', 'Page must be >= 1');
        }

        if ($perPage < 1) {
            throw DatabaseException::queryFailed('pagination', 'Per-page must be >= 1');
        }
    }

    /**
     * @throws DatabaseException when the page is too large for the per-page size.
     */
    public function offset(): int
    {
        // ($page - 1) * $perPage silently promotes int to float once the product
        // passes PHP_INT_MAX, and the `: int` return type then raises a raw
        // TypeError, NOT the DatabaseException this class contracts on, so every
        // caller catching DatabaseException 500s instead. Rejecting the overflow
        // explicitly makes the far end of the range symmetric with the
        // constructor's `page < 1` rejection at the near end.
        //
        // The check is the division form of the multiplication: perPage is >= 1
        // (constructor) and page is >= 1, so neither side can overflow on its own.
        if ($this->page - 1 > intdiv(PHP_INT_MAX, $this->perPage)) {
            throw DatabaseException::queryFailed(
                'pagination',
                'Page is too large for the requested per-page size',
            );
        }

        return ($this->page - 1) * $this->perPage;
    }

    public function limit(): int
    {
        return $this->perPage;
    }

    /**
     * Build from an array of ALREADY VALIDATED values.
     *
     * With $maxPerPage left null this applies NO ceiling: the per-page value is
     * taken as given and reaches LIMIT verbatim. That is intentional (an internal
     * caller asking for 5000 rows must get 5000 rows), and it is also the trap:
     * this method reads the same per_page / perPage / page_size / pageSize / limit
     * keys as fromQuery(), so handing it a raw request array removes the bound the
     * caller most likely assumed was there.
     *
     * Pass $maxPerPage, or use fromQuery(), for anything derived from a request.
     *
     * @param array<string, mixed> $data
     * @param int|null $maxPerPage optional ceiling; per-page is then clamped into
     *                             [1, $maxPerPage] exactly as fromQuery() does.
     * @throws DatabaseException when $maxPerPage is supplied and is below 1.
     */
    public static function fromArray(array $data, ?int $maxPerPage = null): self
    {
        if ($maxPerPage !== null && $maxPerPage < 1) {
            throw DatabaseException::queryFailed('pagination', 'Max per-page must be >= 1');
        }

        $perPage = self::resolvePerPage($data, 25);
        if ($maxPerPage !== null) {
            $perPage = min(max($perPage, 1), $maxPerPage);
        }

        return new self(
            page: self::resolvePage($data, $perPage),
            perPage: $perPage,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArrayWithBounds(array $data, int $defaultPerPage = 25, int $maxPerPage = 100): self
    {
        if ($defaultPerPage < 1) {
            throw DatabaseException::queryFailed('pagination', 'Default per-page must be >= 1');
        }

        if ($maxPerPage < 1) {
            throw DatabaseException::queryFailed('pagination', 'Max per-page must be >= 1');
        }

        $requestedPerPage = self::resolvePerPage($data, $defaultPerPage);
        $perPage = min(max($requestedPerPage, 1), $maxPerPage);

        // per-page is clamped in BOTH directions above; the page used to be only
        // FLOORED, with nothing capping it, so ?page=99999999999999999 travelled
        // through this documented-safe path and overflowed inside offset().
        //
        // The ceiling is DERIVED (the largest page whose offset is still an int)
        // rather than an invented round number, so this clamp can only ever rewrite
        // a page that would otherwise have thrown. No page an application could
        // actually paginate changes value.
        $page = min(max(self::resolvePage($data, $perPage), 1), intdiv(PHP_INT_MAX, $perPage));

        return new self(
            page: $page,
            perPage: $perPage,
        );
    }

    public function withPage(int $page): self
    {
        return new self(page: $page, perPage: $this->perPage);
    }

    public function withPerPage(int $perPage): self
    {
        return new self(page: $this->page, perPage: $perPage);
    }

    public function next(): self
    {
        return new self(page: $this->page + 1, perPage: $this->perPage);
    }

    public function previous(): self
    {
        return new self(page: max(1, $this->page - 1), perPage: $this->perPage);
    }

    /**
     * @return array{page: int, per_page: int}
     */
    public function toArray(): array
    {
        return [
            'page' => $this->page,
            'per_page' => $this->perPage,
        ];
    }

    /**
     * Build from an UNTRUSTED request array. Per-page is clamped into
     * [1, $maxPerPage] and the page is clamped to a representable value, so no
     * combination of query parameters can produce an unbounded LIMIT or an
     * overflowing OFFSET. This is the factory to reach for on $_GET.
     *
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query, int $defaultPerPage = 25, int $maxPerPage = 100): self
    {
        return self::fromArrayWithBounds($query, $defaultPerPage, $maxPerPage);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function resolvePerPage(array $data, int $defaultPerPage): int
    {
        return (int) ($data['per_page'] ?? $data['perPage'] ?? $data['page_size'] ?? $data['pageSize'] ?? $data['limit'] ?? $defaultPerPage);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function resolvePage(array $data, int $perPage): int
    {
        if (array_key_exists('page', $data)) {
            return (int) $data['page'];
        }

        if (array_key_exists('offset', $data)) {
            $offset = max(0, (int) $data['offset']);
            $safePerPage = max(1, $perPage);

            return intdiv($offset, $safePerPage) + 1;
        }

        return 1;
    }

    /**
     * @return array{page: int, per_page: int}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
