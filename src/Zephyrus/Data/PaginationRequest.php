<?php

declare(strict_types=1);

namespace Zephyrus\Data;

final class PaginationRequest
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
            perPage: (int) ($data['per_page'] ?? $data['perPage'] ?? 25),
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

        $requestedPerPage = (int) ($data['per_page'] ?? $data['perPage'] ?? $defaultPerPage);
        $perPage = min(max($requestedPerPage, 1), $maxPerPage);

        return new self(
            page: (int) ($data['page'] ?? 1),
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
}
