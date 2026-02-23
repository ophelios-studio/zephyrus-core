<?php

declare(strict_types=1);

namespace Zephyrus\Data;

/**
 * Immutable pagination envelope for list-style queries.
 *
 * @template T of array<string, mixed>
 */
final class PaginatedResult
{
    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $totalPages,
        public readonly bool $hasPrevious,
        public readonly bool $hasNext,
    ) {
    }

    /**
     * @param array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int, has_previous: bool, has_next: bool} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            items: $data['items'],
            total: $data['total'],
            page: $data['page'],
            perPage: $data['per_page'],
            totalPages: $data['total_pages'],
            hasPrevious: $data['has_previous'],
            hasNext: $data['has_next'],
        );
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int, has_previous: bool, has_next: bool}
     */
    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'total' => $this->total,
            'page' => $this->page,
            'per_page' => $this->perPage,
            'total_pages' => $this->totalPages,
            'has_previous' => $this->hasPrevious,
            'has_next' => $this->hasNext,
        ];
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function itemCount(): int
    {
        return count($this->items);
    }
}
