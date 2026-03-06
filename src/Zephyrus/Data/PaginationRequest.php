<?php

declare(strict_types=1);

namespace Zephyrus\Data;

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

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function limit(): int
    {
        return $this->perPage;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            page: (int) ($data['page'] ?? 1),
            perPage: self::resolvePerPage($data, 25),
        );
    }

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

        $requestedPage = (int) ($data['page'] ?? 1);
        $page = max($requestedPage, 1);

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
        return (int) ($data['per_page'] ?? $data['perPage'] ?? $data['page_size'] ?? $data['pageSize'] ?? $defaultPerPage);
    }

    /**
     * @return array{page: int, per_page: int}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
