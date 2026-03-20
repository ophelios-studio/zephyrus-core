<?php

declare(strict_types=1);

namespace Zephyrus\Data;

/**
 * Immutable pagination envelope for list-style queries.
 *
 * @template T of \stdClass
 */
final class PaginatedResult implements \JsonSerializable
{
    /**
     * @param \stdClass[] $items
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
     * @param array{items: \stdClass[], total: int, page: int, per_page: int, total_pages: int, has_previous: bool, has_next: bool} $data
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
     * @return array{items: \stdClass[], total: int, page: int, per_page: int, total_pages: int, has_previous: bool, has_next: bool}
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

    /**
     * @return null|\stdClass
     */
    public function firstItem(): ?\stdClass
    {
        return $this->items[0] ?? null;
    }

    /**
     * @return null|\stdClass
     */
    public function lastItem(): ?\stdClass
    {
        if ($this->items === []) {
            return null;
        }

        return $this->items[count($this->items) - 1];
    }

    /**
     * @param callable(\stdClass): \stdClass $mapper
     */
    public function mapItems(callable $mapper): self
    {
        return new self(
            items: array_map($mapper, $this->items),
            total: $this->total,
            page: $this->page,
            perPage: $this->perPage,
            totalPages: $this->totalPages,
            hasPrevious: $this->hasPrevious,
            hasNext: $this->hasNext,
        );
    }

    public function isFirstPage(): bool
    {
        return $this->page <= 1;
    }

    public function isLastPage(): bool
    {
        return $this->page >= $this->totalPages;
    }

    public function nextPageNumber(): ?int
    {
        return $this->hasNext ? $this->page + 1 : null;
    }

    public function previousPageNumber(): ?int
    {
        return $this->hasPrevious ? max(1, $this->page - 1) : null;
    }

    public function toPaginationRequest(): PaginationRequest
    {
        return new PaginationRequest(page: $this->page, perPage: $this->perPage);
    }

    /**
     * @return array{items: \stdClass[], total: int, page: int, per_page: int, total_pages: int, has_previous: bool, has_next: bool}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
