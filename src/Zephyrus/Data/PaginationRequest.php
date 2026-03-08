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
        $perPage = self::resolvePerPage($data, 25);

        return new self(
            page: self::resolvePage($data, $perPage),
            perPage: $perPage,
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

        $page = max(self::resolvePage($data, $perPage), 1);

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
