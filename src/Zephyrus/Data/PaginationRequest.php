<?php

declare(strict_types=1);

namespace Zephyrus\Data;

/**
 * Immutable page and per-page pair for offset pagination.
 *
 * fromQuery() takes untrusted input such as $_GET and clamps per-page to $maxPerPage.
 * fromArray() takes pre-validated input and applies no ceiling unless $maxPerPage is passed,
 * so never hand it a raw request array without one. Both read the same request keys.
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
        // Checked before multiplying: an overflowing product would become a float and break the int return type.
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
     * Build from pre-validated values. Without $maxPerPage no ceiling applies and the per-page value reaches LIMIT as given.
     *
     * @param array<string, mixed> $data
     * @param int|null $maxPerPage optional ceiling; per-page is then clamped into [1, $maxPerPage].
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

        // The page ceiling is the largest page whose offset still fits in an int.
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
     * Build from an untrusted request array (e.g. $_GET). Per-page is clamped into [1, $maxPerPage]
     * and the page into a representable value, so no query can yield an unbounded LIMIT or an overflowing OFFSET.
     *
     * Reads page, or offset, for the page, and per_page, perPage, page_size, pageSize, limit for the page size.
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
