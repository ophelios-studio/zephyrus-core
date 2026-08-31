<?php

declare(strict_types=1);

namespace Zephyrus\Data;

/**
 * Immutable pagination envelope for list-style queries.
 *
 * SHARP EDGE: #[JsonIgnore] GIVES NO PROTECTION THROUGH THIS ENVELOPE.
 *
 * $items holds raw stdClass rows exactly as the driver returned them, not
 * Entity instances, so json_encode() on this object emits EVERY selected column.
 * The #[JsonIgnore] attribute is honoured by Entity::jsonSerialize() and by
 * nothing else, which means this is a live disclosure path:
 *
 *   // password_hash, internal notes, every column: all of it ships.
 *   return json_encode($db->paginateResult('SELECT * FROM users', ...));
 *
 * Two ways to stay safe, and there is no third:
 *   1. Name the columns in the SELECT. The envelope can only leak what the query
 *      returned, so a listing query should never be SELECT *.
 *   2. Map the rows through your entities first, with mapItems() or by rebuilding
 *      the envelope from Entity::buildArray(), and serialize those instead.
 *
 * @template T of \stdClass
 */
final class PaginatedResult implements \JsonSerializable
{
    /**
     * @param T[] $items
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
     * @return self<\stdClass>
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
     * @return T|null
     */
    public function firstItem(): ?\stdClass
    {
        return $this->items[0] ?? null;
    }

    /**
     * @return T|null
     */
    public function lastItem(): ?\stdClass
    {
        if ($this->items === []) {
            return null;
        }

        return $this->items[count($this->items) - 1];
    }

    /**
     * @param callable(T): T $mapper
     * @return self<T>
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
