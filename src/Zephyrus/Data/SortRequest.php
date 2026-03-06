<?php

declare(strict_types=1);

namespace Zephyrus\Data;

final class SortRequest implements \JsonSerializable
{
    public function __construct(
        public readonly string $column,
        public readonly string $direction = 'ASC',
    ) {
        if ($column === '' || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_\.]*$/', $column)) {
            throw DatabaseException::queryFailed('sorting', 'Invalid sort column');
        }

        $normalized = strtoupper($direction);
        if (!in_array($normalized, ['ASC', 'DESC'], true)) {
            throw DatabaseException::queryFailed('sorting', 'Sort direction must be ASC or DESC');
        }
    }

    public static function fromArray(array $data, string $defaultColumn, string $defaultDirection = 'ASC'): self
    {
        return new self(
            column: self::resolveColumn($data, $defaultColumn),
            direction: self::resolveDirection($data, $defaultDirection),
        );
    }

    /**
     * @param array<string, mixed> $query
     * @param array<int, string> $allowedColumns
     */
    public static function fromQuery(array $query, array $allowedColumns, string $defaultColumn, string $defaultDirection = 'ASC'): self
    {
        $candidate = self::resolveColumn($query, $defaultColumn);
        if (!in_array($candidate, $allowedColumns, true)) {
            $candidate = $defaultColumn;
        }

        return new self(
            column: $candidate,
            direction: self::resolveDirection($query, $defaultDirection),
        );
    }

    public function toSql(): string
    {
        return sprintf(' ORDER BY %s %s', $this->column, strtoupper($this->direction));
    }

    /** @return array{sort_by: string, sort_dir: string} */
    public function toArray(): array
    {
        return [
            'sort_by' => $this->column,
            'sort_dir' => strtoupper($this->direction),
        ];
    }

    /** @return array{sort_by: string, sort_dir: string} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function resolveColumn(array $data, string $defaultColumn): string
    {
        return (string) ($data['sort_by'] ?? $data['sortBy'] ?? $data['order_by'] ?? $data['orderBy'] ?? $defaultColumn);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function resolveDirection(array $data, string $defaultDirection): string
    {
        return (string) ($data['sort_dir'] ?? $data['sortDir'] ?? $data['direction'] ?? $data['order'] ?? $defaultDirection);
    }
}
