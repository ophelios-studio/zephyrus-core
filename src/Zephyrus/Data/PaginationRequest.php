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
}
